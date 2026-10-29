<?php
/**
 * Updates come from Kico (the plugin is not in the wordpress.org directory:
 * guideline 8 does not allow running code sent from a service). WordPress
 * asks the Update URI's host through update_plugins_kico.dev (WordPress 5.8+);
 * the manifest comes from the Kico this site uses (KICO_URL, or the address
 * in Settings > Kico), and its package is resolved against it. The Update
 * URI stays on kico.dev after the app moved to app.kico.dev: the website
 * there forwards /downloads/ to Kico, so every installed version keeps
 * finding its updates.
 *
 * Every package is signed: the release (wordpress/release.ts) signs
 * "kico-for-wordpress:<version>:<sha256 of the zip>" with Ed25519, and this
 * plugin installs only a download whose hash and signature match a key
 * pinned here (or KICO_UPDATE_PUBLIC_KEY for a self-built release). A
 * manifest without a signature offers no update.
 *
 * Kico's "Update to <version>" (the plugin_update tool) runs WordPress's own
 * upgrader, as wp-admin's "Update now" does, so the same check applies. The
 * plugin replaces its own files while its code runs: everything the rest of
 * the request needs is loaded first, and a loopback request (WordPress's
 * fatal error scrape, as its auto-updates use) checks that the new version
 * loads; when it does not, the old folder is put back at once.
 *
 * Auto-updates: on by default (auto_update_plugin, the Plugins screen's
 * toggle and Settings > Kico all show and change one setting), and only as
 * far as WordPress's own constants and filters allow.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Updates {

	const SLUG = 'kico-for-wordpress';
	// The release keys (raw Ed25519 public keys, base64). Rotation: add the new key, release, then drop the old one.
	// Kico's release key: its private half never leaves Kico's key vault.
	const PUBLIC_KEYS = array( 'gZJYJ+59q8caDr204/reSDdgitqiryBjuPMnUf/yMhM=' );
	const CACHE       = 'kico_update';
	const OK_TTL      = 12 * HOUR_IN_SECONDS;
	const RETRY_TTL   = HOUR_IN_SECONDS;
	// The auto-update setting (a network option): '1' on, '0' off, unset on.
	const AUTO_OPTION = 'kico_auto_update';

	/** The version on disk once this request replaced the plugin's files (it runs the old code). */
	private static $disk = null;

	public static function boot() {
		add_filter( 'update_plugins_kico.dev', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_filter( 'site_option_auto_update_plugins', array( __CLASS__, 'auto_list' ) );
		add_filter( 'pre_update_site_option_auto_update_plugins', array( __CLASS__, 'auto_list_saved' ) );
		add_action( 'admin_post_kico_auto_update', array( __CLASS__, 'auto_post' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'probe_answer' ) );
	}

	public static function manifest_url() {
		return Kico_Admin::kico_url() . '/downloads/' . self::SLUG . '.json';
	}

	/** @return string[] */
	public static function keys() {
		$keys = self::PUBLIC_KEYS;
		if ( defined( 'KICO_UPDATE_PUBLIC_KEY' ) && is_string( KICO_UPDATE_PUBLIC_KEY ) && '' !== KICO_UPDATE_PUBLIC_KEY ) {
			$keys[] = KICO_UPDATE_PUBLIC_KEY;
		}
		return $keys;
	}

	/** The signed text of a release. */
	public static function message( $version, $sha256 ) {
		return self::SLUG . ':' . $version . ':' . strtolower( $sha256 );
	}

	/** Whether a signature (base64) is a pinned key's over the release's version and hash. */
	public static function verify( $version, $sha256, $signature ) {
		$sig = base64_decode( (string) $signature, true );
		if ( false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || ! preg_match( '/^[0-9a-f]{64}$/i', (string) $sha256 ) ) {
			return false;
		}
		foreach ( self::keys() as $key ) {
			$pub = base64_decode( $key, true );
			if ( false !== $pub && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $pub ) && sodium_crypto_sign_verify_detached( $sig, self::message( $version, $sha256 ), $pub ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The manifest: version, package (relative to the manifest), sha256,
	 * signature, requires, requires_php, tested, changelog. Fetched at most
	 * every 12 hours, again after an hour when it failed.
	 *
	 * @return array
	 */
	public static function manifest( $fresh = false ) {
		$cached = $fresh ? false : get_site_transient( self::CACHE );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$url = self::manifest_url();
		// Not wp_safe_remote_get: a self-hosted Kico may be on the company network. What it serves is checked by signature.
		$r   = wp_remote_get( $url, array( 'timeout' => 10 ) );
		$m   = is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $m ) || empty( $m['version'] ) ) {
			set_site_transient( self::CACHE, array( 'error' => is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) ), self::RETRY_TTL );
			return array();
		}
		if ( ! empty( $m['package'] ) ) {
			$m['package'] = self::resolve( (string) $m['package'], $url );
		}
		set_site_transient( self::CACHE, $m, self::OK_TTL );
		return $m;
	}

	/** A package address relative to the manifest's, as a browser resolves it (enough for "x.zip" and "/path/x.zip"). */
	public static function resolve( $package, $base ) {
		if ( preg_match( '#^https?://#i', $package ) ) {
			return $package;
		}
		$b = wp_parse_url( $base );
		$o = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $package, '/' ) ) {
			return $o . $package;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return $o . $dir . $package;
	}

	/** Only a signed release is offered. */
	private static function offered( array $m ) {
		return ! empty( $m['version'] ) && ! empty( $m['package'] ) && ! empty( $m['sha256'] ) && ! empty( $m['signature'] )
			&& version_compare( $m['version'], KICO_WP_VERSION, '>' )
			&& self::verify( $m['version'], $m['sha256'], $m['signature'] );
	}

	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( KICO_WP_FILE ) !== $plugin_file ) {
			return $update;
		}
		$m = self::manifest();
		if ( ! self::offered( $m ) ) {
			// No update, but WordPress knows the plugin updates (its no_update list), so
			// the Plugins screen shows the auto-update toggle.
			return array(
				'slug'    => self::SLUG,
				'version' => KICO_WP_VERSION,
				'url'     => 'https://kico.dev/help/wordpress',
				'package' => '',
				'icons'   => self::icons( $m ),
			);
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $m['version'],
			'url'          => 'https://kico.dev/help/wordpress',
			'package'      => $m['package'],
			'tested'       => $m['tested'] ?? '',
			'requires'     => $m['requires'] ?? '',
			'requires_php' => $m['requires_php'] ?? '',
			'icons'        => self::icons( $m ),
		);
	}

	/**
	 * Dashboard > Updates shows these: the manifest's icons when it names
	 * https ones, otherwise the ones in this plugin (no other server is asked).
	 *
	 * @return array svg, 1x, 2x.
	 */
	public static function icons( array $m = array() ) {
		$out = array();
		if ( isset( $m['icons'] ) && is_array( $m['icons'] ) ) {
			foreach ( array( 'svg', '1x', '2x' ) as $k ) {
				if ( isset( $m['icons'][ $k ] ) && is_string( $m['icons'][ $k ] ) && 0 === strpos( $m['icons'][ $k ], 'https://' ) ) {
					$out[ $k ] = esc_url_raw( $m['icons'][ $k ] );
				}
			}
		}
		return $out ? $out : array(
			'svg' => plugins_url( 'assets/icon.svg', KICO_WP_FILE ),
			'1x'  => plugins_url( 'assets/icon-128x128.png', KICO_WP_FILE ),
			'2x'  => plugins_url( 'assets/icon-256x256.png', KICO_WP_FILE ),
		);
	}

	/** Plugins > View details. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		return (object) array(
			'name'          => 'Kico for WordPress',
			'slug'          => self::SLUG,
			'version'       => $m['version'] ?? KICO_WP_VERSION,
			'author'        => '<a href="https://kico.dev">Unifying Systems LLC</a>',
			'homepage'      => 'https://kico.dev/help/wordpress',
			'requires'      => $m['requires'] ?? '',
			'requires_php'  => $m['requires_php'] ?? '',
			'tested'        => $m['tested'] ?? '',
			'download_link' => self::offered( $m ) ? $m['package'] : '',
			'icons'         => self::icons( $m ),
			'sections'      => array(
				'description' => wp_kses_post( wpautop( __( 'Connects this site to Kico, so your Kico assistant and agents can manage it. Every change is snapshotted and logged, and can be rolled back.', 'kico' ) ) ),
				'changelog'   => wp_kses_post( isset( $m['changelog'] ) ? (string) $m['changelog'] : '' ),
			),
		);
	}

	/**
	 * Downloads an update of this plugin and checks it before WordPress unpacks
	 * it: the sha256 of the zip and the release signature over version and hash.
	 *
	 * @param false|string|WP_Error $reply      Earlier filters' answer.
	 * @param string                $package    Package URL.
	 * @param WP_Upgrader           $upgrader   Upgrader.
	 * @param array                 $hook_extra plugin, among others.
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$m    = self::manifest();
		$ours = ( isset( $hook_extra['plugin'] ) && plugin_basename( KICO_WP_FILE ) === $hook_extra['plugin'] ) || ( ! empty( $m['package'] ) && $m['package'] === $package );
		if ( ! $ours ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $m['sha256'] ) || empty( $m['signature'] ) || empty( $m['package'] ) || $m['package'] !== $package ) {
			// The manifest may have changed since WordPress saw the update: look again once.
			$m = self::manifest( true );
		}
		if ( empty( $m['sha256'] ) || empty( $m['signature'] ) || ! self::verify( (string) $m['version'], (string) $m['sha256'], (string) $m['signature'] ) ) {
			return new WP_Error( 'kico_update_unsigned', __( 'This Kico for WordPress update is not signed with Kico\'s release key, so it was not installed.', 'kico' ) );
		}
		// download_url refuses internal hosts and other ports than 80, 443 and 8080 (a
		// self-hosted Kico); the package is checked by hash and signature, wherever it comes from.
		$host  = (string) wp_parse_url( $package, PHP_URL_HOST );
		$port  = (int) wp_parse_url( $package, PHP_URL_PORT );
		$allow = function ( $external, $h ) use ( $host ) {
			return $h === $host ? true : $external;
		};
		$ports = function ( $allowed ) use ( $port ) {
			return $port ? array_merge( (array) $allowed, array( $port ) ) : $allowed;
		};
		add_filter( 'http_request_host_is_external', $allow, 10, 2 );
		add_filter( 'http_allowed_safe_ports', $ports );
		$file = download_url( $package, 300 );
		remove_filter( 'http_request_host_is_external', $allow, 10 );
		remove_filter( 'http_allowed_safe_ports', $ports );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$hash = hash_file( 'sha256', $file );
		if ( ! hash_equals( strtolower( (string) $m['sha256'] ), (string) $hash ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'kico_update_tampered', sprintf( /* translators: 1: expected hash, 2: hash of the download. */ __( 'The downloaded Kico for WordPress update is not the signed release (sha256 %2$s, signed %1$s), so it was not installed.', 'kico' ), $m['sha256'], $hash ) );
		}
		return $file;
	}

	// --- the version running and on disk -----------------------------------------------------------

	/** The plugin's version as the next request runs it: the files' after this request replaced them. */
	public static function version() {
		return null !== self::$disk ? self::$disk : KICO_WP_VERSION;
	}

	/** Reads the version of the plugin's files now (after an update or a rollback replaced them). */
	public static function note_replaced() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		clearstatcache();
		$data       = file_exists( KICO_WP_FILE ) ? get_plugin_data( KICO_WP_FILE, false, false ) : array();
		self::$disk = ! empty( $data['Version'] ) ? (string) $data['Version'] : '';
		return self::$disk;
	}

	/**
	 * Before the plugin's own files are replaced in this request: loads every
	 * class the rest of it may need (the MCP Adapter's, which autoload), so the
	 * answer comes from the code already loaded and never from half-copied or
	 * newer files. Kico's own classes are loaded at start.
	 */
	public static function preload() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$map  = KICO_WP_DIR . '/vendor/composer/jetpack_autoload_classmap.php';
		$list = is_readable( $map ) ? include $map : array();
		foreach ( array_keys( is_array( $list ) ? $list : array() ) as $class ) {
			$class = (string) $class;
			if ( ( 0 !== strpos( $class, 'WP\\MCP\\' ) && 0 !== strpos( $class, 'WP\\McpSchema\\' ) ) || ( false !== strpos( $class, '\\Cli\\' ) && ! defined( 'WP_CLI' ) ) ) {
				continue;
			}
			if ( class_exists( $class, false ) || interface_exists( $class, false ) || trait_exists( $class, false ) ) {
				continue;
			}
			try {
				class_exists( $class );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Not needed then.
			}
		}
	}

	// --- the loopback check ------------------------------------------------------------------------

	/**
	 * Loads the home page in a request of its own, as WordPress's auto-updates
	 * do after updating a plugin (its fatal error scrape, wp_scrape_key): did
	 * WordPress load with the plugin's files as they are now?
	 *
	 * @return array reached, clean (true: no fatal error), fatal (the error), status, version (the plugin's, 0.2.1 and later say it), error.
	 */
	public static function probe() {
		$key   = strtolower( wp_generate_password( 32, false, false ) );
		$nonce = wp_generate_password( 20, false, false );
		set_transient( 'scrape_key_' . $key, $nonce, 60 );
		$url     = add_query_arg( array( 'wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce ), home_url( '/' ) );
		$headers = array( 'Cache-Control' => 'no-cache' );
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$r = wp_remote_get(
			$url,
			array(
				'timeout'   => 50,
				'headers'   => $headers,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core's filter.
			)
		);
		delete_transient( 'scrape_key_' . $key );
		if ( is_wp_error( $r ) ) {
			return array( 'reached' => false, 'clean' => null, 'error' => $r->get_error_message() );
		}
		$body   = wp_remote_retrieve_body( $r );
		$start  = "###### wp_scraping_result_start:$key ######";
		$end    = "###### wp_scraping_result_end:$key ######";
		$at     = strpos( $body, $start );
		$result = null;
		if ( false !== $at ) {
			$out    = substr( $body, $at + strlen( $start ) );
			$result = json_decode( trim( substr( $out, 0, (int) strpos( $out, $end ) ) ), true );
		}
		$code    = (int) wp_remote_retrieve_response_code( $r );
		$version = (string) wp_remote_retrieve_header( $r, 'kico-plugin' );
		$fatal   = is_array( $result ) && isset( $result['type'] ) ? $result : null;
		return array(
			'reached' => true,
			// Without the scrape's answer (a cache or another page answered), nothing is known.
			'clean'   => $fatal ? false : ( true === $result ? true : null ),
			'fatal'   => $fatal ? sprintf( '%s (%s:%d)', (string) ( $fatal['message'] ?? '' ), (string) ( $fatal['file'] ?? '' ), (int) ( $fatal['line'] ?? 0 ) ) : null,
			'status'  => $code,
			'version' => preg_match( '/^[0-9A-Za-z.+-]{1,20}$/', $version ) ? $version : null,
		);
	}

	/** The loopback check's request: the plugin says which version loaded (only to a valid scrape key). */
	public static function probe_answer() {
		if ( ! defined( 'WP_SANDBOX_SCRAPING' ) || ! isset( $_REQUEST['wp_scrape_key'], $_REQUEST['wp_scrape_nonce'] ) || headers_sent() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key = substr( sanitize_key( wp_unslash( $_REQUEST['wp_scrape_key'] ) ), 0, 32 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( get_transient( 'scrape_key_' . $key ) === wp_unslash( $_REQUEST['wp_scrape_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			header( 'Kico-Plugin: ' . KICO_WP_VERSION );
		}
	}

	// --- Kico's "Update to <version>" --------------------------------------------------------------

	/** What the snapshot keeps: the plugin's folder as a zip (Changes and rollback in Kico put it back). */
	public static function snapshot_update( array $a, Kico_Snapshot $s ) {
		$s->archive( KICO_WP_DIR );
	}

	/**
	 * The plugin_update tool: updates this plugin to the signed release the
	 * manifest offers, through WordPress's upgrader (Plugin_Upgrader::bulk_upgrade
	 * with the AJAX skin, as wp-admin's "Update now"), so upgrader_pre_download
	 * checks the package (download). Then checks that the new version loads, and
	 * puts the old folder back when it does not.
	 *
	 * @param array         $a Arguments (none).
	 * @param Kico_Snapshot $s The operation's snapshot, with the old folder.
	 */
	public static function update_self( array $a, $s ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$basename = plugin_basename( KICO_WP_FILE );
		$from     = KICO_WP_VERSION;
		$notes    = array();
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$notes[] = 'wp-config.php sets DISALLOW_FILE_MODS: wp-admin offers no updates on this site; Kico\'s update ran through PHP.';
		}

		// What WordPress would learn from the manifest now (wp_update_plugins
		// asks wordpress.org first, and stops when that fails).
		$m = self::manifest( true );
		if ( empty( $m['version'] ) ) {
			$cached = get_site_transient( self::CACHE );
			return array( 'ok' => false, 'version' => $from, 'error' => sprintf( 'The update manifest at %s could not be read (%s).', self::manifest_url(), is_array( $cached ) && ! empty( $cached['error'] ) ? $cached['error'] : 'no version in it' ) );
		}
		if ( ! version_compare( (string) $m['version'], $from, '>' ) ) {
			return array( 'ok' => false, 'code' => 'up_to_date', 'version' => $from, 'error' => sprintf( 'Kico for WordPress %s is the newest: the manifest at %s offers %s.', $from, self::manifest_url(), (string) $m['version'] ) );
		}
		if ( ! self::offered( $m ) ) {
			return array( 'ok' => false, 'version' => $from, 'error' => sprintf( 'Kico for WordPress %s at %s is not signed with a release key this site trusts, so it was not installed.', (string) $m['version'], self::manifest_url() ) );
		}
		$item = (object) self::check( false, get_plugin_data( KICO_WP_FILE, false, false ), $basename );
		$item->id          = self::manifest_url();
		$item->plugin      = $basename;
		$item->new_version = $item->version;
		$current           = get_site_transient( 'update_plugins' );
		if ( ! is_object( $current ) ) {
			$current = new stdClass();
		}
		if ( ! isset( $current->response ) || ! is_array( $current->response ) ) {
			$current->response = array();
		}
		$current->response[ $basename ] = $item;
		set_site_transient( 'update_plugins', $current );

		if ( ! isset( $s->archives[ KICO_WP_DIR ]['zip'] ) ) {
			$notes[] = 'The plugin\'s folder is not in the snapshot (see the snapshot notes): this update cannot be rolled back from Kico.';
		}
		self::preload();
		$before = self::probe();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array( $basename ) );
		$messages = array_values( array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ) );
		$error    = null;
		if ( is_wp_error( $skin->result ) ) {
			$error = $skin->result;
		} elseif ( $skin->get_errors()->has_errors() ) {
			$error = $skin->get_errors();
		} elseif ( false === $result ) {
			$error = new WP_Error( 'unable_to_connect_to_filesystem', __( 'Unable to connect to the filesystem. Please confirm your credentials.' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's string.
		} elseif ( is_array( $result ) && true === ( $result[ $basename ] ?? null ) ) {
			$error = new WP_Error( 'up_to_date', $upgrader->strings['up_to_date'] );
		} elseif ( ! is_array( $result ) || empty( $result[ $basename ] ) ) {
			$error = new WP_Error( 'update_failed', __( 'Plugin update failed.' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's string.
		}
		$now = self::note_replaced();
		if ( $error ) {
			return array(
				'ok'       => false,
				'code'     => $error->get_error_code(),
				'error'    => implode( ' ', array_map( 'wp_strip_all_tags', $error->get_error_messages() ) ),
				'from'     => $from,
				'version'  => '' !== $now ? $now : $from,
				'messages' => $messages,
				'notes'    => $notes,
			);
		}

		// Does WordPress load with the new files? WordPress itself waits too before it looks.
		sleep( 2 );
		$after  = self::probe();
		$broken = false === $after['clean'] || ( ! $after['reached'] && $before['reached'] ) || ( $after['reached'] && $after['status'] >= 500 && null === $after['clean'] && ( $before['status'] ?? 0 ) < 500 );
		if ( $broken ) {
			$why  = $after['fatal'] ? 'a PHP fatal error: ' . $after['fatal'] : ( $after['reached'] ? 'HTTP ' . $after['status'] : 'no answer: ' . ( $after['error'] ?? '' ) );
			$back = isset( $s->archives[ KICO_WP_DIR ]['zip'] ) ? Kico_Snapshot::restore_folder( $s->archives[ KICO_WP_DIR ] ) : 'The old folder is not in the snapshot.';
			if ( true !== $back && method_exists( $upgrader, 'restore_temp_backup' ) ) {
				// WordPress's own copy of the old folder, kept until this request ends.
				$wp_back = $upgrader->restore_temp_backup( array( array( 'dir' => 'plugins', 'slug' => dirname( $basename ), 'src' => WP_PLUGIN_DIR ) ) );
				$back    = is_wp_error( $wp_back ) ? $back . ' ' . $wp_back->get_error_message() : true;
			}
			$restored = self::note_replaced();
			$check    = true === $back ? self::probe() : null;
			return array(
				'ok'          => false,
				'code'        => 'kico_update_broken',
				'error'       => true === $back
					? sprintf( 'Kico for WordPress %1$s did not load (%2$s), so %3$s was put back.', $now, $why, $restored ) . ( $check && false === $check['clean'] ? ' The site still stops with a fatal error: ' . $check['fatal'] : '' )
					: sprintf( 'Kico for WordPress %1$s did not load (%2$s), and putting %3$s back failed: %4$s', $now, $why, $from, $back ),
				'from'        => $from,
				'tried'       => $now,
				'version'     => $restored,
				'rolled_back' => true === $back,
				'loads'       => $check ? $check['clean'] : null,
				'messages'    => $messages,
				'notes'       => $notes,
			);
		}
		if ( null === $after['clean'] ) {
			$notes[] = $after['reached']
				? sprintf( 'The site\'s loopback request answered HTTP %d without WordPress\'s check, so it is not known yet whether %s loads; Kico checks on its next call.', $after['status'], $now )
				: sprintf( 'The site cannot reach itself (%s), so it is not known yet whether %s loads; Kico checks on its next call.', $after['error'] ?? '', $now );
		}
		return array(
			'ok'       => true,
			'from'     => $from,
			'version'  => $now,
			'loads'    => $after['clean'],
			'loaded'   => $after['version'] ?? null,
			'messages' => $messages,
			'notes'    => $notes,
		);
	}

	// --- auto-updates ------------------------------------------------------------------------------

	/** Whether the admin wants WordPress to update Kico for WordPress on its own (default on). */
	public static function auto_wanted() {
		return '0' !== (string) get_site_option( self::AUTO_OPTION, '1' );
	}

	public static function set_auto( $on ) {
		update_site_option( self::AUTO_OPTION, $on ? '1' : '0' );
	}

	/**
	 * What stops WordPress from updating plugins on its own on this site,
	 * whatever the setting says: WordPress's constants and filters.
	 *
	 * @return string[] Each reason, said for the admin.
	 */
	public static function auto_blocked() {
		$why = array();
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$why[] = __( 'wp-config.php sets AUTOMATIC_UPDATER_DISABLED, which turns off all automatic updates.', 'kico' );
		} elseif ( apply_filters( 'automatic_updater_disabled', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core's filter.
			$why[] = __( 'A plugin or theme turns off all automatic updates (the automatic_updater_disabled filter).', 'kico' );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$why[] = __( 'wp-config.php sets DISALLOW_FILE_MODS, which turns off updates and installs from WordPress.', 'kico' );
		} elseif ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			$why[] = __( 'A plugin or theme does not allow automatic updates to change files (the file_mod_allowed filter).', 'kico' );
		}
		// (WordPress turns plugin auto-updates off too when the above do.)
		if ( ! $why && ( function_exists( 'wp_is_auto_update_enabled_for_type' ) ? ! wp_is_auto_update_enabled_for_type( 'plugin' ) : ! apply_filters( 'plugins_auto_update_enabled', true ) ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core's filter.
			$why[] = __( 'A plugin or theme turns off plugin auto-updates (the plugins_auto_update_enabled filter).', 'kico' );
		}
		return $why;
	}

	/**
	 * auto_update_plugin: for this plugin, the admin's setting, when WordPress
	 * decides (its constants and other filters still apply). The Plugins
	 * screen asks with null first, whether the setting is forced: it is not,
	 * so the screen keeps its toggle.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update offer.
	 */
	public static function auto_update( $update, $item ) {
		if ( null === $update || ! is_object( $item ) || plugin_basename( KICO_WP_FILE ) !== ( $item->plugin ?? '' ) ) {
			return $update;
		}
		if ( function_exists( 'wp_is_auto_update_enabled_for_type' ) && ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			return $update;
		}
		return self::auto_wanted();
	}

	/** The list WordPress keeps of plugins to auto-update shows the setting (the Plugins screen, Site Health). */
	public static function auto_list( $list ) {
		$list = is_array( $list ) ? $list : array();
		$me   = plugin_basename( KICO_WP_FILE );
		$in   = in_array( $me, $list, true );
		if ( self::auto_wanted() && ! $in ) {
			$list[] = $me;
		} elseif ( ! self::auto_wanted() && $in ) {
			$list = array_values( array_diff( $list, array( $me ) ) );
		}
		return $list;
	}

	/** The Plugins screen's toggle changed the list: the setting follows. */
	public static function auto_list_saved( $value ) {
		$on = is_array( $value ) && in_array( plugin_basename( KICO_WP_FILE ), $value, true );
		if ( $on !== self::auto_wanted() ) {
			self::set_auto( $on );
		}
		return $value;
	}

	/** Settings > Kico's switch. */
	public static function auto_post() {
		if ( ! Kico_Auth::is_admin_user( get_current_user_id() ) || ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Only an administrator who may update plugins changes this.', 'kico' ) );
		}
		check_admin_referer( 'kico_auto_update' );
		self::set_auto( isset( $_POST['auto'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['auto'] ) ) );
		wp_safe_redirect( Kico_Admin::page_url( '&kico=' . ( self::auto_wanted() ? 'auto-on' : 'auto-off' ) . '#kico-updates' ) );
		exit;
	}

	/** For env: the setting and what WordPress makes of it. */
	public static function auto_info() {
		$blocked = self::auto_blocked();
		return array(
			'wanted'  => self::auto_wanted(),
			'on'      => self::auto_wanted() && ! $blocked,
			'blocked' => $blocked,
		);
	}
}

