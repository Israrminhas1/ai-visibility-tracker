<?php
/**
 * Shared HTTP and JSON helpers for providers.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class every provider extends.
 */
abstract class Base_Provider implements Provider {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * Model ID (or preset, for Perplexity).
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Two-letter country code for localized search, or ''.
	 *
	 * @var string
	 */
	protected $country;

	/**
	 * Constructor.
	 *
	 * @param string $key     API key.
	 * @param string $model   Model ID.
	 * @param string $country ISO country code or ''.
	 */
	public function __construct( $key, $model, $country = '' ) {
		$this->key     = (string) $key;
		$this->model   = (string) $model;
		$this->country = preg_match( '/^[A-Z]{2}$/', strtoupper( (string) $country ) ) ? strtoupper( $country ) : '';
	}

	/**
	 * Human name of the service, used in error messages.
	 *
	 * @return string
	 */
	abstract protected function label();

	/**
	 * Send an HTTP request and decode the JSON body.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $url     Full URL.
	 * @param array      $headers Headers.
	 * @param array|null $body    JSON body.
	 * @return array|WP_Error
	 */
	protected function http( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 150,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'aivt_http_' . $code, $this->error_message( $code, $data ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			/* translators: %s: service name */
			return new WP_Error( 'aivt_bad_response', sprintf( __( 'Unexpected response from %s.', 'ai-visibility-tracker' ), $this->label() ) );
		}
		return $data;
	}

	/**
	 * Readable error message from an API error response.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $data Decoded body.
	 * @return string
	 */
	protected function error_message( $code, $data ) {
		if ( 401 === $code || 403 === $code ) {
			/* translators: %s: service name */
			return sprintf( __( 'Invalid %s API key, or the key lacks permission.', 'ai-visibility-tracker' ), $this->label() );
		}
		$msg = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$msg = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = $data['error'];
			} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$msg = $data['message'];
			} elseif ( isset( $data[0]['error']['message'] ) ) {
				$msg = $data[0]['error']['message'];
			}
		}
		if ( 429 === $code && '' === $msg ) {
			/* translators: %s: service name */
			$msg = sprintf( __( '%s rate limit reached, or no credits left on the account.', 'ai-visibility-tracker' ), $this->label() );
		}
		if ( '' === $msg ) {
			/* translators: 1: service name, 2: HTTP status code */
			$msg = sprintf( __( '%1$s returned HTTP %2$d.', 'ai-visibility-tracker' ), $this->label(), $code );
		}
		return $msg;
	}

	/**
	 * Instructions that ask for JSON matching a schema, for APIs without
	 * native structured output (or as a fallback when that fails).
	 *
	 * @param string $instructions Original instructions.
	 * @param array  $schema       JSON schema.
	 * @return string
	 */
	protected function json_instructions( $instructions, array $schema ) {
		return $instructions . "\n\nRespond with ONLY a JSON object (no markdown, no code fences) that matches this JSON schema:\n" . wp_json_encode( $schema );
	}

	/**
	 * Decode a JSON object from model text, tolerating code fences or prose around it.
	 *
	 * @param string $text Model output.
	 * @return array|WP_Error
	 */
	protected function decode_json( $text ) {
		$text = trim( (string) $text );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aivt_bad_json', __( 'The model returned invalid JSON.', 'ai-visibility-tracker' ) );
		}
		return $data;
	}

	/**
	 * Remove tracking parameters AI engines append to cited URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function clean_url( $url ) {
		return esc_url_raw( remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign' ), (string) $url ) );
	}
}
