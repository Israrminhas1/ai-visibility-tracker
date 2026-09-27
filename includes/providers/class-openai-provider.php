<?php
/**
 * OpenAI (ChatGPT) provider.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI Responses API with the web_search tool.
 */
class OpenAI_Provider extends Responses_Provider {

	/**
	 * {@inheritDoc}
	 */
	protected function label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_url() {
		return 'https://api.openai.com/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$res = $this->http( 'GET', $this->base_url() . '/models/' . rawurlencode( $this->model ), $this->headers() );
		if ( is_wp_error( $res ) && 404 === $this->status( $res ) ) {
			/* translators: %s: model ID */
			return new WP_Error( 'aivt_model', sprintf( __( 'The key works, but it cannot use the model "%s". Choose another model.', 'ai-visibility-tracker' ), $this->model ) );
		}
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function search_tool() {
		$tool = array( 'type' => 'web_search' );
		if ( $this->country ) {
			$tool['user_location'] = array(
				'type'    => 'approximate',
				'country' => $this->country,
			);
		}
		return $tool;
	}

	/**
	 * Reasoning models get low effort to keep runs fast and cheap. If the
	 * model rejects the parameter, the request is retried without it.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	protected function responses( array $body ) {
		if ( ! preg_match( '/^(gpt-5|gpt-6|o\d)/i', $this->model ) ) {
			return parent::responses( $body );
		}
		$res = parent::responses( array_merge( $body, array( 'reasoning' => array( 'effort' => 'low' ) ) ) );
		if ( is_wp_error( $res ) && false !== stripos( $res->get_error_message(), 'reasoning' ) ) {
			$res = parent::responses( $body );
		}
		return $res;
	}
}
