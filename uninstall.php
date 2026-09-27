<?php
/**
 * Removes all plugin data when the plugin is deleted, on every site of a network.
 *
 * @package AIVT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's tables, options, transients and events from the current site.
 */
function aivt_uninstall_site() {
	global $wpdb;

	foreach ( array( 'aivt_citations', 'aivt_mentions', 'aivt_results', 'aivt_runs', 'aivt_prompts', 'aivt_traffic' ) as $aivt_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$aivt_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	delete_option( 'aivt_settings' );
	delete_option( 'aivt_db_version' );
	delete_option( 'aivt_readiness_ver' );
	delete_option( 'aivt_start_lock' );

	// Transients: notices, readiness results, llms.txt and hit counters.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_aivt\_%' OR option_name LIKE '\_transient\_timeout\_aivt\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	wp_clear_scheduled_hook( 'aivt_process_queue' );
	wp_clear_scheduled_hook( 'aivt_scheduled_run' );
	wp_clear_scheduled_hook( 'aivt_prune_traffic' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $aivt_site_id ) {
		switch_to_blog( $aivt_site_id );
		aivt_uninstall_site();
		restore_current_blog();
	}
} else {
	aivt_uninstall_site();
}
