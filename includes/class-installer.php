<?php
/**
 * Database tables and lifecycle.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and handles activation/deactivation.
 */
class Installer {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'aivt_db_version', AIVT_DB_VERSION, false );
	}

	/**
	 * Deactivation hook: stop scheduled events. Data is kept until uninstall.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Runner::CRON_PROCESS );
		wp_clear_scheduled_hook( Runner::CRON_SCHEDULED );
		wp_clear_scheduled_hook( Traffic::CRON_PRUNE );
	}

	/**
	 * Run table migrations when the schema version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'aivt_db_version' ) !== AIVT_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Table names keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'prompts'   => $wpdb->prefix . 'aivt_prompts',
			'runs'      => $wpdb->prefix . 'aivt_runs',
			'results'   => $wpdb->prefix . 'aivt_results',
			'citations' => $wpdb->prefix . 'aivt_citations',
			'mentions'  => $wpdb->prefix . 'aivt_mentions',
			'traffic'   => $wpdb->prefix . 'aivt_traffic',
		);
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['prompts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			prompt text NOT NULL,
			intent varchar(40) NOT NULL DEFAULT '',
			topic varchar(100) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'manual',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['runs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'running',
			trigger_type varchar(20) NOT NULL DEFAULT 'manual',
			engines varchar(190) NOT NULL DEFAULT '',
			total int(11) NOT NULL DEFAULT 0,
			done int(11) NOT NULL DEFAULT 0,
			failed int(11) NOT NULL DEFAULT 0,
			mentioned int(11) NOT NULL DEFAULT 0,
			cited int(11) NOT NULL DEFAULT 0,
			cost decimal(12,6) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['results']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			prompt_id bigint(20) unsigned NOT NULL,
			engine varchar(20) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			topic varchar(100) NOT NULL DEFAULT '',
			prompt text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			response longtext NULL,
			brand_mentioned tinyint(1) NOT NULL DEFAULT 0,
			brand_cited tinyint(1) NOT NULL DEFAULT 0,
			brand_position smallint(5) unsigned DEFAULT NULL,
			input_tokens int(11) NOT NULL DEFAULT 0,
			output_tokens int(11) NOT NULL DEFAULT 0,
			searches smallint(5) unsigned NOT NULL DEFAULT 0,
			cost decimal(12,6) NOT NULL DEFAULT 0,
			error text NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY prompt_id (prompt_id),
			KEY engine (engine),
			KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['citations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			result_id bigint(20) unsigned NOT NULL,
			run_id bigint(20) unsigned NOT NULL,
			engine varchar(20) NOT NULL DEFAULT '',
			url text NOT NULL,
			domain varchar(190) NOT NULL DEFAULT '',
			title text NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY result_id (result_id),
			KEY run_domain (run_id,domain)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['mentions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			result_id bigint(20) unsigned NOT NULL,
			run_id bigint(20) unsigned NOT NULL,
			engine varchar(20) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			domain varchar(190) NOT NULL DEFAULT '',
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			is_brand tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY result_id (result_id),
			KEY run_name (run_id,name)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['traffic']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			kind varchar(10) NOT NULL DEFAULT 'referral',
			source varchar(40) NOT NULL DEFAULT '',
			path varchar(190) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hits int(11) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_kind_source_path (day,kind,source,path),
			KEY kind_day (kind,day)
			) $charset;"
		);
	}
}
