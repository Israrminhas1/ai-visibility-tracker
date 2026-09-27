<?php
/**
 * Anthropic (Claude) provider.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Claude Messages API with the server-side web search tool.
 */
class Anthropic_Provider extends Base_Provider {

	const API = 'https://api.anthropic.com/v1';

	/**
	 * How many times to resume a paused (long-running) search turn.
	 */
	const MAX_CONTINUATIONS = 3;

	/**
	 * {@inheritDoc}
	 */
	protected function label() {
		return 'Anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$res = $this->http( 'GET', self::API . '/models/' . rawurlencode( $this->model ), $this->headers() );
		if ( is_wp_error( $res ) && 'aivt_http_404' === $res->get_error_code() ) {
			/* translators: %s: model ID */
			return new WP_Error( 'aivt_model', sprintf( __( 'The key works, but the model "%s" was not found. Choose another model.', 'ai-visibility-tracker' ), $this->model ) );
		}
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function ask( $prompt ) {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => (string) $prompt,
			),
		);
		$blocks   = array();
		$in       = 0;
		$out      = 0;

		for ( $i = 0; $i <= self::MAX_CONTINUATIONS; $i++ ) {
			$res = $this->messages(
				array(
					'max_tokens' => 16000,
					'messages'   => $messages,
					'tools'      => array( $this->search_tool() ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			$in    += isset( $res['usage']['input_tokens'] ) ? (int) $res['usage']['input_tokens'] : 0;
			$out   += isset( $res['usage']['output_tokens'] ) ? (int) $res['usage']['output_tokens'] : 0;
			$content = isset( $res['content'] ) && is_array( $res['content'] ) ? $res['content'] : array();
			$blocks  = array_merge( $blocks, $content );

			$stop = isset( $res['stop_reason'] ) ? $res['stop_reason'] : '';
			if ( 'refusal' === $stop ) {
				return new WP_Error( 'aivt_refusal', __( 'Claude declined to answer this prompt.', 'ai-visibility-tracker' ) );
			}
			if ( 'pause_turn' !== $stop ) {
				break;
			}
			// Send the paused turn back unchanged; the API resumes where it left off.
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $content,
			);
		}

		// The answer is the text after the last search; earlier text is "Let me search...".
		$last_tool = -1;
		foreach ( $blocks as $idx => $b ) {
			if ( isset( $b['type'] ) && in_array( $b['type'], array( 'server_tool_use', 'web_search_tool_result' ), true ) ) {
				$last_tool = $idx;
			}
		}

		$text      = '';
		$citations = array();
		foreach ( $blocks as $idx => $b ) {
			if ( ! isset( $b['type'] ) || 'text' !== $b['type'] ) {
				continue;
			}
			if ( $idx > $last_tool ) {
				$text .= $b['text'];
			}
			if ( empty( $b['citations'] ) || ! is_array( $b['citations'] ) ) {
				continue;
			}
			foreach ( $b['citations'] as $c ) {
				if ( isset( $c['url'] ) ) {
					$url = self::clean_url( $c['url'] );
					if ( '' !== $url && ! isset( $citations[ $url ] ) ) {
						$citations[ $url ] = array(
							'url'   => $url,
							'title' => isset( $c['title'] ) ? sanitize_text_field( $c['title'] ) : '',
						);
					}
				}
			}
		}

		if ( '' === trim( $text ) ) {
			foreach ( $blocks as $b ) {
				if ( isset( $b['type'] ) && 'text' === $b['type'] ) {
					$text .= $b['text'];
				}
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'aivt_empty', __( 'The model returned an empty answer.', 'ai-visibility-tracker' ) );
		}

		return array(
			'text'          => $text,
			'citations'     => array_values( $citations ),
			'input_tokens'  => $in,
			'output_tokens' => $out,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function json( $instructions, $input, $name, array $schema ) {
		$body = array(
			'max_tokens'    => 8000,
			'system'        => (string) $instructions,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => (string) $input,
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		$res = $this->messages( $body );
		if ( is_wp_error( $res ) && 'aivt_http_400' === $res->get_error_code() ) {
			unset( $body['output_config'] );
			$body['system'] = $this->json_instructions( $instructions, $schema );
			$res            = $this->messages( $body );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$text = '';
		foreach ( (array) $res['content'] as $b ) {
			if ( isset( $b['type'] ) && 'text' === $b['type'] ) {
				$text .= $b['text'];
			}
		}
		return $this->decode_json( $text );
	}

	/**
	 * Newer models get the web search version with dynamic filtering.
	 *
	 * @return array
	 */
	private function search_tool() {
		$dynamic = (bool) preg_match( '/^claude-(opus-(5|4-[678])|sonnet-(5|4-6)|fable|mythos)/', $this->model );
		$tool    = array(
			'type'     => $dynamic ? 'web_search_20260209' : 'web_search_20250305',
			'name'     => 'web_search',
			'max_uses' => 5,
		);
		if ( $this->country ) {
			$tool['user_location'] = array(
				'type'    => 'approximate',
				'country' => $this->country,
			);
		}
		return $tool;
	}

	/**
	 * POST /messages. Low effort keeps runs quick and cheap on models that support it.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	private function messages( array $body ) {
		$body['model'] = $this->model;
		if ( false === strpos( $this->model, 'haiku' ) ) {
			$body['output_config'] = array_merge(
				isset( $body['output_config'] ) ? $body['output_config'] : array(),
				array( 'effort' => 'low' )
			);
		}
		return $this->http( 'POST', self::API . '/messages', $this->headers(), $body );
	}

	/**
	 * Auth headers.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'x-api-key'         => $this->key,
			'anthropic-version' => '2023-06-01',
		);
	}
}
