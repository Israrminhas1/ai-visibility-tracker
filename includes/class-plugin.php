<?php
/**
 * Main plugin bootstrap.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up all plugin components.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and boot) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function boot() {
		Installer::maybe_upgrade();

		( new Runner() )->register();
		( new Rest() )->register();
		( new Traffic() )->register();
		( new Llms_Txt() )->register();

		if ( is_admin() ) {
			( new Admin\Admin() )->register();
		}
	}
}
