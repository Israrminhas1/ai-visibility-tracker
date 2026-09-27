<?php
/**
 * xAI (Grok) provider.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * xAI Responses API with the web_search tool.
 */
class Xai_Provider extends Responses_Provider {

	/**
	 * {@inheritDoc}
	 */
	protected function label() {
		return 'xAI';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_url() {
		return 'https://api.x.ai/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$res = $this->http( 'GET', $this->base_url() . '/models', $this->headers() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
			$ids = wp_list_pluck( $res['data'], 'id' );
			if ( $ids && ! in_array( $this->model, $ids, true ) ) {
				/* translators: %s: model ID */
				return new WP_Error( 'aivt_model', sprintf( __( 'The key works, but the model "%s" is not available to it. Choose another model.', 'ai-visibility-tracker' ), $this->model ) );
			}
		}
		return true;
	}

	/**
	 * Grok puts inline citation links like [[1]](https://...) in the text; the
	 * annotations already carry them, so keep only the number.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function clean_text( $text ) {
		return preg_replace( '/\[\[(\d+)\]\]\(https?:[^)\s]+\)/', '[$1]', $text );
	}

	/**
	 * Fall back to the top-level "citations" list when there are no annotations.
	 *
	 * @param array $res       Decoded response.
	 * @param array $citations Citations keyed by URL (by reference).
	 */
	protected function extra_citations( array $res, array &$citations ) {
		if ( empty( $res['citations'] ) || ! is_array( $res['citations'] ) ) {
			return;
		}
		foreach ( $res['citations'] as $c ) {
			if ( is_string( $c ) ) {
				$this->add_citation( $citations, $c, '' );
			} elseif ( isset( $c['url'] ) ) {
				$this->add_citation( $citations, $c['url'], isset( $c['title'] ) ? $c['title'] : '' );
			}
		}
	}

	/**
	 * Grok's citation annotations use the citation number as the title; drop it.
	 *
	 * @param array  $citations Citations keyed by URL (by reference).
	 * @param string $url       URL.
	 * @param string $title     Title.
	 */
	protected function add_citation( array &$citations, $url, $title ) {
		parent::add_citation( $citations, $url, preg_match( '/^\d+$/', (string) $title ) ? '' : $title );
	}
}
