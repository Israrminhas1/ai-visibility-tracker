<?php
/**
 * Removes all plugin data when the plugin is deleted.
 *
 * @package AIVT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'aivt_citations', 'aivt_mentions', 'aivt_results', 'aivt_runs', 'aivt_prompts', 'aivt_traffic' ) as $aivt_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$aivt_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'aivt_settings' );
delete_option( 'aivt_db_version' );
delete_option( 'aivt_readiness_ver' );
delete_transient( 'aivt_llms_txt' );
wp_clear_scheduled_hook( 'aivt_process_queue' );
wp_clear_scheduled_hook( 'aivt_scheduled_run' );
wp_clear_scheduled_hook( 'aivt_prune_traffic' );
