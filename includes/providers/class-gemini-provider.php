<?php
/**
 * Google Gemini provider.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gemini generateContent with Grounding with Google Search.
 */
class Gemini_Provider extends Base_Provider {

	const API = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * {@inheritDoc}
	 */
	protected function label() {
		return 'Google Gemini';
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
		$res = $this->generate(
			array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => (string) $prompt ) ),
					),
				),
				'tools'    => array( array( 'google_search' => new \stdClass() ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$candidate = isset( $res['candidates'][0] ) ? $res['candidates'][0] : array();
		$text      = $this->candidate_text( $candidate );
		if ( '' === trim( $text ) ) {
			$reason = isset( $candidate['finishReason'] ) ? $candidate['finishReason'] : '';
			/* translators: %s: finish reason reported by Gemini */
			return new WP_Error( 'aivt_empty', $reason ? sprintf( __( 'Gemini returned no answer (%s).', 'ai-visibility-tracker' ), $reason ) : __( 'The model returned an empty answer.', 'ai-visibility-tracker' ) );
		}

		$citations = array();
		$chunks    = isset( $candidate['groundingMetadata']['groundingChunks'] ) ? (array) $candidate['groundingMetadata']['groundingChunks'] : array();
		foreach ( $chunks as $chunk ) {
			if ( empty( $chunk['web']['uri'] ) ) {
				continue;
			}
			$url   = self::clean_url( $chunk['web']['uri'] );
			$title = isset( $chunk['web']['title'] ) ? sanitize_text_field( $chunk['web']['title'] ) : '';
			// Gemini links through a Google redirect; the title holds the real site's domain.
			if ( false !== strpos( $url, 'vertexaisearch.cloud.google.com' ) && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $title ) ) {
				$url = 'https://' . strtolower( $title ) . '/';
			}
			if ( '' !== $url && ! isset( $citations[ $url ] ) ) {
				$citations[ $url ] = array(
					'url'   => $url,
					'title' => $title,
				);
			}
		}

		return array(
			'text'          => $text,
			'citations'     => array_values( $citations ),
			'input_tokens'  => isset( $res['usageMetadata']['promptTokenCount'] ) ? (int) $res['usageMetadata']['promptTokenCount'] : 0,
			'output_tokens' => isset( $res['usageMetadata']['candidatesTokenCount'] ) ? (int) $res['usageMetadata']['candidatesTokenCount'] : 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function json( $instructions, $input, $name, array $schema ) {
		$res = $this->generate(
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $this->json_instructions( $instructions, $schema ) ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => (string) $input ) ),
					),
				),
				'generationConfig'  => array( 'responseMimeType' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->decode_json( $this->candidate_text( isset( $res['candidates'][0] ) ? $res['candidates'][0] : array() ) );
	}

	/**
	 * POST models/{model}:generateContent.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	private function generate( array $body ) {
		return $this->http( 'POST', self::API . '/models/' . rawurlencode( $this->model ) . ':generateContent', $this->headers(), $body );
	}

	/**
	 * Concatenated text parts of a candidate (skipping thought parts).
	 *
	 * @param array $candidate Candidate.
	 * @return string
	 */
	private function candidate_text( array $candidate ) {
		$text = '';
		if ( empty( $candidate['content']['parts'] ) ) {
			return $text;
		}
		foreach ( (array) $candidate['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}
		return $text;
	}

	/**
	 * Auth headers.
	 *
	 * @return array
	 */
	private function headers() {
		return array( 'x-goog-api-key' => $this->key );
	}
}
