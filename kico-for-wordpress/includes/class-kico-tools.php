<?php
/**
 * The tools Kico's agents get: the same reach the admin has in wp-admin and
 * more (files, the database, PHP). Each says what it does in its description
 * and whether it only reads, so Kico's approval rules work per tool. None of
 * them refuses on its own: limits of the host are reported (env), and the
 * model picks the route.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Tools {

	// --- helpers ---------------------------------------------------------------------------------

	/** An absolute path: relative paths are relative to the WordPress root (ABSPATH). */
	public static function path( $p ) {
		$p = (string) $p;
		if ( '' === $p ) {
			return rtrim( ABSPATH, '/' );
		}
		if ( '/' !== $p[0] && ! preg_match( '#^[A-Za-z]:[\\\\/]#', $p ) ) {
			$p = ABSPATH . ltrim( $p, '/' );
		}
		return wp_normalize_path( $p );
	}

	// --- writing files: open_basedir, owners, WP_Filesystem ---------------------------------------

	/** The open_basedir folders PHP is limited to (none: no limit). */
	public static function basedirs() {
		$v = (string) ini_get( 'open_basedir' );
		return '' === $v ? array() : array_filter( array_map( 'wp_normalize_path', explode( PATH_SEPARATOR, $v ) ) );
	}

	/** Why PHP cannot reach a path because of open_basedir, or null. */
	public static function outside_basedir( $p ) {
		$dirs = self::basedirs();
		if ( ! $dirs ) {
			return null;
		}
		$p = wp_normalize_path( $p );
		foreach ( $dirs as $d ) {
			if ( 0 === strpos( $p, $d ) ) {
				return null;
			}
		}
		return sprintf( 'PHP may not reach %s: open_basedir limits it to %s (the host\'s setting).', $p, implode( ', ', $dirs ) );
	}

	/** WordPress's filesystem with FTP or SSH credentials from wp-config.php, when PHP cannot write directly; else null. */
	public static function wpfs() {
		static $fs = false;
		if ( false !== $fs ) {
			return $fs;
		}
		$fs = null;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$method = get_filesystem_method();
		if ( 'direct' === $method || ! ( defined( 'FTP_HOST' ) || defined( 'FTP_USER' ) ) ) {
			return $fs;
		}
		ob_start();
		$creds = request_filesystem_credentials( '', $method, false, false, null );
		ob_end_clean();
		if ( $creds && WP_Filesystem( $creds ) ) {
			global $wp_filesystem;
			$fs = $wp_filesystem;
		}
		return $fs;
	}

	private static function who() {
		return self::can_call( 'posix_geteuid' ) ? posix_geteuid() : getmyuid();
	}

	/**
	 * Why PHP cannot write, create, delete or replace a path, or null when it
	 * can (directly or through WP_Filesystem). Says what would let it.
	 */
	public static function cannot_write( $p, $verb = 'write' ) {
		$exists = file_exists( $p ) || is_link( $p );
		if ( 'delete' === $verb && ! $exists ) {
			return null;
		}
		$why = self::outside_basedir( $p );
		if ( $why ) {
			return $why;
		}
		if ( 'write' === $verb && $exists && ! is_dir( $p ) ) {
			$target = $p;
		} else {
			// Creating, deleting or replacing needs the folder it is in.
			$target = dirname( $p );
			while ( ! file_exists( $target ) && dirname( $target ) !== $target ) {
				$target = dirname( $target );
			}
		}
		if ( wp_is_writable( $target ) || self::wpfs() ) {
			return null;
		}
		$owner = @fileowner( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$perms = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$msg   = sprintf( 'PHP (user %s) cannot %s %s: %s belongs to user %s, mode %s.', self::who(), $verb, $p, $target === $p ? 'it' : $target, false === $owner ? '?' : $owner, false === $perms ? '?' : substr( sprintf( '%o', $perms ), -4 ) );
		if ( false !== $owner && (int) $owner !== (int) self::who() ) {
			$msg .= ' WordPress can write as that user over FTP or SSH when FS_METHOD and FTP_HOST, FTP_USER, FTP_PASS (or FTP_PUBKEY, FTP_PRIKEY) are set in wp-config.php.';
		}
		return $msg;
	}

	public static function mkdir( $dir ) {
		if ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) {
			return true;
		}
		$fs = self::wpfs();
		if ( ! $fs ) {
			return false;
		}
		$parts = array();
		for ( $d = $dir; ! is_dir( $d ) && dirname( $d ) !== $d; $d = dirname( $d ) ) {
			array_unshift( $parts, $d );
		}
		foreach ( $parts as $d ) {
			if ( ! $fs->mkdir( $d, FS_CHMOD_DIR ) ) {
				return false;
			}
		}
		return is_dir( $dir );
	}

	/** Writes a file: directly, else through WP_Filesystem. */
	public static function put( $path, $data, $perms = null ) {
		if ( ! self::mkdir( dirname( $path ) ) ) {
			return false;
		}
		$ok = false !== @file_put_contents( $path, $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $ok && self::wpfs() ) {
			$ok = self::wpfs()->put_contents( $path, $data, $perms ? (int) $perms : FS_CHMOD_FILE );
		}
		if ( $ok && null !== $perms ) {
			@chmod( $path, (int) $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( $ok && function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $ok;
	}

	/** Deletes a file or link: directly, else through WP_Filesystem. */
	public static function remove_file( $path ) {
		if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return true;
		}
		return self::wpfs() ? (bool) self::wpfs()->delete( $path ) : false;
	}

	/** Deletes a folder with everything in it. */
	public static function delete_tree( $dir ) {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return self::remove_file( $dir );
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		if ( @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return true;
		}
		return self::wpfs() ? (bool) self::wpfs()->delete( $dir, true ) : false;
	}

	private static function decode_arg( array $a, $name ) {
		if ( isset( $a[ $name . '_base64' ] ) && '' !== $a[ $name . '_base64' ] ) {
			$v = base64_decode( (string) $a[ $name . '_base64' ], true );
			return false === $v ? null : $v;
		}
		return isset( $a[ $name ] ) ? (string) $a[ $name ] : null;
	}

	private static function disabled_functions() {
		return array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
	}

	public static function can_call( $fn ) {
		return function_exists( $fn ) && ! in_array( $fn, self::disabled_functions(), true );
	}

	public static function elementor_active() {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	/** Elementor's generated CSS and element cache, after its data changed underneath it. */
	public static function elementor_flush( $post_id = 0 ) {
		if ( ! self::elementor_active() ) {
			return false;
		}
		try {
			if ( $post_id ) {
				delete_post_meta( $post_id, '_elementor_element_cache' );
				$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
				$css->update();
			} else {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public static function site_info() {
		global $wp_version;
		return array(
			'site'           => home_url( '/' ),
			'siteurl'        => site_url( '/' ),
			'name'           => get_bloginfo( 'name' ),
			'mcp_url'        => rest_url( Kico_Auth::NS . '/mcp' ),
			'disconnect_url' => rest_url( Kico_Auth::NS . '/disconnect' ),
			'wp_version'     => $wp_version,
			'plugin_version' => KICO_WP_VERSION,
			'multisite'      => is_multisite(),
			'transport'      => Kico_Transport::info(),
		);
	}

	// --- environment -----------------------------------------------------------------------------

	public static function env() {
		global $wpdb, $wp_version;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$uploads = wp_upload_dir( null, false );
		$paths   = array(
			'wordpress_root' => ABSPATH,
			'wp_config'      => file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php',
			'wp_content'     => WP_CONTENT_DIR,
			'plugins'        => WP_PLUGIN_DIR,
			'themes'         => get_theme_root(),
			'active_theme'   => get_stylesheet_directory(),
			'uploads'        => $uploads['basedir'],
		);
		$php_uid = self::can_call( 'posix_geteuid' ) ? posix_geteuid() : getmyuid();
		$files   = array();
		foreach ( $paths as $k => $p ) {
			$files[ $k ] = array(
				'path'     => $p,
				'exists'   => file_exists( $p ),
				'writable' => wp_is_writable( $p ),
				'owner'    => file_exists( $p ) ? fileowner( $p ) : null,
			);
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active( $file ) );
		}
		$theme  = wp_get_theme();
		$grants = $wpdb->get_col( 'SHOW GRANTS' ); // phpcs:ignore WordPress.DB
		$shell  = array();
		foreach ( array( 'exec', 'shell_exec', 'proc_open', 'system', 'passthru', 'popen' ) as $fn ) {
			$shell[ $fn ] = self::can_call( $fn );
		}
		$markers = array();
		foreach ( array( 'IS_ATOMIC', 'WPCOMSH_VERSION', 'IS_PRESSABLE', 'WPE_APIKEY', 'KINSTAMU_VERSION', 'FLYWHEEL_CONFIG_DIR', 'GD_SYSTEM_PLUGIN_DIR', 'WPMU_PLUGIN_DIR', 'IS_WPE' ) as $c ) {
			if ( defined( $c ) ) {
				$markers[] = $c;
			}
		}
		foreach ( array( 'PANTHEON_ENVIRONMENT', 'KINSTA_CACHE_ZONE', 'WPE_CLUSTER_ID' ) as $e ) {
			if ( false !== getenv( $e ) ) {
				$markers[] = 'env:' . $e;
			}
		}
		return array(
			'site'        => self::site_info(),
			// WordPress's auto-updates of this plugin: the admin's setting, and what stops them.
			'auto_update' => Kico_Updates::auto_info(),
			'acting_as'   => array( 'id' => get_current_user_id(), 'login' => wp_get_current_user()->user_login, 'roles' => wp_get_current_user()->roles ),
			'wordpress'   => array(
				'version'            => $wp_version,
				'locale'             => get_locale(),
				'permalinks'         => get_option( 'permalink_structure' ),
				'block_theme'        => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
				'theme'              => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'stylesheet' => get_stylesheet(), 'template' => get_template() ),
				'plugins'            => $plugins,
				'elementor'          => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				'elementor_pro'      => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
				'DISALLOW_FILE_EDIT' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'DISALLOW_FILE_MODS' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
				'WP_DEBUG'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_CACHE'           => defined( 'WP_CACHE' ) && WP_CACHE,
				'object_cache'       => wp_using_ext_object_cache(),
				'filesystem_method'  => get_filesystem_method(),
				'filesystem_credentials' => defined( 'FTP_HOST' ) || defined( 'FTP_USER' ) ? 'set in wp-config.php: Kico writes through WP_Filesystem when PHP cannot write a file directly' : 'none (FTP_HOST, FTP_USER, FTP_PASS): files PHP cannot write are reported, not written',
				'abilities'          => function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : null,
			),
			'php'         => array(
				'version'            => PHP_VERSION,
				'sapi'               => PHP_SAPI,
				'os'                 => PHP_OS,
				'user_id'            => $php_uid,
				'memory_limit'       => ini_get( 'memory_limit' ),
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'upload_max'         => ini_get( 'upload_max_filesize' ),
				'post_max'           => ini_get( 'post_max_size' ),
				'disable_functions'  => self::disabled_functions(),
				'open_basedir'       => ini_get( 'open_basedir' ) ? explode( PATH_SEPARATOR, ini_get( 'open_basedir' ) ) : array(),
				'shell'              => $shell,
				'wp_cli'             => self::wp_cli_binary(),
				'extensions'         => array_values( array_intersect( array( 'sodium', 'gd', 'imagick', 'zip', 'curl', 'mbstring', 'intl', 'opcache' ), get_loaded_extensions() ) ),
			),
			'server'      => array(
				'software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null,
				'host_markers' => $markers,
			),
			'files'       => $files,
			'database'    => self::database_info( $grants ),
			'multisite'   => is_multisite() ? array(
				'blog_id'      => get_current_blog_id(),
				'main_site'    => is_main_site(),
				'network_id'   => get_current_network_id(),
				'sites'        => (int) get_sites( array( 'count' => true ) ),
				'base_prefix'  => $wpdb->base_prefix,
				'scope'        => 'Tools act on this site (' . home_url( '/' ) . '): its tables (prefix ' . $wpdb->prefix . ( is_main_site() ? ', plus the network\'s shared tables' : '' ) . '), its options and uploads; wp_cli gets --url for it. Files, plugins and themes are shared by the network.',
			) : null,
			'caches'      => Kico_Cache::detect(),
			'snapshots'   => array(
				'max_bytes' => Kico_Snapshot::max_bytes(),
				'sql_parser' => Kico_Sql::available(),
				'note'      => 'Every write records what it changes and Kico keeps it, so rollback restores that one operation: files, rows by key for SQL (whole tables for DDL), and for php_eval, wp_cli and abilities_run what the code changed through PHP\'s file functions and $wpdb while it ran. What a snapshot could not keep is in snapshot_notes.',
			),
			'time'        => array( 'unix' => time(), 'timezone' => wp_timezone_string() ),
		);
	}

	private static function database_info( $grants ) {
		global $wpdb;
		$tables = Kico_Snapshot::site_tables();
		$size   = 0.0;
		if ( $tables ) {
			$in   = implode( ',', array_map( function ( $t ) use ( $wpdb ) {
				return "'" . $wpdb->_real_escape( $t ) . "'";
			}, $tables ) );
			$size = (float) $wpdb->get_var( "SELECT SUM(data_length + index_length) / 1048576 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($in)" ); // phpcs:ignore WordPress.DB
		}
		return array(
			'server'  => $wpdb->db_server_info(),
			'name'    => DB_NAME,
			'prefix'  => $wpdb->prefix,
			'tables'  => count( $tables ),
			'grants'  => $grants ?: array( 'SHOW GRANTS failed: ' . $wpdb->last_error ),
			'size_mb' => round( $size, 1 ),
		);
	}

	private static function wp_cli_binary() {
		if ( ! self::can_call( 'proc_open' ) ) {
			return array( 'available' => false, 'why' => 'proc_open is disabled; use php_eval.' );
		}
		$out = self::exec( array( '/bin/sh', '-c', 'command -v wp || command -v wp-cli || ls /usr/local/bin/wp 2>/dev/null' ), 10 );
		$bin = trim( strtok( (string) $out['stdout'], "\n" ) );
		return '' !== $bin ? array( 'available' => true, 'path' => $bin ) : array( 'available' => false, 'why' => 'No wp binary on the PATH; use php_eval.' );
	}

	private static function exec( array $cmd, $timeout, $env = null ) {
		$spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$proc = proc_open( $cmd, $spec, $pipes, ABSPATH, $env ); // phpcs:ignore Generic.PHP.ForbiddenFunctions
		if ( ! is_resource( $proc ) ) {
			return array( 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed' );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$start  = time();
		$code   = null;
		for ( ;; ) {
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );
			$st      = proc_get_status( $proc );
			if ( ! $st['running'] ) {
				$code = $st['exitcode'];
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $proc );
				$stderr .= "\n[stopped after {$timeout} s]";
				break;
			}
			usleep( 50000 );
		}
		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$closed = proc_close( $proc );
		return array( 'code' => null === $code ? $closed : $code, 'stdout' => $stdout, 'stderr' => $stderr );
	}

	// --- files -----------------------------------------------------------------------------------

	public static function fs_list( array $a ) {
		$root  = self::path( $a['path'] ?? '' );
		$depth = max( 1, min( 5, (int) ( $a['depth'] ?? 1 ) ) );
		$max   = 2000;
		$out   = array();
		if ( ! is_dir( $root ) ) {
			return array( 'ok' => false, 'error' => file_exists( $root ) ? "$root is a file; use fs_read." : "$root does not exist (or PHP may not see it: open_basedir)." );
		}
		$walk = function ( $dir, $level ) use ( &$walk, &$out, $depth, $max, $root ) {
			$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $items ) {
				$out[] = array( 'path' => $dir, 'error' => 'not readable' );
				return;
			}
			foreach ( $items as $name ) {
				if ( '.' === $name || '..' === $name || count( $out ) >= $max ) {
					continue;
				}
				$p     = $dir . '/' . $name;
				$isdir = is_dir( $p );
				$out[] = array(
					'path'     => ltrim( substr( $p, strlen( $root ) ), '/' ),
					'type'     => $isdir ? 'dir' : ( is_link( $p ) ? 'link' : 'file' ),
					'size'     => $isdir ? null : @filesize( $p ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
					'modified' => gmdate( 'c', (int) @filemtime( $p ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
					'writable' => wp_is_writable( $p ),
				);
				if ( $isdir && $level < $depth ) {
					$walk( $p, $level + 1 );
				}
			}
		};
		$walk( rtrim( $root, '/' ), 1 );
		return array( 'root' => $root, 'entries' => $out, 'truncated' => count( $out ) >= $max );
	}

	public static function fs_read( array $a ) {
		$path = self::path( $a['path'] ?? '' );
		if ( ! is_file( $path ) ) {
			return array( 'ok' => false, 'error' => "$path is not a file (or PHP may not see it: open_basedir)." );
		}
		$size   = (int) filesize( $path );
		$offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
		$length = max( 1, min( 5 * 1024 * 1024, (int) ( $a['length'] ?? 1024 * 1024 ) ) );
		$data   = file_get_contents( $path, false, null, $offset, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $data ) {
			return array( 'ok' => false, 'error' => "$path is not readable by PHP." );
		}
		$text = ( function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $data, 'UTF-8' ) : ! preg_match( '/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', $data ) ) && false === strpos( $data, "\0" );
		return array(
			'path'     => $path,
			'size'     => $size,
			'offset'   => $offset,
			'length'   => strlen( $data ),
			'more'     => $offset + strlen( $data ) < $size,
			'modified' => gmdate( 'c', (int) filemtime( $path ) ),
			'writable' => wp_is_writable( $path ),
		) + ( $text ? array( 'content' => $data ) : array( 'content_base64' => base64_encode( $data ) ) );
	}

	public static function fs_write( array $a, ?Kico_Snapshot $s = null ) {
		$path    = self::path( $a['path'] ?? '' );
		$content = self::decode_arg( $a, 'content' );
		if ( null === $content ) {
			return array( 'ok' => false, 'error' => 'Give content (text) or content_base64.' );
		}
		if ( ! self::mkdir( dirname( $path ) ) ) {
			return array( 'ok' => false, 'error' => 'Could not create the folder ' . dirname( $path ) . '. ' . self::cannot_write( dirname( $path ), 'create' ) );
		}
		if ( ! empty( $a['append'] ) ) {
			$ok = false !== @file_put_contents( $path, $content, FILE_APPEND ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! $ok && self::wpfs() && file_exists( $path ) ) {
				$ok = self::wpfs()->put_contents( $path, file_get_contents( $path ) . $content, FS_CHMOD_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		} else {
			$ok = self::put( $path, $content );
		}
		if ( ! $ok ) {
			$e = error_get_last();
			return array( 'ok' => false, 'error' => "Could not write $path. " . ( self::cannot_write( $path, 'write' ) ?: ( $e ? $e['message'] : '' ) ) );
		}
		clearstatcache( true, $path );
		return array( 'path' => $path, 'bytes' => strlen( $content ), 'size' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) );
	}

	public static function fs_delete( array $a ) {
		$path = self::path( $a['path'] ?? '' );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return array( 'ok' => false, 'error' => "$path does not exist." . ( self::outside_basedir( $path ) ? ' ' . self::outside_basedir( $path ) : '' ) );
		}
		$ok = self::delete_tree( $path );
		clearstatcache();
		return $ok && ! file_exists( $path ) ? array( 'deleted' => $path ) : array( 'ok' => false, 'error' => "Could not delete $path. " . self::cannot_write( $path, 'delete' ) );
	}

	public static function fs_move( array $a ) {
		$from = self::path( $a['from'] ?? '' );
		$to   = self::path( $a['to'] ?? '' );
		if ( ! file_exists( $from ) ) {
			return array( 'ok' => false, 'error' => "$from does not exist." . ( self::outside_basedir( $from ) ? ' ' . self::outside_basedir( $from ) : '' ) );
		}
		self::mkdir( dirname( $to ) );
		$ok = @rename( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $ok && self::wpfs() ) {
			$ok = self::wpfs()->move( $from, $to );
		}
		return $ok ? array( 'from' => $from, 'to' => $to ) : array( 'ok' => false, 'error' => "Could not move $from to $to. " . ( self::cannot_write( $from, 'delete' ) ?: self::cannot_write( $to, 'create' ) ) );
	}

	// --- database --------------------------------------------------------------------------------

	public static function sql_read( array $a ) {
		global $wpdb;
		$sql = self::decode_arg( $a, 'sql' );
		if ( ! $sql ) {
			return array( 'ok' => false, 'error' => 'Give sql (or sql_base64).' );
		}
		$max = max( 1, min( 5000, (int) ( $a['max_rows'] ?? 500 ) ) );
		// One statement (wpdb never runs several), in a read-only session and
		// transaction: the database itself refuses any change, DDL included.
		$wpdb->query( 'SET SESSION TRANSACTION READ ONLY' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'START TRANSACTION READ ONLY' ); // phpcs:ignore WordPress.DB
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'SET SESSION TRANSACTION READ WRITE' ); // phpcs:ignore WordPress.DB
		if ( $error ) {
			return array( 'ok' => false, 'error' => $error );
		}
		$rows = is_array( $rows ) ? $rows : array();
		return array(
			'rows'      => array_slice( $rows, 0, $max ),
			'row_count' => count( $rows ),
			'truncated' => count( $rows ) > $max,
			'columns'   => $rows ? array_keys( $rows[0] ) : array(),
		);
	}

	/** The tables a statement changes, from the database's own plan (EXPLAIN). */
	public static function tables_of( $sql ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$plan     = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( $error || ! is_array( $plan ) ) {
			return null;
		}
		$tables = array();
		foreach ( $plan as $row ) {
			$t = $row['table'] ?? '';
			if ( '' === $t || '<' === $t[0] ) {
				continue;
			}
			$tables[ $t ] = true;
		}
		return array_keys( $tables );
	}

	/**
	 * sql_write's snapshot: the rows the statement changes, by key, read from
	 * the statement (Kico_Sql); whole tables for DDL and what cannot be told
	 * row by row; the tables the call names, whole, when it names them.
	 */
	public static function snapshot_sql( array $a, Kico_Snapshot $s ) {
		global $wpdb;
		$sql = (string) self::decode_arg( $a, 'sql' );
		if ( isset( $a['tables'] ) && is_array( $a['tables'] ) ) {
			foreach ( $a['tables'] as $t ) {
				$s->table( (string) $t, 'asked for' );
			}
			return;
		}
		if ( '' !== $sql ) {
			$s->statement( $sql, true );
		}
	}

	public static function sql_write( array $a, ?Kico_Snapshot $s = null ) {
		global $wpdb;
		$sql = self::decode_arg( $a, 'sql' );
		if ( ! $sql ) {
			if ( $s ) {
				$s->statement_done( 0, true );
			}
			return array( 'ok' => false, 'error' => 'Give sql (or sql_base64).' );
		}
		$suppress        = $wpdb->suppress_errors( true );
		$wpdb->insert_id = 0;
		$n               = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
		$error           = $wpdb->last_error;
		// wpdb counts and keeps the new id only for statements that start with INSERT, UPDATE,
		// DELETE or REPLACE ("/* note */ INSERT ..." does not): ask the connection right away.
		if ( false !== $n && ! $error && $wpdb->dbh instanceof mysqli ) {
			if ( ! $wpdb->insert_id ) {
				$wpdb->insert_id = (int) mysqli_insert_id( $wpdb->dbh );
			}
			if ( 0 === $n ) {
				$n = max( 0, (int) mysqli_affected_rows( $wpdb->dbh ) );
			}
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->suppress_errors( $suppress );
		if ( $s ) {
			$s->statement_done( false === $n ? null : (int) $n, false === $n || '' !== $error );
		}
		if ( false === $n || $error ) {
			return array( 'ok' => false, 'error' => $error ?: 'The statement failed.' );
		}
		return array( 'rows_affected' => (int) $n ) + ( $id > 0 ? array( 'insert_id' => $id ) : array() );
	}

	// --- PHP and WP-CLI --------------------------------------------------------------------------

	/**
	 * The snapshot of code Kico runs: recorded while it runs (files through
	 * the recorder and the manifest, rows through $wpdb), plus the tables the
	 * call names, whole. record: false records nothing.
	 */
	public static function snapshot_code( array $a, Kico_Snapshot $s ) {
		if ( isset( $a['tables'] ) && is_array( $a['tables'] ) && $a['tables'] ) {
			$s->database( array_map( 'strval', $a['tables'] ) );
		}
		if ( isset( $a['record'] ) && false === $a['record'] ) {
			$s->note( 'Nothing was recorded: the call asked for no recording (record: false).' );
			return;
		}
		$s->record       = true;
		$s->record_files = ! ( isset( $a['files'] ) && false === $a['files'] );
	}

	public static function php_eval( array $a ) {
		$code = self::decode_arg( $a, 'code' );
		if ( null === $code ) {
			return array( 'ok' => false, 'error' => 'Give code (or code_base64).' );
		}
		$code   = preg_replace( '/^\s*<\?php\s*/', '', $code );
		$errors = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			function ( $no, $str, $file, $line ) use ( &$errors ) {
				$errors[] = "$str ($file:$line)";
				return true;
			}
		);
		ob_start();
		$return = null;
		$thrown = null;
		try {
			$return = eval( $code ); // phpcs:ignore Squiz.PHP.Eval
		} catch ( Throwable $e ) {
			$thrown = get_class( $e ) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
		}
		$output = ob_get_clean();
		restore_error_handler();
		$out = array(
			'output' => Kico_Store::clip( (string) $output, 1024 * 1024 ),
			'return' => is_scalar( $return ) || is_array( $return ) || null === $return ? $return : print_r( $return, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		);
		if ( $errors ) {
			$out['warnings'] = array_slice( $errors, 0, 50 );
		}
		if ( $thrown ) {
			$out['ok']    = false;
			$out['error'] = $thrown;
		}
		return $out;
	}

	public static function wp_cli( array $a, ?Kico_Snapshot $s = null ) {
		$bin = self::wp_cli_binary();
		if ( empty( $bin['available'] ) ) {
			return array( 'ok' => false, 'error' => 'WP-CLI is not available on this host: ' . $bin['why'] );
		}
		$args = isset( $a['args'] ) && is_array( $a['args'] ) ? array_map( 'strval', $a['args'] ) : array();
		if ( ! $args ) {
			return array( 'ok' => false, 'error' => 'Give args, e.g. ["post", "list", "--post_type=page"].' );
		}
		$extra = array( '--path=' . ABSPATH, '--no-color' );
		// On multisite, the site this request is for (the command may name another with its own --url).
		$has_url = false;
		foreach ( $args as $x ) {
			$has_url = $has_url || 0 === strpos( $x, '--url=' );
		}
		if ( is_multisite() && ! $has_url ) {
			$extra[] = '--url=' . home_url( '/' );
		}
		// The recorder in WP-CLI's process: it writes what it kept to a file this process merges.
		$record = null;
		if ( $s && $s->record ) {
			$record  = tempnam( get_temp_dir(), 'kico-cli' );
			$extra[] = '--require=' . __DIR__ . '/kico-cli-recorder.php';
		}
		$env = null;
		if ( $record ) {
			$env = array_merge(
				getenv() ?: array(),
				array(
					'KICO_RECORD'       => $record,
					'KICO_RECORD_FILES' => $s->record_files ? '1' : '0',
					'KICO_RECORD_ROOM'  => (string) max( 0, Kico_Snapshot::max_bytes() - $s->bytes ),
				)
			);
		}
		$cmd = array_merge( array( $bin['path'] ), $args, $extra );
		$r   = self::exec( $cmd, max( 5, min( 600, (int) ( $a['timeout'] ?? 120 ) ) ), $env );
		if ( $record ) {
			$child = json_decode( (string) @file_get_contents( $record ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( $record ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $child ) ) {
				$s->merge( $child );
			} else {
				$s->note( 'WP-CLI\'s process recorded nothing (it ended before its shutdown, or ignored --require): its database changes are not in this snapshot; files it changed are named below when the manifest found them.' );
			}
		}
		return array(
			'exit_code' => $r['code'],
			'stdout'    => Kico_Store::clip( $r['stdout'], 1024 * 1024 ),
			'stderr'    => Kico_Store::clip( $r['stderr'], 64 * 1024 ),
		) + ( 0 === $r['code'] ? array() : array( 'ok' => false ) );
	}

	// --- content ---------------------------------------------------------------------------------

	private static function post_summary( WP_Post $p ) {
		return array(
			'id'        => $p->ID,
			'type'      => $p->post_type,
			'status'    => $p->post_status,
			'title'     => $p->post_title,
			'slug'      => $p->post_name,
			'parent'    => $p->post_parent,
			'modified'  => $p->post_modified_gmt,
			'link'      => get_permalink( $p ),
			'elementor' => 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true ),
			'blocks'    => has_blocks( $p->post_content ),
		);
	}

	public static function posts_query( array $a ) {
		$q = new WP_Query(
			array(
				'post_type'      => $a['post_type'] ?? 'any',
				'post_status'    => $a['status'] ?? 'any',
				's'              => $a['search'] ?? '',
				'name'           => $a['slug'] ?? '',
				'posts_per_page' => max( 1, min( 200, (int) ( $a['per_page'] ?? 20 ) ) ),
				'paged'          => max( 1, (int) ( $a['page'] ?? 1 ) ),
				'orderby'        => $a['orderby'] ?? 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);
		return array( 'total' => (int) $q->found_posts, 'posts' => array_map( array( __CLASS__, 'post_summary' ), $q->posts ) );
	}

	public static function post_get( array $a ) {
		$p = get_post( (int) ( $a['id'] ?? 0 ) );
		if ( ! $p ) {
			return array( 'ok' => false, 'error' => 'No post with that id.' );
		}
		$meta = array();
		foreach ( get_post_meta( $p->ID ) as $k => $values ) {
			$meta[ $k ] = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		if ( isset( $meta['_elementor_data'] ) && empty( $a['full_meta'] ) ) {
			$meta['_elementor_data'] = sprintf( '[%d bytes of Elementor data; use elementor_get]', strlen( (string) $meta['_elementor_data'] ) );
		}
		return self::post_summary( $p ) + array(
			'content' => $p->post_content,
			'excerpt' => $p->post_excerpt,
			'author'  => (int) $p->post_author,
			'date'    => $p->post_date_gmt,
			'meta'    => $meta,
			'terms'   => wp_get_object_terms( $p->ID, get_object_taxonomies( $p->post_type ), array( 'fields' => 'all' ) ),
		);
	}

	public static function post_save( array $a, ?Kico_Snapshot $s = null ) {
		$id     = (int) ( $a['id'] ?? 0 );
		$fields = array();
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'post_type' => 'post_type', 'parent' => 'post_parent', 'menu_order' => 'menu_order' ) as $in => $col ) {
			if ( array_key_exists( $in, $a ) ) {
				$fields[ $col ] = $a[ $in ];
			}
		}
		if ( $id ) {
			if ( ! get_post( $id ) ) {
				return array( 'ok' => false, 'error' => "No post $id." );
			}
			$fields['ID'] = $id;
			$r            = wp_update_post( wp_slash( $fields ), true );
		} else {
			$r = wp_insert_post( wp_slash( $fields + array( 'post_status' => 'draft' ) ), true );
			if ( ! is_wp_error( $r ) && $s ) {
				$s->created( 'post', $r );
			}
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( (array) ( $a['meta'] ?? array() ) as $k => $v ) {
			null === $v ? delete_post_meta( $r, $k ) : update_post_meta( $r, $k, wp_slash( $v ) );
		}
		return self::post_summary( get_post( $r ) );
	}

	public static function post_delete( array $a ) {
		$id = (int) ( $a['id'] ?? 0 );
		$r  = ! empty( $a['force'] ) ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		return $r ? array( 'deleted' => $id, 'trashed' => empty( $a['force'] ) ) : array( 'ok' => false, 'error' => "Could not delete post $id." );
	}

	public static function media_upload( array $a, ?Kico_Snapshot $s = null ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name = sanitize_file_name( (string) ( $a['filename'] ?? '' ) );
		if ( ! empty( $a['url'] ) ) {
			$tmp = download_url( esc_url_raw( (string) $a['url'] ), 120 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			if ( '' === $name ) {
				$name = sanitize_file_name( basename( (string) wp_parse_url( (string) $a['url'], PHP_URL_PATH ) ) );
			}
		} else {
			$data = self::decode_arg( $a, 'content' );
			if ( null === $data || '' === $name ) {
				return array( 'ok' => false, 'error' => 'Give filename and content_base64, or url.' );
			}
			$tmp = wp_tempnam( $name );
			file_put_contents( $tmp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), (int) ( $a['post_id'] ?? 0 ), $a['title'] ?? null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $id;
		}
		if ( $s ) {
			$s->created( 'attachment', $id );
		}
		if ( isset( $a['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $a['alt'] ) );
		}
		return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'mime' => get_post_mime_type( $id ), 'sizes' => array_keys( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) ) );
	}

	public static function options_get( array $a ) {
		$out = array();
		foreach ( (array) ( $a['names'] ?? array() ) as $n ) {
			$out[ (string) $n ] = get_option( (string) $n, null );
		}
		return array( 'options' => $out );
	}

	public static function snapshot_options( array $a, Kico_Snapshot $s ) {
		global $wpdb;
		$names = array_keys( (array) ( $a['values'] ?? array() ) );
		if ( $names ) {
			$list = implode( ',', array_map( function ( $n ) use ( $wpdb ) {
				return "'" . $wpdb->_real_escape( (string) $n ) . "'";
			}, $names ) );
			$s->rows( $wpdb->options, "option_name IN ($list)" );
		}
	}

	public static function options_update( array $a ) {
		$done = array();
		foreach ( (array) ( $a['values'] ?? array() ) as $name => $value ) {
			$done[ $name ] = null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		return array( 'updated' => $done );
	}

	// --- abilities of other plugins ----------------------------------------------------------------

	public static function abilities_list( array $a ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array( 'ok' => false, 'error' => 'This WordPress has no Abilities API (6.9 or later has it).' );
		}
		$out = array();
		foreach ( wp_get_abilities() as $ab ) {
			if ( ! empty( $a['category'] ) && $ab->get_category() !== $a['category'] ) {
				continue;
			}
			if ( 0 === strpos( $ab->get_name(), 'kico/' ) ) {
				continue;
			}
			$meta  = $ab->get_meta();
			$out[] = array(
				'name'          => $ab->get_name(),
				'label'         => $ab->get_label(),
				'description'   => $ab->get_description(),
				'category'      => $ab->get_category(),
				'input_schema'  => $ab->get_input_schema(),
				'output_schema' => $ab->get_output_schema(),
				'annotations'   => $meta['annotations'] ?? null,
			);
		}
		return array( 'abilities' => $out );
	}

	public static function ability_readonly( $name ) {
		$ab = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $name ) : null;
		$m  = $ab ? $ab->get_meta() : array();
		return ! empty( $m['annotations']['readonly'] ) && empty( $m['annotations']['destructive'] );
	}

	public static function abilities_run( array $a ) {
		$ab = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) ( $a['name'] ?? '' ) ) : null;
		if ( ! $ab ) {
			return array( 'ok' => false, 'error' => 'No ability ' . ( $a['name'] ?? '' ) . '; abilities_list shows them.' );
		}
		$r = $ab->execute( $a['input'] ?? null );
		return is_wp_error( $r ) ? $r : array( 'result' => $r );
	}

	// --- Elementor -------------------------------------------------------------------------------

	private static function elementor_data( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private static function outline( array $elements, $path = '' ) {
		$out = array();
		foreach ( $elements as $i => $el ) {
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$text     = '';
			foreach ( array( 'title', 'editor', 'text', 'heading', 'description', 'button_text' ) as $k ) {
				if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
					$text = wp_strip_all_tags( $settings[ $k ] );
					break;
				}
			}
			$out[] = array(
				'id'       => $el['id'] ?? '',
				'path'     => ltrim( $path . '.' . $i, '.' ),
				'type'     => $el['elType'] ?? '',
				'widget'   => $el['widgetType'] ?? null,
				'text'     => mb_substr( $text, 0, 80 ),
				'settings' => array_keys( $settings ),
				'children' => isset( $el['elements'] ) && is_array( $el['elements'] ) ? self::outline( $el['elements'], ltrim( $path . '.' . $i, '.' ) ) : array(),
			);
		}
		return $out;
	}

	private static function &find( array &$elements, $id ) {
		$none = null;
		foreach ( $elements as &$el ) {
			if ( ( $el['id'] ?? null ) === $id ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) ) {
				$hit = &self::find( $el['elements'], $id );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return $none;
	}

	private static function remove( array &$elements, $id ) {
		foreach ( $elements as $i => &$el ) {
			if ( ( $el['id'] ?? null ) === $id ) {
				array_splice( $elements, $i, 1 );
				return true;
			}
			if ( ! empty( $el['elements'] ) && self::remove( $el['elements'], $id ) ) {
				return true;
			}
		}
		return false;
	}

	public static function elementor_get( array $a ) {
		$id = (int) ( $a['post_id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return array( 'ok' => false, 'error' => "No post $id." );
		}
		$data = self::elementor_data( $id );
		$base = array( 'post_id' => $id, 'edit_mode' => get_post_meta( $id, '_elementor_edit_mode', true ), 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null );
		if ( ! empty( $a['element_id'] ) ) {
			$el = &self::find( $data, (string) $a['element_id'] );
			return null === $el ? array( 'ok' => false, 'error' => 'No element ' . $a['element_id'] . ' on this page.' ) + $base : $base + array( 'element' => $el );
		}
		return $base + ( ! empty( $a['full'] ) ? array( 'elements' => $data ) : array( 'outline' => self::outline( $data ), 'note' => 'Outline only; pass element_id for one element, or full: true for everything.' ) );
	}

	public static function elementor_patch( array $a ) {
		$id = (int) ( $a['post_id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return array( 'ok' => false, 'error' => "No post $id." );
		}
		$data = self::elementor_data( $id );
		$eid  = (string) ( $a['element_id'] ?? '' );
		if ( ! empty( $a['remove'] ) ) {
			if ( ! self::remove( $data, $eid ) ) {
				return array( 'ok' => false, 'error' => "No element $eid on this page." );
			}
		} elseif ( ! empty( $a['insert'] ) && is_array( $a['insert'] ) ) {
			$new = $a['insert']['element'] ?? null;
			if ( ! is_array( $new ) ) {
				return array( 'ok' => false, 'error' => 'insert needs element (the Elementor element JSON).' );
			}
			if ( empty( $new['id'] ) ) {
				$new['id'] = substr( md5( wp_generate_uuid4() ), 0, 7 );
			}
			$pos = isset( $a['insert']['position'] ) ? (int) $a['insert']['position'] : PHP_INT_MAX;
			if ( empty( $a['insert']['parent_id'] ) ) {
				array_splice( $data, min( $pos, count( $data ) ), 0, array( $new ) );
			} else {
				$parent = &self::find( $data, (string) $a['insert']['parent_id'] );
				if ( null === $parent ) {
					return array( 'ok' => false, 'error' => 'No parent element ' . $a['insert']['parent_id'] . '.' );
				}
				$parent['elements'] = isset( $parent['elements'] ) ? $parent['elements'] : array();
				array_splice( $parent['elements'], min( $pos, count( $parent['elements'] ) ), 0, array( $new ) );
			}
			$eid = $new['id'];
		} else {
			$el = &self::find( $data, $eid );
			if ( null === $el ) {
				return array( 'ok' => false, 'error' => "No element $eid on this page." );
			}
			if ( isset( $a['element'] ) && is_array( $a['element'] ) ) {
				$el = $a['element'] + array( 'id' => $eid );
			}
			if ( isset( $a['settings'] ) && is_array( $a['settings'] ) ) {
				$el['settings'] = array_merge( isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array(), $a['settings'] );
				foreach ( $a['settings'] as $k => $v ) {
					if ( null === $v ) {
						unset( $el['settings'][ $k ] );
					}
				}
			}
			unset( $el );
		}
		$via = 'meta';
		if ( self::elementor_active() ) {
			// Elementor's own save: revisions, post_content for search and
			// no-JS, and its CSS stay right.
			$doc = \Elementor\Plugin::$instance->documents->get( $id, false );
			if ( $doc ) {
				$doc->save( array( 'elements' => $data ) );
				$via = 'elementor';
			}
		}
		if ( 'meta' === $via ) {
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		}
		$css = self::elementor_flush( $id );
		return array( 'post_id' => $id, 'element_id' => $eid, 'saved_via' => $via, 'css_regenerated' => $css, 'link' => get_permalink( $id ) );
	}

	public static function css_regenerate( array $a ) {
		if ( ! self::elementor_active() ) {
			return array( 'ok' => false, 'error' => 'Elementor is not active on this site.' );
		}
		$id = (int) ( $a['post_id'] ?? 0 );
		return array( 'regenerated' => self::elementor_flush( $id ), 'scope' => $id ? "post $id" : 'all' );
	}

	// --- blocks ----------------------------------------------------------------------------------

	private static function block_tree( array $blocks, $path = '' ) {
		$out = array();
		foreach ( $blocks as $i => $b ) {
			if ( null === $b['blockName'] && '' === trim( (string) $b['innerHTML'] ) ) {
				continue;
			}
			$p     = ltrim( $path . '.' . $i, '.' );
			$out[] = array(
				'path'      => $p,
				'name'      => $b['blockName'],
				'attrs'     => $b['attrs'],
				'innerHTML' => $b['innerHTML'],
				'inner'     => self::block_tree( $b['innerBlocks'], $p ),
			);
		}
		return $out;
	}

	public static function blocks_get( array $a ) {
		$p = get_post( (int) ( $a['post_id'] ?? 0 ) );
		if ( ! $p ) {
			return array( 'ok' => false, 'error' => 'No such post.' );
		}
		return array( 'post_id' => $p->ID, 'blocks' => self::block_tree( parse_blocks( $p->post_content ) ) );
	}

	public static function blocks_patch( array $a ) {
		$p = get_post( (int) ( $a['post_id'] ?? 0 ) );
		if ( ! $p ) {
			return array( 'ok' => false, 'error' => 'No such post.' );
		}
		$blocks = parse_blocks( $p->post_content );
		$keys   = array_map( 'intval', explode( '.', (string) ( $a['path'] ?? '' ) ) );
		$list   = &$blocks;
		$last   = array_pop( $keys );
		foreach ( $keys as $k ) {
			if ( ! isset( $list[ $k ] ) ) {
				return array( 'ok' => false, 'error' => 'No block at path ' . $a['path'] . '.' );
			}
			$list = &$list[ $k ]['innerBlocks'];
		}
		if ( ! empty( $a['insert'] ) ) {
			$new = parse_blocks( (string) $a['insert'] );
			array_splice( $list, $last, 0, $new );
		} elseif ( ! isset( $list[ $last ] ) ) {
			return array( 'ok' => false, 'error' => 'No block at path ' . $a['path'] . '.' );
		} elseif ( ! empty( $a['remove'] ) ) {
			array_splice( $list, $last, 1 );
		} elseif ( isset( $a['block'] ) ) {
			array_splice( $list, $last, 1, parse_blocks( (string) $a['block'] ) );
		} elseif ( isset( $a['attrs'] ) && is_array( $a['attrs'] ) ) {
			$list[ $last ]['attrs'] = array_merge( (array) $list[ $last ]['attrs'], $a['attrs'] );
		} else {
			return array( 'ok' => false, 'error' => 'Give attrs, block (markup), insert (markup) or remove.' );
		}
		unset( $list );
		$r = wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => serialize_blocks( $blocks ) ) ), true );
		return is_wp_error( $r ) ? $r : array( 'post_id' => $p->ID, 'link' => get_permalink( $p->ID ) );
	}

	// --- caches and checks -----------------------------------------------------------------------

	public static function cache_warm( array $a ) {
		return Kico_Cache::warm( $a );
	}

	public static function cache_purge( array $a ) {
		return Kico_Cache::cache_purge( $a );
	}

	public static function cache_check( array $a ) {
		return Kico_Cache::check( $a );
	}

	/** After every write and rollback (Kico_Snapshot::end). */
	public static function after_write( $tool, array $posts, $changed = true ) {
		return Kico_Cache::after_write( $tool, $posts, $changed );
	}

	public static function op_status( array $a ) {
		$op = Kico_Transport::op_info( strtolower( (string) ( $a['op_id'] ?? '' ) ) );
		return is_wp_error( $op ) ? array( 'ok' => false, 'error' => $op->get_error_message(), 'code' => $op->get_error_code() ) : $op;
	}

	public static function audit_log( array $a ) {
		return Kico_Store::audit_list( (int) ( $a['limit'] ?? 50 ), (string) ( $a['op_id'] ?? '' ) );
	}

	public static function rollback( array $a ) {
		$snap = $a['snapshot'] ?? null;
		if ( ! is_array( $snap ) ) {
			return array( 'ok' => false, 'error' => 'Kico adds the snapshot of op_id when the call goes through Kico; this call came without one.' );
		}
		// Putting this plugin's own folder back replaces the code that runs now: load what the answer needs first.
		$self = false;
		foreach ( (array) ( $snap['archives'] ?? array() ) as $ar ) {
			if ( isset( $ar['path'] ) && wp_normalize_path( Kico_Snapshot::abs( (string) $ar['path'] ) ) === wp_normalize_path( KICO_WP_DIR ) ) {
				$self = true;
			}
		}
		if ( $self ) {
			Kico_Updates::preload();
		}
		$r = Kico_Snapshot::restore( $snap, array( 'partial' => ! empty( $a['partial'] ) ) );
		if ( $self ) {
			$r['plugin_version'] = Kico_Updates::note_replaced();
		}
		return array( 'rolled_back' => (string) ( $a['op_id'] ?? '' ) ) + $r;
	}
}
