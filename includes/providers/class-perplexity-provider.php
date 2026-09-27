<?php
/**
 * Perplexity provider.
 *
 * @package AIVT
 */

namespace AIVT\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Perplexity Agent API through its Responses-compatible endpoint.
 * The "model" setting may be a preset name (fast, low, medium, high...) or
 * a model ID such as openai/gpt-5.6-luna.
 */
class Perplexity_Provider extends Responses_Provider {

	const PRESETS = array( 'fast', 'low', 'medium', 'high', 'xhigh', 'wide-research' );

	/**
	 * {@inheritDoc}
	 */
	protected function label() {
		return 'Perplexity';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_url() {
		return 'https://api.perplexity.ai/v1';
	}

	/**
	 * Perplexity has no free "check key" endpoint, so send a tiny request
	 * without web search. It costs a fraction of a cent.
	 *
	 * @return true|\WP_Error
	 */
	public function test() {
		$res = $this->responses(
			array(
				'input'             => 'Reply with the single word OK.',
				'max_output_tokens' => 16,
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Presets go in "preset", anything else in "model".
	 *
	 * @param array $body Body.
	 * @return array
	 */
	protected function prepare_body( array $body ) {
		if ( in_array( $this->model, self::PRESETS, true ) ) {
			$body['preset'] = $this->model;
		} else {
			$body['model'] = $this->model;
		}
		return $body;
	}

	/**
	 * Fall back to search_results items when the answer has no annotations.
	 *
	 * @param array $res       Decoded response.
	 * @param array $citations Citations keyed by URL (by reference).
	 */
	protected function extra_citations( array $res, array &$citations ) {
		$lists = array();
		if ( ! empty( $res['search_results'] ) ) {
			$lists[] = $res['search_results'];
		}
		if ( ! empty( $res['output'] ) && is_array( $res['output'] ) ) {
			foreach ( $res['output'] as $item ) {
				if ( ! empty( $item['results'] ) && is_array( $item['results'] ) ) {
					$lists[] = $item['results'];
				}
			}
		}
		foreach ( $lists as $list ) {
			foreach ( (array) $list as $r ) {
				if ( isset( $r['url'] ) ) {
					$this->add_citation( $citations, $r['url'], isset( $r['title'] ) ? $r['title'] : '' );
				}
			}
		}
	}
}
