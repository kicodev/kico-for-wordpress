<?php
/**
 * Plugin Name:       Kico for WordPress
 * Plugin URI:        https://kico.dev/help/wordpress
 * Description:       Connects this site to Kico, so your Kico assistant and agents can manage it with the access you have in wp-admin. Every change is snapshotted and logged, and can be rolled back.
 * Version:           0.2.2
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Unifying Systems LLC
 * Author URI:        https://kico.dev
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       kico
 * Domain Path:       /languages
 * Update URI:        https://kico.dev/downloads/kico-for-wordpress.json
 *
 * Kico for WordPress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

define( 'KICO_WP_VERSION', '0.2.2' );
define( 'KICO_WP_FILE', __FILE__ );
define( 'KICO_WP_DIR', __DIR__ );

// The off switch: define( 'KICO_DISABLED', true ); in wp-config.php turns the
// plugin off completely. No route is registered, so Kico's requests get 404.
if ( defined( 'KICO_DISABLED' ) && KICO_DISABLED ) {
	return;
}

require_once __DIR__ . '/includes/class-kico-signature.php';
require_once __DIR__ . '/includes/class-kico-keys.php';
require_once __DIR__ . '/includes/class-kico-mail.php';
require_once __DIR__ . '/includes/class-kico-auth.php';
require_once __DIR__ . '/includes/class-kico-store.php';
require_once __DIR__ . '/includes/class-kico-blobs.php';
require_once __DIR__ . '/includes/class-kico-transport.php';
require_once __DIR__ . '/includes/class-kico-sql.php';
require_once __DIR__ . '/includes/class-kico-recorder.php';
require_once __DIR__ . '/includes/class-kico-snapshot.php';
require_once __DIR__ . '/includes/class-kico-cache.php';
require_once __DIR__ . '/includes/class-kico-ops.php';
require_once __DIR__ . '/includes/class-kico-tools.php';
require_once __DIR__ . '/includes/class-kico-server.php';
require_once __DIR__ . '/includes/class-kico-admin.php';
require_once __DIR__ . '/includes/class-kico-frame.php';
require_once __DIR__ . '/includes/class-kico-updates.php';
require_once __DIR__ . '/includes/class-kico-retention.php';

register_activation_hook( __FILE__, array( 'Kico_Store', 'install' ) );

Kico_Store::boot();
Kico_Auth::boot();
Kico_Transport::boot();
Kico_Server::boot();
Kico_Admin::boot();
Kico_Frame::boot();
Kico_Cache::boot();
Kico_Updates::boot();
Kico_Retention::boot();

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'kico', false, dirname( plugin_basename( KICO_WP_FILE ) ) . '/languages' );
	}
);
