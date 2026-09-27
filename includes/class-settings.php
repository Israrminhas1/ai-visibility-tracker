<?php
/**
 * Plugin settings storage.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single settings option.
 */
class Settings {

	const OPTION = 'aivt_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'engines'       => array(),
			'brand_name'    => get_bloginfo( 'name' ),
			'brand_aliases' => array(),
			'brand_domains' => array( self::site_domain() ),
			'competitors'   => array(),
			'business'      => get_bloginfo( 'description' ),
			'location'      => '',
			'country'       => '',
			'language'      => self::site_language(),
			'schedule'      => 'off',
			'track_traffic' => true,
			'llms_enabled'  => false,
			'llms_intro'    => '',
		);
	}

	/**
	 * All settings merged with defaults. The API key stays encrypted here.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Merge and save settings.
	 *
	 * @param array $values Values to save.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * One engine's config: enabled flag, encrypted key, model.
	 *
	 * @param string $id Engine ID.
	 * @return array
	 */
	public static function engine( $id ) {
		$engines = (array) self::get( 'engines' );
		$def     = Engines::get( $id );
		return wp_parse_args(
			isset( $engines[ $id ] ) ? (array) $engines[ $id ] : array(),
			array(
				'enabled' => false,
				'key'     => '',
				'model'   => $def ? $def['model'] : '',
			)
		);
	}

	/**
	 * Save one engine's config.
	 *
	 * @param string      $id      Engine ID.
	 * @param bool        $enabled Whether the engine is tracked.
	 * @param string|null $key     New plain-text key, '' to remove, null to keep.
	 * @param string      $model   Model ID.
	 */
	public static function set_engine( $id, $enabled, $key, $model ) {
		$engines        = (array) self::get( 'engines' );
		$cfg            = self::engine( $id );
		$cfg['enabled'] = (bool) $enabled;
		$cfg['model']   = '' === $model ? $cfg['model'] : $model;
		if ( null !== $key ) {
			$cfg['key'] = Crypto::encrypt( $key );
		}
		$engines[ $id ] = $cfg;
		self::update( array( 'engines' => $engines ) );
	}

	/**
	 * Decrypted API key for an engine.
	 *
	 * @param string $id Engine ID.
	 * @return string
	 */
	public static function engine_key( $id ) {
		$cfg = self::engine( $id );
		return Crypto::decrypt( (string) $cfg['key'] );
	}

	/**
	 * Masked key for display, e.g. sk-••••••••a1b2.
	 *
	 * @param string $id Engine ID.
	 * @return string
	 */
	public static function masked_key( $id ) {
		$key = self::engine_key( $id );
		if ( strlen( $key ) < 12 ) {
			return '' === $key ? '' : str_repeat( '•', 8 );
		}
		return substr( $key, 0, 3 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Host of the site URL without www.
	 *
	 * @return string
	 */
	public static function site_domain() {
		return Analyzer::normalize_domain( home_url() );
	}

	/**
	 * English name of the site language, e.g. "English", "German".
	 *
	 * @return string
	 */
	private static function site_language() {
		$locale = get_locale();
		if ( function_exists( 'locale_get_display_language' ) ) {
			$name = locale_get_display_language( $locale, 'en' );
			if ( $name ) {
				return $name;
			}
		}
		return $locale;
	}
}
