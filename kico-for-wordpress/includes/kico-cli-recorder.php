<?php
/**
 * Loaded into WP-CLI's process with --require when Kico runs wp_cli: once
 * WordPress is loaded, records what the command changes (files through
 * Kico_Recorder, $wpdb queries row by row) like php_eval does in-process,
 * and writes it to the file named by KICO_RECORD at shutdown. The plugin's
 * wp_cli tool merges it into the operation's snapshot.
 *
 * Does nothing outside WP-CLI or without KICO_RECORD.
 *
 * @package Kico
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

if ( ! class_exists( 'WP_CLI' ) || ! getenv( 'KICO_RECORD' ) ) {
	return;
}

WP_CLI::add_hook(
	'after_wp_load',
	function () {
		$file = (string) getenv( 'KICO_RECORD' );
		// The plugin may be skipped (--skip-plugins); its classes are loaded from here then.
		foreach ( array( 'class-kico-store.php', 'class-kico-sql.php', 'class-kico-recorder.php', 'class-kico-snapshot.php', 'class-kico-cache.php', 'class-kico-tools.php' ) as $f ) {
			require_once __DIR__ . '/' . $f;
		}
		$s               = new Kico_Snapshot();
		$s->record       = true;
		$s->record_files = '0' !== getenv( 'KICO_RECORD_FILES' );
		$s->use_manifest = false; // The parent compares the files before and after.
		$s->max          = max( 0, (int) getenv( 'KICO_RECORD_ROOM' ) );
		$s->begin();
		register_shutdown_function(
			function () use ( $s, $file ) {
				$s->stop();
				file_put_contents( $file, (string) wp_json_encode( $s->export() ) );
			}
		);
	}
);
