<?php
/**
 * The site's audit log is kept for 180 days (KICO_AUDIT_RETENTION_DAYS in
 * wp-config.php changes it; Kico keeps its own copy of every write). A daily
 * job removes older entries, oldest first, and remembers the hash of the last
 * one removed: the oldest entry left must point at it, so the hash chain
 * still shows an entry changed or removed outside this job.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Retention {

	const HOOK   = 'kico_daily';
	const ANCHOR = 'kico_audit_anchor';
	const DAYS   = 180;
	const BATCH  = 1000;

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		register_deactivation_hook( KICO_WP_FILE, array( __CLASS__, 'unschedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function days() {
		$days = defined( 'KICO_AUDIT_RETENTION_DAYS' ) ? (int) KICO_AUDIT_RETENTION_DAYS : self::DAYS;
		return max( 1, $days );
	}

	/** Removes entries older than the retention, a batch at a time; returns how many went. */
	public static function run() {
		global $wpdb;
		$table  = Kico_Store::audit_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS );
		$gone   = 0;
		do {
			$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, hash FROM $table WHERE created_at < %s ORDER BY id ASC LIMIT 1 OFFSET %d", $cutoff, self::BATCH - 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! $last ) {
				$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, hash FROM $table WHERE created_at < %s ORDER BY id DESC LIMIT 1", $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB
			}
			if ( ! $last ) {
				break;
			}
			// The anchor first: if the delete stops halfway, the chain check names it, not hides it.
			update_option( self::ANCHOR, array( 'id' => (int) $last['id'], 'hash' => (string) $last['hash'], 'at' => time() ), false );
			$n     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", (int) $last['id'] ) ); // phpcs:ignore WordPress.DB
			$gone += $n;
		} while ( $n >= self::BATCH );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Kico_Store::nonce_table() . ' WHERE expires < %d', time() ) ); // phpcs:ignore WordPress.DB
		return $gone;
	}

	/**
	 * Whether the oldest entry of the log follows the last one removed by this
	 * job (or starts the chain, when nothing was removed).
	 *
	 * @param array $oldest The oldest entry left (prev_hash).
	 */
	public static function anchored( array $oldest ) {
		$anchor = get_option( self::ANCHOR );
		$want   = is_array( $anchor ) ? (string) $anchor['hash'] : '';
		return hash_equals( $want, (string) $oldest['prev_hash'] );
	}
}
