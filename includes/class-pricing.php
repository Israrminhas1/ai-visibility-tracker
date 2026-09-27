<?php
/**
 * Estimated cost of AI calls.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * USD cost of one answer: tokens at the model's price plus a fee per web
 * search. Prices change often; adjust them with the "aivt_pricing" filter.
 */
class Pricing {

	/**
	 * Price table.
	 *
	 * "models": model ID prefix => [input, output] USD per million tokens (longest prefix wins).
	 * "engines": fallback per engine when the model is not listed.
	 * "search": USD per web search, per engine.
	 *
	 * @return array
	 */
	public static function table() {
		$table = array(
			'models'  => array(
				'gpt-6-astra'      => array( 10.00, 50.00 ),
				'gpt-6-sol'        => array( 2.00, 10.00 ),
				'gpt-6-luna'       => array( 0.10, 0.50 ),
				'gpt-5.5'          => array( 5.00, 30.00 ),
				'gpt-4.1-mini'     => array( 0.40, 1.60 ),
				'claude-opus-5-5'  => array( 4.00, 20.00 ),
				'claude-opus-5'    => array( 5.00, 25.00 ),
				'claude-sonnet-5'  => array( 2.00, 10.00 ),
				'claude-haiku-4-5' => array( 1.00, 5.00 ),
				'gemini-3.8-flash' => array( 0.75, 3.75 ),
				'gemini-3.7-flash' => array( 0.75, 3.75 ),
				'gemini-3.5-flash-lite' => array( 0.30, 2.50 ),
				'gemini-3.5-flash' => array( 1.50, 9.00 ),
				'gemini-2.5-pro'   => array( 1.25, 10.00 ),
				'gemini-2.5-flash' => array( 0.30, 2.50 ),
				'grok-4.7'         => array( 2.00, 6.00 ),
				'grok-4.3'         => array( 1.25, 2.50 ),
			),
			'engines' => array(
				'openai'     => array( 1.25, 10.00 ),
				'anthropic'  => array( 3.00, 15.00 ),
				'gemini'     => array( 0.30, 2.50 ),
				'xai'        => array( 3.00, 15.00 ),
				'perplexity' => array( 1.00, 1.00 ),
			),
			'search'  => array(
				'openai'     => 0.01,
				'anthropic'  => 0.01,
				'gemini'     => 0.014,
				'xai'        => 0.01,
				'perplexity' => 0.0025,
			),
		);

		/**
		 * Filters the prices used for cost estimates.
		 *
		 * @param array $table See Pricing::table().
		 */
		return apply_filters( 'aivt_pricing', $table );
	}

	/**
	 * Cost of one call. A cost the provider reported itself wins.
	 *
	 * @param string     $engine   Engine key.
	 * @param string     $model    Model ID.
	 * @param int        $input    Input tokens.
	 * @param int        $output   Output tokens (including thinking).
	 * @param int        $searches Web searches.
	 * @param float|null $reported Cost reported by the provider, if any.
	 * @return float USD.
	 */
	public static function cost( $engine, $model, $input, $output, $searches = 0, $reported = null ) {
		if ( null !== $reported && $reported > 0 ) {
			return round( (float) $reported, 6 );
		}

		$table  = self::table();
		$prices = self::model_prices( $engine, (string) $model, $table );
		$fee    = isset( $table['search'][ $engine ] ) ? (float) $table['search'][ $engine ] : 0.0;

		return round( ( $input * $prices[0] + $output * $prices[1] ) / 1000000 + $searches * $fee, 6 );
	}

	/**
	 * Expected cost of one answer before any has been measured.
	 *
	 * @param string $engine Engine key.
	 * @param string $model  Model ID.
	 * @return float USD.
	 */
	public static function typical_answer( $engine, $model ) {
		// Measured with each engine's default model (September 2026). Search
		// results count as input tokens, so engines that search a lot cost more.
		$typical = apply_filters(
			'aivt_typical_answer_cost',
			array(
				'openai'     => 0.015,
				'anthropic'  => 0.09,
				'gemini'     => 0.07,
				'perplexity' => 0.01,
				'xai'        => 0.30,
			),
			$engine,
			$model
		);
		return isset( $typical[ $engine ] ) ? (float) $typical[ $engine ] : 0.05;
	}

	/**
	 * Format a USD amount.
	 *
	 * @param float $usd Amount.
	 * @return string
	 */
	public static function format( $usd ) {
		$usd = (float) $usd;
		return '$' . number_format_i18n( $usd, $usd > 0 && $usd < 0.1 ? 3 : 2 );
	}

	/**
	 * [input, output] prices for a model: the longest matching prefix, else the engine's fallback.
	 *
	 * @param string $engine Engine key.
	 * @param string $model  Model ID.
	 * @param array  $table  Price table.
	 * @return float[]
	 */
	private static function model_prices( $engine, $model, array $table ) {
		$best = '';
		foreach ( array_keys( $table['models'] ) as $prefix ) {
			if ( 0 === strpos( $model, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}
		if ( '' !== $best ) {
			return array_map( 'floatval', $table['models'][ $best ] );
		}
		return isset( $table['engines'][ $engine ] ) ? array_map( 'floatval', $table['engines'][ $engine ] ) : array( 0.0, 0.0 );
	}
}
