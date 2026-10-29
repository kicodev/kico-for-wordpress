<?php
/**
 * Deleting the plugin in wp-admin removes everything it kept on the site, on
 * every site of a network: its tables ({prefix}kico_*: the audit log, nonces,
 * staged blobs), its options (keys, pairings, the Kico address, mail
 * failures, the audit anchor) and transients, and its scheduled job. Kico's
 * own copy (snapshots, its audit mirror) stays in Kico until the connection
 * is deleted there.
 *
 * @package Kico
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Removes the plugin's data of the current site. */
function kico_uninstall_site() {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix . 'kico_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) { // phpcs:ignore WordPress.DB
		// Only this site's own tables: on the main site of a network, not wp_2_kico_*.
		if ( preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'kico_[a-z0-9_]+$/', $table ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'kico_' ) . '%', $wpdb->esc_like( '_transient_kico_' ) . '%', $wpdb->esc_like( '_transient_timeout_kico_' ) . '%' ) ); // phpcs:ignore WordPress.DB
	wp_cache_flush();
	wp_clear_scheduled_hook( 'kico_daily' );
	wp_clear_scheduled_hook( 'kico_purge' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $kico_site ) {
		switch_to_blog( $kico_site );
		kico_uninstall_site();
		restore_current_blog();
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( '_site_transient_kico_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_kico_' ) . '%' ) ); // phpcs:ignore WordPress.DB
} else {
	kico_uninstall_site();
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_site_transient_kico_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_kico_' ) . '%' ) ); // phpcs:ignore WordPress.DB
}
