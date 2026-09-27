<?php
/**
 * Registry of supported answer engines.
 *
 * @package AIVT
 */

namespace AIVT;

use AIVT\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Engine metadata and provider factory.
 */
class Engines {

	/**
	 * Engine definitions keyed by ID, in display order.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'openai'     => array(
				'label'    => 'ChatGPT',
				'vendor'   => 'OpenAI',
				'class'    => Providers\OpenAI_Provider::class,
				'model'    => 'gpt-6-luna',
				'models'   => array( 'gpt-6-luna', 'gpt-6-sol', 'gpt-5.5', 'gpt-4.1-mini' ),
				'keys_url' => 'https://platform.openai.com/api-keys',
				'terms'    => 'https://openai.com/policies/terms-of-use/',
				'privacy'  => 'https://openai.com/policies/privacy-policy/',
			),
			'anthropic'  => array(
				'label'    => 'Claude',
				'vendor'   => 'Anthropic',
				'class'    => Providers\Anthropic_Provider::class,
				'model'    => 'claude-opus-5',
				'models'   => array( 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5' ),
				'keys_url' => 'https://platform.claude.com/settings/keys',
				'terms'    => 'https://www.anthropic.com/legal/commercial-terms',
				'privacy'  => 'https://www.anthropic.com/legal/privacy',
			),
			'gemini'     => array(
				'label'    => 'Gemini',
				'vendor'   => 'Google',
				'class'    => Providers\Gemini_Provider::class,
				'model'    => 'gemini-3.8-flash',
				'models'   => array( 'gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-2.5-pro', 'gemini-2.5-flash' ),
				'keys_url' => 'https://aistudio.google.com/apikey',
				'terms'    => 'https://ai.google.dev/gemini-api/terms',
				'privacy'  => 'https://policies.google.com/privacy',
			),
			'perplexity' => array(
				'label'    => 'Perplexity',
				'vendor'   => 'Perplexity',
				'class'    => Providers\Perplexity_Provider::class,
				'model'    => 'low',
				'models'   => Providers\Perplexity_Provider::PRESETS,
				'keys_url' => 'https://console.perplexity.ai/',
				'terms'    => 'https://www.perplexity.ai/hub/legal/terms-of-service',
				'privacy'  => 'https://www.perplexity.ai/hub/legal/privacy-policy',
			),
			'xai'        => array(
				'label'    => 'Grok',
				'vendor'   => 'xAI',
				'class'    => Providers\Xai_Provider::class,
				'model'    => 'grok-4.7',
				'models'   => array( 'grok-4.7' ),
				'keys_url' => 'https://console.x.ai/',
				'terms'    => 'https://x.ai/legal/terms-of-service',
				'privacy'  => 'https://x.ai/legal/privacy-policy',
			),
		);
	}

	/**
	 * One engine's definition.
	 *
	 * @param string $id Engine ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Display label, e.g. "ChatGPT".
	 *
	 * @param string $id Engine ID.
	 * @return string
	 */
	public static function label( $id ) {
		$e = self::get( $id );
		return $e ? $e['label'] : $id;
	}

	/**
	 * IDs of engines that are enabled and have a key.
	 *
	 * @return string[]
	 */
	public static function active() {
		$ids = array();
		foreach ( array_keys( self::all() ) as $id ) {
			$cfg = Settings::engine( $id );
			if ( $cfg['enabled'] && '' !== Settings::engine_key( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Build a provider for an engine using saved settings.
	 *
	 * @param string      $id    Engine ID.
	 * @param string|null $key   Override key (for testing before saving).
	 * @param string|null $model Override model.
	 * @return Provider|null
	 */
	public static function provider( $id, $key = null, $model = null ) {
		$def = self::get( $id );
		if ( ! $def ) {
			return null;
		}
		$cfg   = Settings::engine( $id );
		$class = $def['class'];
		return new $class(
			null === $key ? Settings::engine_key( $id ) : $key,
			null === $model || '' === $model ? $cfg['model'] : $model,
			(string) Settings::get( 'country' )
		);
	}

	/**
	 * Provider used for helper tasks (prompt generation, brand extraction):
	 * the first active engine in the preferred order.
	 *
	 * @return Provider|null
	 */
	public static function utility_provider() {
		$active = self::active();
		foreach ( array( 'openai', 'anthropic', 'gemini', 'xai', 'perplexity' ) as $id ) {
			if ( in_array( $id, $active, true ) ) {
				return self::provider( $id );
			}
		}
		return null;
	}
}
