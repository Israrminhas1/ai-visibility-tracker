<?php
/**
 * Provider for "Responses API" compatible services (OpenAI, xAI, Perplexity).
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST {base}/responses with the web_search tool; citations come back as
 * url_citation annotations on output_text parts.
 */
abstract class Responses_Provider extends Base_Provider {

	/**
	 * API base URL without trailing slash, e.g. https://api.openai.com/v1.
	 *
	 * @return string
	 */
	abstract protected function base_url();

	/**
	 * {@inheritDoc}
	 */
	public function ask( $prompt ) {
		$res = $this->responses(
			array(
				'input' => (string) $prompt,
				'tools' => array( $this->search_tool() ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$text      = '';
		$citations = array();
		foreach ( $this->message_parts( $res ) as $part ) {
			$text .= isset( $part['text'] ) ? $part['text'] : '';
			if ( empty( $part['annotations'] ) || ! is_array( $part['annotations'] ) ) {
				continue;
			}
			foreach ( $part['annotations'] as $a ) {
				if ( isset( $a['type'], $a['url'] ) && 'url_citation' === $a['type'] ) {
					$this->add_citation( $citations, $a['url'], isset( $a['title'] ) ? $a['title'] : '' );
				}
			}
		}

		if ( ! $citations ) {
			$this->extra_citations( $res, $citations );
		}

		$text = $this->clean_text( $text );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'aivt_empty', __( 'The model returned an empty answer.', 'ai-visibility-tracker' ) );
		}

		return array(
			'text'          => $text,
			'citations'     => array_values( $citations ),
			'input_tokens'  => isset( $res['usage']['input_tokens'] ) ? (int) $res['usage']['input_tokens'] : 0,
			'output_tokens' => isset( $res['usage']['output_tokens'] ) ? (int) $res['usage']['output_tokens'] : 0,
			'searches'      => $this->search_count( $res ),
			'reported_cost' => isset( $res['usage']['cost']['total_cost'] ) ? (float) $res['usage']['cost']['total_cost'] : null,
		);
	}

	/**
	 * Web searches the answer used: search calls in the output, or the
	 * provider's own count (Perplexity reports "search_web" invocations).
	 *
	 * @param array $res Response.
	 * @return int
	 */
	protected function search_count( array $res ) {
		if ( isset( $res['usage']['tool_calls_details']['search_web']['invocation'] ) ) {
			return (int) $res['usage']['tool_calls_details']['search_web']['invocation'];
		}

		$count = 0;
		foreach ( isset( $res['output'] ) && is_array( $res['output'] ) ? $res['output'] : array() as $item ) {
			if ( isset( $item['type'] ) && false !== strpos( (string) $item['type'], 'search_call' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * {@inheritDoc}
	 */
	public function json( $instructions, $input, $name, array $schema ) {
		$res = $this->responses(
			array(
				'instructions' => (string) $instructions,
				'input'        => (string) $input,
				'text'         => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => $name,
						'schema' => $schema,
						'strict' => true,
					),
				),
			)
		);

		// Some compatible APIs don't support structured output: ask in the prompt instead.
		if ( is_wp_error( $res ) && 400 === (int) $this->status( $res ) ) {
			$res = $this->responses(
				array(
					'instructions' => $this->json_instructions( $instructions, $schema ),
					'input'        => (string) $input,
				)
			);
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$text = '';
		foreach ( $this->message_parts( $res ) as $part ) {
			$text .= isset( $part['text'] ) ? $part['text'] : '';
		}
		return $this->decode_json( $text );
	}

	/**
	 * The web search tool definition.
	 *
	 * @return array
	 */
	protected function search_tool() {
		return array( 'type' => 'web_search' );
	}

	/**
	 * Adjust the request body before sending (model, reasoning, preset...).
	 *
	 * @param array $body Body.
	 * @return array
	 */
	protected function prepare_body( array $body ) {
		$body['model'] = $this->model;
		return $body;
	}

	/**
	 * Hook for services that list citations outside annotations.
	 *
	 * @param array $res       Decoded response.
	 * @param array $citations Citations keyed by URL (by reference).
	 */
	protected function extra_citations( array $res, array &$citations ) {
	}

	/**
	 * Hook to tidy answer text (e.g. strip inline citation markers).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function clean_text( $text ) {
		return $text;
	}

	/**
	 * Auth headers.
	 *
	 * @return array
	 */
	protected function headers() {
		return array( 'Authorization' => 'Bearer ' . $this->key );
	}

	/**
	 * POST /responses.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	protected function responses( array $body ) {
		$res = $this->http( 'POST', $this->base_url() . '/responses', $this->headers(), $this->prepare_body( $body ) );
		if ( ! is_wp_error( $res ) && isset( $res['status'] ) && 'failed' === $res['status'] ) {
			$msg = isset( $res['error']['message'] ) ? $res['error']['message'] : __( 'The request failed.', 'ai-visibility-tracker' );
			return new WP_Error( 'aivt_failed', $msg );
		}
		return $res;
	}

	/**
	 * Output text parts from all assistant message items.
	 *
	 * @param array $res Decoded response.
	 * @return array
	 */
	protected function message_parts( array $res ) {
		$parts = array();
		if ( empty( $res['output'] ) || ! is_array( $res['output'] ) ) {
			return $parts;
		}
		foreach ( $res['output'] as $item ) {
			if ( ! isset( $item['type'] ) || 'message' !== $item['type'] || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $part ) {
				if ( isset( $part['type'] ) && 'output_text' === $part['type'] ) {
					$parts[] = $part;
				}
			}
		}
		return $parts;
	}

	/**
	 * Add a citation, de-duplicated by URL.
	 *
	 * @param array  $citations Citations keyed by URL (by reference).
	 * @param string $url       URL.
	 * @param string $title     Title.
	 */
	protected function add_citation( array &$citations, $url, $title ) {
		$url = self::clean_url( $url );
		if ( '' === $url || isset( $citations[ $url ] ) ) {
			return;
		}
		$citations[ $url ] = array(
			'url'   => $url,
			'title' => sanitize_text_field( (string) $title ),
		);
	}

	/**
	 * HTTP status stored on a WP_Error from http().
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	protected function status( WP_Error $error ) {
		$data = $error->get_error_data();
		return isset( $data['status'] ) ? (int) $data['status'] : 0;
	}
}
