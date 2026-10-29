<?php
/**
 * Page caches: which the site has (env), purging them after every write and
 * rollback (Kico_Snapshot::end) and on request (cache_purge), loading the
 * site's own pages (cache_warm), and the caches in front of WordPress
 * (nginx, Varnish, LiteSpeed, CDNs): seen from a visitor's response headers,
 * refreshed after writes, and checked as a visitor (cache_check).
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Cache {

	/** Tools that change posts only: their pages and the home page are purged, not the whole site. */
	const POST_TOOLS = array( 'post_save', 'post_delete', 'elementor_patch', 'blocks_patch', 'media_upload', 'css_regenerate' );

	public static function boot() {
		// The preview after a write (Kico adds ?kico_op=<id>): never served from or stored in a page cache.
		if ( isset( $_GET['kico_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			add_action(
				'send_headers',
				function () {
					header( 'Cache-Control: no-store, max-age=0' );
				}
			);
		}
	}

	/** The page and object caches this site has (plugins, drop-ins, host markers). */
	public static function detect() {
		$have = array(
			'WP Super Cache'       => function_exists( 'wp_cache_clear_cache' ) || function_exists( 'prune_super_cache' ),
			'W3 Total Cache'       => function_exists( 'w3tc_flush_all' ),
			'WP Rocket'            => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'      => defined( 'LSCWP_V' ),
			'WP Fastest Cache'     => isset( $GLOBALS['wp_fastest_cache'] ),
			'Cache Enabler'        => class_exists( 'Cache_Enabler' ),
			'Comet Cache'          => class_exists( 'comet_cache' ),
			'Hummingbird'          => defined( 'WPHB_VERSION' ),
			'Breeze'               => defined( 'BREEZE_VERSION' ),
			'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
			'Nginx Helper'         => defined( 'NGINX_HELPER_BASEPATH' ),
			'Proxy Cache Purge'    => class_exists( 'VarnishPurger' ),
			'Autoptimize'          => class_exists( 'autoptimizeCache' ),
			'WP Engine'            => class_exists( 'WpeCommon' ),
			'Pantheon'             => function_exists( 'pantheon_wp_clear_edge_all' ),
			'Kinsta'               => defined( 'KINSTAMU_VERSION' ),
			'Cloudflare'           => defined( 'CLOUDFLARE_PLUGIN_DIR' ),
		);
		$page = array();
		foreach ( $have as $name => $yes ) {
			if ( $yes ) {
				$page[] = $name;
			}
		}
		$object = null;
		if ( wp_using_ext_object_cache() ) {
			$object = 'yes';
			$dropin = WP_CONTENT_DIR . '/object-cache.php';
			if ( is_file( $dropin ) ) {
				$head   = (string) @file_get_contents( $dropin, false, null, 0, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				$object = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : 'object-cache.php';
			}
		}
		return array(
			'page_cache_plugins' => $page,
			'advanced_cache'     => is_file( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'WP_CACHE'           => defined( 'WP_CACHE' ) && WP_CACHE,
			'object_cache'       => $object,
			'after_writes'       => 'Every write and rollback purges these page caches (the post and the home page for post tools, else the whole site) and the object cache entries it changed, then refreshes the cache in front of WordPress (in_front) for the home page and the posts it changed, and says in page_cache_in_front whether a visitor now gets the fresh page. Verify what visitors see with cache_check, never with a cache-busting query.',
			'in_front'           => self::front_summary( self::front( true ) ),
		);
	}

	/**
	 * Purges the page caches the site has: the posts' pages and the home page
	 * when post ids are given, else everything. Returns what was purged.
	 *
	 * @param int[]|null $post_ids Posts written, or null for the whole site.
	 * @return string[]
	 */
	public static function purge( $post_ids = null ) {
		$done  = array();
		$posts = null === $post_ids ? null : array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
		if ( array() === $posts ) {
			$posts = null;
		}
		$try = function ( $name, callable $fn ) use ( &$done ) {
			try {
				$fn();
				$done[] = $name;
			} catch ( Throwable $e ) {
				$done[] = $name . ' (failed: ' . $e->getMessage() . ')';
			}
		};
		$home = home_url( '/' );
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$try(
				'WP Super Cache',
				function () use ( $posts, $home ) {
					if ( $posts && function_exists( 'wp_cache_post_change' ) ) {
						foreach ( $posts as $id ) {
							wp_cache_post_change( $id );
						}
						if ( function_exists( 'wpsc_delete_url_cache' ) ) {
							wpsc_delete_url_cache( $home );
						}
					} else {
						wp_cache_clear_cache( is_multisite() ? get_current_blog_id() : 0 );
					}
				}
			);
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$try(
				'W3 Total Cache',
				function () use ( $posts, $home ) {
					if ( $posts && function_exists( 'w3tc_flush_post' ) ) {
						foreach ( $posts as $id ) {
							w3tc_flush_post( $id );
						}
						if ( function_exists( 'w3tc_flush_url' ) ) {
							w3tc_flush_url( $home );
						}
					} else {
						w3tc_flush_all();
					}
					// W3TC purges at shutdown, after the answer is sent; Kico reloads the preview at once.
					if ( class_exists( '\\W3TC\\Dispatcher' ) ) {
						$flush = \W3TC\Dispatcher::component( 'CacheFlush' );
						if ( $flush && method_exists( $flush, 'execute_delayed_operations' ) ) {
							$flush->execute_delayed_operations();
						}
					}
					// Disk: Enhanced keeps a purged page as <page>_old and serves it for 30 s more.
					self::drop_old( defined( 'W3TC_CACHE_PAGE_ENHANCED_DIR' ) ? W3TC_CACHE_PAGE_ENHANCED_DIR : WP_CONTENT_DIR . '/cache/page_enhanced' );
				}
			);
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$try(
				'WP Rocket',
				function () use ( $posts ) {
					if ( $posts && function_exists( 'rocket_clean_post' ) ) {
						foreach ( $posts as $id ) {
							rocket_clean_post( $id );
						}
						if ( function_exists( 'rocket_clean_home' ) ) {
							rocket_clean_home();
						}
					} else {
						rocket_clean_domain();
					}
				}
			);
		}
		if ( defined( 'LSCWP_V' ) ) {
			$try(
				'LiteSpeed Cache',
				function () use ( $posts, $home ) {
					if ( $posts ) {
						foreach ( $posts as $id ) {
							do_action( 'litespeed_purge_post', $id );
						}
						do_action( 'litespeed_purge_url', $home );
					} else {
						do_action( 'litespeed_purge_all' );
					}
				}
			);
		}
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$try(
				'WP Fastest Cache',
				function () {
					$GLOBALS['wp_fastest_cache']->deleteCache( true );
				}
			);
		}
		foreach ( array(
			'Cache Enabler'     => 'cache_enabler_clear_complete_cache',
			'Hummingbird'       => 'wphb_clear_page_cache',
			'Breeze'            => 'breeze_clear_all_cache',
			'Nginx Helper'      => 'rt_nginx_helper_purge_all',
			'Proxy Cache Purge' => 'vhp_purge_all',
		) as $name => $hook ) {
			if ( has_action( $hook ) ) {
				$try(
					$name,
					function () use ( $hook ) {
						do_action( $hook );
					}
				);
			}
		}
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			$try(
				'Comet Cache',
				function () {
					comet_cache::clear();
				}
			);
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$try(
				'SiteGround Optimizer',
				function () {
					sg_cachepress_purge_cache();
				}
			);
		}
		if ( class_exists( 'WpeCommon' ) ) {
			$try(
				'WP Engine',
				function () {
					if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
						WpeCommon::purge_varnish_cache();
					}
					if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
						WpeCommon::purge_memcached();
					}
				}
			);
		}
		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
			$try(
				'Pantheon',
				function () {
					pantheon_wp_clear_edge_all();
				}
			);
		}
		/**
		 * After Kico purged the page caches it knows, for hosts and plugins it does not.
		 *
		 * @param int[]|null $posts The posts written, or null for the whole site.
		 */
		do_action( 'kico_purge_caches', $posts );
		return $done;
	}

	/** Deletes the stale copies (*_old) a page cache keeps under a folder (bounded: 2 s). */
	private static function drop_old( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$start = microtime( true );
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( '_old' === substr( $f->getFilename(), -4 ) ) {
				@unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
			if ( microtime( true ) - $start > 2 ) {
				break;
			}
		}
	}

	/**
	 * After every write and rollback: the page cache plugins, then the caches
	 * in front of WordPress for the pages the change shows on. Returns what to
	 * add to the result.
	 *
	 * @param string $tool    The tool that wrote.
	 * @param int[]  $posts   Posts it changed.
	 * @param bool   $changed Whether its snapshot recorded a change. Code that recorded none (a read, or record: false) is not refreshed in front; other tools always are.
	 */
	public static function after_write( $tool, array $posts, $changed = true ) {
		$changed   = $changed || ! in_array( $tool, array( 'php_eval', 'wp_cli', 'abilities_run' ), true );
		$post_tool = in_array( $tool, self::POST_TOOLS, true ) && $posts;
		$done      = self::purge( $post_tool ? $posts : null );
		$out       = $done ? array( 'cache_purged' => $done ) : array();
		try {
			$front = $changed ? self::refresh_front( self::urls_for( $posts ), false, ! $post_tool ) : self::front_unchanged();
		} catch ( Throwable $e ) {
			$front = array( 'error' => 'Refreshing the cache in front failed: ' . $e->getMessage() );
		}
		if ( $front ) {
			$out['page_cache_in_front'] = $front;
		}
		return $out;
	}

	public static function cache_purge( array $a ) {
		$ids = isset( $a['post_ids'] ) && is_array( $a['post_ids'] ) && $a['post_ids'] ? $a['post_ids'] : null;
		if ( ! empty( $a['object_cache'] ) ) {
			wp_cache_flush();
		}
		$done  = self::purge( $ids );
		$given = array();
		$wrong = array();
		foreach ( (array) ( $a['urls'] ?? array() ) as $u ) {
			$u = self::own_url( $u );
			if ( self::same_site( $u ) ) {
				$given[] = $u;
			} elseif ( '' !== $u ) {
				$wrong[] = $u;
			}
		}
		$urls = array_merge( $ids ? self::urls_for( $ids ) : ( $given ? array() : array( home_url( '/' ) ) ), $given );
		$out  = array(
			'purged'       => $done,
			'scope'        => $ids ? 'posts ' . implode( ', ', array_map( 'intval', $ids ) ) . ' and the home page' : 'the whole site',
			'object_cache' => ! empty( $a['object_cache'] ) ? 'flushed' : 'kept',
		);
		if ( ! $done ) {
			$out['note'] = 'No page cache plugin the plugin knows is active; page_cache_in_front is what sits in front of WordPress.';
		}
		$out['page_cache_in_front'] = self::refresh_front( $urls, true, ! $ids && ! $given, self::MAX_URLS );
		if ( $wrong ) {
			$out['not_this_site'] = $wrong;
		}
		return $out;
	}

	// --- caches in front of WordPress: nginx, Varnish, LiteSpeed, CDNs -------------------------------

	/** A plain visitor's browser: Chrome on Windows. */
	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/**
	 * Accept-Encoding and Sec-Fetch-Dest as visitors send them. A cache that
	 * varies on them (nginx keeps one copy per value of each header the
	 * page's Vary names) holds a copy for each: browsers (with and without
	 * zstd), Kico's preview and other fetches (gzip, deflate), as a page and
	 * in a frame.
	 */
	const ENCODINGS = array( 'gzip, deflate, br, zstd', 'gzip, deflate, br', 'gzip, deflate' );
	const DESTS     = array( 'document', 'iframe', '' );

	/**
	 * Asks a cache in front for a fresh copy, which it then stores: X-Update
	 * for HestiaCP's nginx caching templates (proxy_cache_bypass
	 * $no_cache $cookie_session $http_x_update, with proxy_no_cache only for
	 * $no_cache, so the bypassed answer replaces the cached one), Cache-Control
	 * and Pragma for caches set up to bypass on them.
	 */
	const REFRESH = array( 'X-Update' => '1', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' );

	/** Pages refreshed per call: after a write, and at most for cache_purge. */
	const AFTER_WRITE_URLS = 8;
	const MAX_URLS         = 20;

	/** Visitor copies fetched to check a refresh: every copy of the first pages, then one per page. */
	const FULL_CHECKS = 27;

	/** How long what the home page showed is kept before it is fetched again after a write (env and cache_purge always fetch). */
	const FRONT_TTL = 600;

	/** A visitor's request headers: a browser's, no cookies. */
	public static function visitor_headers( $encoding = 'gzip, deflate, br, zstd', $dest = 'document' ) {
		$h = array(
			'User-Agent'      => self::UA,
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Accept-Encoding' => $encoding,
		);
		if ( '' !== $dest ) {
			$h['Sec-Fetch-Dest'] = $dest;
			$h['Sec-Fetch-Mode'] = 'navigate';
			$h['Sec-Fetch-Site'] = 'iframe' === $dest ? 'cross-site' : 'none';
		}
		return $h;
	}

	/**
	 * The visitor copies a cache that follows Vary keeps of a page: request
	 * headers for each, a browser's page first.
	 *
	 * @param string[] $vary Header names of the page's Vary.
	 * @return array[]
	 */
	public static function variants( array $vary ) {
		$vary  = array_map( 'strtolower', $vary );
		$encs  = in_array( 'accept-encoding', $vary, true ) ? self::ENCODINGS : array( self::ENCODINGS[0] );
		$dests = in_array( 'sec-fetch-dest', $vary, true ) ? self::DESTS : array( self::DESTS[0] );
		$out   = array();
		foreach ( $dests as $d ) {
			foreach ( $encs as $e ) {
				$out[] = self::visitor_headers( $e, $d );
			}
		}
		return $out;
	}

	/**
	 * What a response's headers say about caches in front of WordPress.
	 *
	 * @param array $headers Header name => value (a list for repeated headers).
	 * @return array caches (each: cache, kind, evidence, hit), from_cache
	 *               (true, false or null: unknown), age, ttl (what the site's
	 *               Cache-Control allows shared caches), vary, headers.
	 */
	public static function read_headers( array $headers ) {
		$h = array();
		foreach ( $headers as $k => $v ) {
			$h[ strtolower( (string) $k ) ] = trim( is_array( $v ) ? implode( ', ', $v ) : (string) $v );
		}
		$get    = function ( $n ) use ( $h ) {
			return isset( $h[ $n ] ) && '' !== $h[ $n ] ? $h[ $n ] : null;
		};
		$status = function ( $v ) {
			$t = preg_split( '/[\s,;:]+/', strtoupper( (string) $v ) );
			if ( array_intersect( $t, array( 'HIT', 'STALE', 'UPDATING', 'REVALIDATED' ) ) ) {
				return true;
			}
			return array_intersect( $t, array( 'MISS', 'EXPIRED', 'BYPASS', 'DYNAMIC', 'PASS', 'NONE', 'UNCACHEABLE', 'NO-CACHE' ) ) ? false : null;
		};
		$caches = array();
		$add    = function ( $cache, $kind, $evidence, $hit ) use ( &$caches ) {
			$caches[ $kind ] = array( 'cache' => $cache, 'kind' => $kind, 'evidence' => $evidence, 'hit' => $hit );
		};
		$server = strtolower( (string) $get( 'server' ) );
		$via    = $get( 'via' );

		if ( null !== ( $v = $get( 'cf-cache-status' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$add( 'Cloudflare', 'cloudflare', 'CF-Cache-Status: ' . $v, $status( $v ) );
		} elseif ( null !== $get( 'cf-ray' ) ) {
			$add( 'Cloudflare', 'cloudflare', 'CF-Ray (Cloudflare proxies the site; no CF-Cache-Status, so it did not cache this page)', false );
		}
		if ( null !== ( $v = $get( 'x-varnish' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			// One id: a miss; two (this request's and the cached object's): a hit.
			$add( 'Varnish', 'varnish', 'X-Varnish: ' . $v, preg_match( '/^\d+\s+\d+$/', $v ) ? true : ( preg_match( '/^\d+$/', $v ) ? false : null ) );
		} elseif ( $via && false !== stripos( $via, 'varnish' ) ) {
			$add( 'Varnish', 'varnish', 'Via: ' . $via, null );
		}
		if ( null !== ( $v = $get( 'x-litespeed-cache' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$add( 'LiteSpeed', 'litespeed', 'X-LiteSpeed-Cache: ' . $v, $status( $v ) );
		}
		foreach ( array(
			'x-nginx-cache'   => array( 'X-Nginx-Cache', 'nginx proxy cache', 'nginx' ),
			'x-proxy-cache'   => array( 'X-Proxy-Cache', 'nginx proxy cache', 'nginx' ),
			'x-cache-status'  => array( 'X-Cache-Status', 'nginx proxy cache', 'nginx' ),
			'x-fastcgi-cache' => array( 'X-FastCGI-Cache', 'nginx FastCGI cache', 'fastcgi' ),
			'x-kinsta-cache'  => array( 'X-Kinsta-Cache', 'Kinsta', 'host' ),
			'x-sucuri-cache'  => array( 'X-Sucuri-Cache', 'Sucuri', 'cdn' ),
			'x-qc-cache'      => array( 'X-QC-Cache', 'QUIC.cloud', 'cdn' ),
		) as $n => $d ) {
			if ( null !== ( $v = $get( $n ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				// X-Proxy-Cache and X-Cache-Status are nginx's names by custom only.
				$add( 'x-nginx-cache' !== $n && 'nginx proxy cache' === $d[1] && false === strpos( $server, 'nginx' ) ? 'a proxy cache' : $d[1], $d[2], $d[0] . ': ' . $v, $status( $v ) );
			}
		}
		if ( null !== ( $v = $get( 'x-cache' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$hit = $status( $v );
			if ( isset( $caches['varnish'] ) ) {
				$caches['varnish']['evidence'] .= ', X-Cache: ' . $v;
				$caches['varnish']['hit']       = null === $caches['varnish']['hit'] ? $hit : $caches['varnish']['hit'];
			} elseif ( false !== stripos( $v, 'cloudfront' ) ) {
				$add( 'CloudFront', 'cdn', 'X-Cache: ' . $v, $hit );
			} elseif ( null !== $get( 'x-served-by' ) || null !== $get( 'fastly-debug-digest' ) ) {
				$add( 'Fastly', 'cdn', 'X-Cache: ' . $v, $hit );
			} elseif ( ! isset( $caches['nginx'] ) ) {
				// HestiaCP's caching templates and many nginx setups: add_header X-Cache $upstream_cache_status.
				$add( false !== strpos( $server, 'nginx' ) ? 'nginx proxy cache' : 'a proxy cache', 'nginx', 'X-Cache: ' . $v, $hit );
			}
		}
		$age = null !== $get( 'age' ) && is_numeric( $get( 'age' ) ) ? (int) $get( 'age' ) : null;
		if ( $age && ! $caches ) {
			$add( 'a shared cache', 'proxy', 'Age: ' . $age, true );
		}
		if ( $via && ! $caches ) {
			$add( 'a proxy', 'proxy', 'Via: ' . $via, null );
		}
		$from = null;
		foreach ( $caches as $c ) {
			if ( true === $c['hit'] ) {
				$from = true;
			} elseif ( false === $c['hit'] && null === $from ) {
				$from = false;
			}
		}
		if ( $age ) {
			$from = true;
		}
		$ttl = null;
		$cc  = strtolower( (string) $get( 'cache-control' ) );
		if ( preg_match( '/s-maxage=(\d+)/', $cc, $m ) || ( false === strpos( $cc, 'private' ) && false === strpos( $cc, 'no-store' ) && preg_match( '/(?<![-\w])max-age=(\d+)/', $cc, $m ) ) ) {
			$ttl = (int) $m[1] ? (int) $m[1] : null;
		}
		$vary = array();
		foreach ( preg_split( '/\s*,\s*/', (string) $get( 'vary' ) ) as $n ) {
			if ( '' !== $n ) {
				$vary[ strtolower( $n ) ] = true;
			}
		}
		$keep = array();
		foreach ( array( 'server', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'cf-cache-status', 'cf-ray', 'x-varnish', 'x-litespeed-cache', 'x-kinsta-cache', 'x-sucuri-cache', 'x-qc-cache', 'x-served-by', 'via', 'age', 'cache-control', 'expires', 'vary' ) as $n ) {
			if ( null !== $get( $n ) ) {
				$keep[ $n ] = $get( $n );
			}
		}
		return array(
			'caches'     => array_values( $caches ),
			'from_cache' => $from,
			'age'        => $age,
			'ttl'        => $ttl,
			'vary'       => array_keys( $vary ),
			'headers'    => $keep,
		);
	}

	/** HestiaCP lays sites out as /home/<user>/web/<domain>/public_html; its caching templates send no header of their own. */
	public static function hestia( $root = ABSPATH ) {
		return (bool) preg_match( '#^/home/[^/]+/web/[^/]+/public_s?html/#', (string) $root );
	}

	/**
	 * What sits in front of WordPress, from the home page as a plain visitor
	 * gets it (no query, no cookies, a browser's headers). Kept FRONT_TTL
	 * seconds unless $fresh.
	 */
	public static function front( $fresh = false ) {
		$kept = $fresh ? false : get_transient( 'kico_cache_front' );
		if ( is_array( $kept ) && isset( $kept['caches'] ) ) {
			return $kept;
		}
		$url = home_url( '/' );
		$r   = self::fetch_all( array( array( 'url' => $url, 'headers' => self::visitor_headers() ) ) )[0];
		$out = array(
			'url'    => $url,
			'at'     => time(),
			'hestia' => self::hestia(),
		);
		if ( isset( $r['error'] ) ) {
			$out += array( 'error' => $r['error'], 'caches' => array(), 'vary' => array(), 'from_cache' => null, 'age' => null, 'ttl' => null, 'headers' => array() );
		} else {
			$out += array( 'status' => $r['status'] ) + self::read_headers( $r['headers'] );
		}
		set_transient( 'kico_cache_front', $out, self::FRONT_TTL );
		return $out;
	}

	/** What front() saw, for env and results. */
	public static function front_summary( array $f ) {
		$seen = array();
		foreach ( $f['caches'] as $c ) {
			$seen[] = $c['cache'] . ' (' . $c['evidence'] . ')';
		}
		if ( $f['hestia'] ) {
			$seen[] = 'HestiaCP host (the site\'s path): Hestia\'s nginx caching templates cache pages for visitors and send no header unless the template adds one';
		}
		$out = array(
			'seen'       => $seen ? $seen : 'none: the home page, as a visitor gets it, has no cache headers',
			'checked'    => $f['url'] . ( isset( $f['status'] ) ? ' (' . $f['status'] . ')' : '' ) . ', ' . gmdate( 'H:i:s', (int) $f['at'] ) . ' UTC',
			'from_cache' => $f['from_cache'],
		);
		if ( ! empty( $f['error'] ) ) {
			$out['error'] = $f['error'];
		}
		foreach ( array( 'age', 'ttl', 'vary' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$out[ $k ] = $f[ $k ];
			}
		}
		if ( ! empty( $f['headers'] ) ) {
			$out['headers'] = $f['headers'];
		}
		return $out;
	}

	/** How long visitors may keep getting an old copy, as far as anything says. */
	private static function expiry( array $f ) {
		if ( $f['ttl'] ) {
			return 'until it expires: the site\'s Cache-Control allows shared caches ' . $f['ttl'] . ' s';
		}
		if ( $f['hestia'] || in_array( 'nginx', array_column( $f['caches'], 'kind' ), true ) ) {
			return 'until it expires: nginx does not say when (HestiaCP\'s caching template keeps pages 5 minutes; templates based on it set their own proxy_cache_valid)';
		}
		return 'until it expires (the cache does not say when)';
	}

	/** After a call that recorded no change: what is known in front, nothing refreshed. */
	private static function front_unchanged() {
		$f = get_transient( 'kico_cache_front' );
		if ( ! is_array( $f ) || ( ! $f['caches'] && ! $f['hestia'] ) ) {
			return array();
		}
		return array(
			'seen'      => self::front_summary( $f )['seen'],
			'refreshed' => 'nothing: this call recorded no change. If it changed what visitors see, refresh with cache_purge (urls) and check with cache_check.',
		);
	}

	/**
	 * Pages a change to these posts shows on: the home page, each published
	 * post, and cheaply its archives (the posts page, two categories, the
	 * post type's archive such as a shop).
	 */
	public static function urls_for( array $posts ) {
		$urls = array( home_url( '/' ) );
		foreach ( array_slice( array_map( 'intval', $posts ), 0, 5 ) as $id ) {
			$p = get_post( $id );
			if ( ! $p || 'publish' !== $p->post_status || ! is_post_type_viewable( $p->post_type ) ) {
				continue;
			}
			$urls[] = get_permalink( $p );
			if ( 'post' === $p->post_type ) {
				$blog = (int) get_option( 'page_for_posts' );
				if ( $blog ) {
					$urls[] = get_permalink( $blog );
				}
				foreach ( array_slice( (array) get_the_category( $p->ID ), 0, 2 ) as $c ) {
					$urls[] = get_category_link( $c );
				}
			} else {
				$urls[] = get_post_type_archive_link( $p->post_type );
			}
		}
		return array_slice( array_values( array_unique( array_filter( $urls, 'is_string' ) ) ), 0, self::AFTER_WRITE_URLS );
	}

	/**
	 * Refreshes the caches in front of WordPress for these pages, and checks
	 * what a visitor gets afterwards.
	 *
	 * - Varnish: PURGE of each page (Varnish allows it where its VCL does).
	 * - Cloudflare: through the Cloudflare plugin, when it is active and
	 *   connected (it purges when its page cache or APO is on).
	 * - nginx proxy caches (HestiaCP) and any cache that bypasses on
	 *   X-Update, Cache-Control: no-cache or Pragma: each page requested with
	 *   those headers as a browser asks for it, so the cache stores the fresh
	 *   page. nginx keeps one copy per value of each header the page varies
	 *   on, and a bypassed answer replaces only its main copy (the others are
	 *   looked up as secondary keys, which a bypass never touches): those
	 *   keep the old page until they expire, and the result says so.
	 * - LiteSpeed and nginx caches with a purge module go through their
	 *   plugins (purge()).
	 * Then each page as plain visitors get it, once per copy the cache keeps
	 * (Vary): the same as the refreshed page, or not.
	 *
	 * @param string[] $urls       Pages of this site.
	 * @param bool     $fresh      Look at the home page's headers again first.
	 * @param bool     $whole_site The change may show on every page (only $urls are refreshed).
	 * @param int      $max        At most this many pages.
	 */
	public static function refresh_front( array $urls, $fresh = false, $whole_site = false, $max = self::AFTER_WRITE_URLS ) {
		$f = self::front( $fresh );
		if ( ! $f['caches'] && ! $f['hestia'] ) {
			return array( 'seen' => self::front_summary( $f )['seen'] );
		}
		$out = array( 'seen' => self::front_summary( $f )['seen'] );
		// Cloudflare without a cache status for HTML (DYNAMIC, or none) holds no pages.
		$holding = array_filter(
			$f['caches'],
			function ( $c ) {
				return 'cloudflare' !== $c['kind'] || false !== $c['hit'];
			}
		);
		if ( ! $holding && ! $f['hestia'] ) {
			$out['refreshed'] = 'nothing: no cache in front holds this site\'s pages';
			return $out;
		}
		$kinds = array_column( $holding, 'kind' );
		$urls  = array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, $max );
		$notes = array();

		if ( in_array( 'varnish', $kinds, true ) ) {
			$purge = array();
			foreach ( $urls as $u ) {
				$purge[] = array( 'url' => $u, 'method' => 'PURGE', 'headers' => array( 'User-Agent' => self::UA ) );
			}
			$codes = array();
			foreach ( self::fetch_all( $purge ) as $i => $r ) {
				$codes[ $urls[ $i ] ] = $r['error'] ?? $r['status'];
			}
			$out['varnish_purge'] = $codes;
		}
		if ( in_array( 'cloudflare', $kinds, true ) ) {
			$cf = $GLOBALS['cloudflareHooks'] ?? null;
			if ( is_object( $cf ) && method_exists( $cf, 'purgeCacheEverything' ) && get_option( 'cloudflare_api_key' ) && get_option( 'cloudflare_api_email' ) ) {
				$cf->purgeCacheEverything();
				$out['cloudflare'] = 'Asked the Cloudflare plugin to purge the zone (it does when its page cache or APO is on).';
			} else {
				$notes[] = 'Cloudflare: no connected Cloudflare plugin here, so Kico cannot purge it. Cloudflare caches HTML only with a cache rule or APO (CF-Cache-Status DYNAMIC means it does not).';
			}
		}
		if ( in_array( 'litespeed', $kinds, true ) && ! defined( 'LSCWP_V' ) ) {
			$notes[] = 'LiteSpeed: its cache is purged through the LiteSpeed Cache plugin, which is not active.';
		}

		// Each page asked fresh as a browser asks for it, the cache storing the answer. When the
		// page varies on Sec-Fetch-Dest, frames and other clients first and the page last: an nginx
		// that keys on Sec-Fetch-Dest (and ignores Vary) gets every copy; one that follows Vary
		// keeps the last as its main copy, the one most visitors get.
		$variants = self::variants( $f['vary'] );
		$dests    = in_array( 'sec-fetch-dest', $f['vary'], true ) ? array_reverse( self::DESTS ) : array( 'document' );
		foreach ( $dests as $d ) {
			$reqs = array();
			foreach ( $urls as $u ) {
				$reqs[] = array( 'url' => $u, 'headers' => self::REFRESH + self::visitor_headers( self::ENCODINGS[0], $d ) );
			}
			$refreshed = self::fetch_all( $reqs );
		}
		// Then every copy visitors get (one per value of what the page varies on), as they get it:
		// for the first pages; past FULL_CHECKS requests, the browser copy only.
		$visits = array();
		$index  = array();
		foreach ( $urls as $i => $u ) {
			$these       = count( $visits ) + count( $variants ) <= self::FULL_CHECKS ? $variants : array( $variants[0] );
			$index[ $i ] = array( count( $visits ), count( $these ) );
			foreach ( $these as $vh ) {
				$visits[] = array( 'url' => $u, 'headers' => $vh );
			}
		}
		$visited = self::fetch_all( $visits );
		$pages   = array();
		$split   = false;
		foreach ( $urls as $i => $u ) {
			list( $from, $n ) = $index[ $i ];
			$first = $refreshed[ $i ];
			$page  = array(
				'url'       => $u,
				'refreshed' => isset( $first['error'] ) ? 'failed: ' . $first['error'] : $first['status'] . self::evidence( $first['headers'], ': ' ),
			);
			$fresh = array();
			$old   = array();
			$open  = array();
			foreach ( array_slice( $visited, $from, $n ) as $k => $visit ) {
				$who = self::who( $variants[ $k ], count( $variants ) );
				if ( isset( $visit['error'] ) || isset( $first['error'] ) ) {
					$open[ $who ] = $visit['error'] ?? $first['error'];
					continue;
				}
				$seen = self::read_headers( $visit['headers'] );
				if ( ( $visit['status'] === $first['status'] && md5( $visit['body'] ) === md5( $first['body'] ) ) || false === $seen['from_cache'] ) {
					$fresh[] = $who;
				} elseif ( true === $seen['from_cache'] ) {
					$old[ $who ] = $visit['status'] . self::evidence( $visit['headers'], ': ' ) . ( null !== $seen['age'] ? ', Age ' . $seen['age'] : '' );
				} else {
					$open[ $who ] = 'differs from the refreshed page, and no header says whether it came from a cache (a page that changes on every load looks like this too)';
				}
			}
			$page['visitor_gets_fresh'] = $old ? false : ( $open ? null : true );
			if ( count( $variants ) > 1 || ! $page['visitor_gets_fresh'] ) {
				$page['fresh_for'] = $fresh;
			}
			if ( $n < count( $variants ) ) {
				$page['checked'] = 'the browser page copy only, of ' . count( $variants ) . ' the cache may keep';
			}
			if ( $old ) {
				$page['old_for'] = $old;
				$split           = $split || ( $fresh && count( $variants ) > 1 );
			}
			if ( $open ) {
				$page['unknown_for'] = $open;
			}
			$pages[] = $page;
		}
		$out['pages'] = $pages;
		if ( $split ) {
			$notes[] = 'The page varies on ' . implode( ', ', $f['vary'] ) . ', and nginx keeps a copy per value: a refresh (X-Update) replaces only its main copy, the one browsers get; the copies in old_for keep the old page ' . self::expiry( $f ) . '. (An nginx that keys on $http_sec_fetch_dest, does not pass Accept-Encoding upstream and ignores Vary keeps one copy per Sec-Fetch-Dest, and a refresh renews them all.)';
		}
		if ( array_filter(
			$pages,
			function ( $p ) {
				return true !== $p['visitor_gets_fresh'];
			}
		) ) {
			$notes[] = 'Where visitor_gets_fresh is not true, those visitors may get the old page ' . self::expiry( $f ) . '. cache_check shows what a visitor gets.';
		}
		if ( $whole_site ) {
			$notes[] = 'Only these pages were refreshed; a change that shows on every page (theme, options, menus, widgets) stays old on the others ' . self::expiry( $f ) . '. cache_purge with urls refreshes more.';
		}
		if ( array_diff( $f['vary'], array( 'accept-encoding', 'sec-fetch-dest' ) ) ) {
			$notes[] = 'The page varies on ' . implode( ', ', array_diff( $f['vary'], array( 'accept-encoding', 'sec-fetch-dest' ) ) ) . ': only the copies for a desktop browser\'s values of those were checked.';
		}
		if ( $notes ) {
			$out['notes'] = $notes;
		}
		return $out;
	}

	/** Who gets a visitor copy: by Sec-Fetch-Dest and Accept-Encoding, when the page varies on them. */
	private static function who( array $h, $n ) {
		if ( 1 === $n ) {
			return 'visitors';
		}
		$dest = $h['Sec-Fetch-Dest'] ?? '';
		$enc  = $h['Accept-Encoding'] ?? '';
		$by   = array(
			'gzip, deflate, br, zstd' => 'Chrome, Edge, Firefox',
			'gzip, deflate, br'       => 'Safari',
			'gzip, deflate'           => 'other clients',
		);
		$what = 'document' === $dest ? 'pages' : ( 'iframe' === $dest ? 'frames' : 'no Sec-Fetch-Dest (bots, Kico\'s preview)' );
		return $what . ', ' . ( $by[ $enc ] ?? $enc ) . ' (Accept-Encoding: ' . $enc . ')';
	}

	/** A response's cache evidence in a few words ("X-Cache: HIT"). */
	private static function evidence( array $headers, $prefix ) {
		$e = array();
		foreach ( self::read_headers( $headers )['caches'] as $c ) {
			$e[] = $c['evidence'];
		}
		return $e ? $prefix . implode( '; ', $e ) : '';
	}

	/** A path ("/about/") is this site's; anything else as given. */
	private static function own_url( $u ) {
		$u = trim( (string) $u );
		return '' !== $u && '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ? home_url( $u ) : $u;
	}

	/**
	 * Looks at a page of this site as a plain visitor does (cache_check): a
	 * browser's headers, no cookies, the address as given. Reads only.
	 */
	public static function check( array $a ) {
		$u = self::own_url( $a['url'] ?? '/' );
		if ( ! self::same_site( $u ) ) {
			return array( 'ok' => false, 'error' => 'Not this site: cache_check loads only this site\'s own pages (' . home_url( '/' ) . ').' );
		}
		$texts = array_values( array_filter( array_map( 'strval', (array) ( $a['text'] ?? array() ) ), 'strlen' ) );
		$reqs  = array( array( 'url' => $u, 'headers' => self::visitor_headers() ) );
		if ( ! empty( $a['fresh'] ) ) {
			// Passed by WordPress-aware caches (HestiaCP: bypassed and not stored); its headers say whether it was.
			$reqs[] = array( 'url' => $u, 'headers' => self::visitor_headers() + array( 'Cookie' => 'wordpress_no_cache=1', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ) );
		}
		$res = self::fetch_all( $reqs );
		$out = array(
			'url' => $u,
			'as'  => 'a visitor: Chrome\'s request headers, no cookies, this address as given',
		) + self::seen( $res[0], $texts );
		if ( ! empty( $a['fresh'] ) ) {
			$out['fresh'] = array( 'asked' => 'with the cookie wordpress_no_cache=1 and Cache-Control: no-cache, which WordPress-aware caches pass by without storing; its from_cache says whether this one did' ) + self::seen( $res[1], $texts );
			if ( isset( $res[0]['body'], $res[1]['body'] ) ) {
				$out['fresh']['same_as_visitor'] = md5( $res[0]['body'] ) === md5( $res[1]['body'] );
			}
		}
		if ( self::hestia() ) {
			$out['host'] = 'HestiaCP: its nginx caching templates may cache pages without any header saying so.';
		}
		return $out;
	}

	/** One response as cache_check reports it. */
	private static function seen( array $r, array $texts ) {
		if ( isset( $r['error'] ) ) {
			return array( 'error' => $r['error'] );
		}
		$s   = self::read_headers( $r['headers'] );
		$out = array(
			'status'     => $r['status'],
			'ms'         => $r['ms'],
			'bytes'      => strlen( $r['body'] ),
			'from_cache' => $s['from_cache'],
			'caches'     => $s['caches'],
			'headers'    => $s['headers'],
		);
		foreach ( array( 'age', 'ttl' ) as $k ) {
			if ( null !== $s[ $k ] ) {
				$out[ $k ] = $s[ $k ];
			}
		}
		if ( isset( $r['headers']['location'] ) ) {
			$out['location'] = $r['headers']['location'];
		}
		if ( $texts ) {
			$out['contains'] = array();
			foreach ( $texts as $t ) {
				$out['contains'][ $t ] = false !== strpos( $r['body'], $t );
			}
		}
		return $out;
	}

	/**
	 * Requests to this site's own pages, five at a time in parallel, exactly
	 * with the headers given (WordPress's HTTP API would set its own
	 * Accept-Encoding). No redirects followed; TLS verified as WordPress
	 * verifies it.
	 *
	 * @param array[] $reqs Each: url, headers, method (GET).
	 * @return array[] In the same order: status, headers (lower-case name => value), body, ms; or error.
	 */
	private static function fetch_all( array $reqs, $timeout = 15 ) {
		$out = array();
		foreach ( array_chunk( $reqs, 5, true ) as $chunk ) {
			$batch = array();
			foreach ( $chunk as $i => $r ) {
				$verify      = 0 === stripos( $r['url'], 'https:' ) ? apply_filters( 'https_ssl_verify', true, $r['url'] ) : false;
				$batch[ $i ] = array(
					'url'     => $r['url'],
					'type'    => $r['method'] ?? 'GET',
					'headers' => $r['headers'],
					'options' => array(
						'timeout'          => $timeout,
						'connect_timeout'  => 10,
						'follow_redirects' => false,
						'useragent'        => self::UA,
						'verify'           => $verify ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
						'verifyname'       => (bool) $verify,
					),
				);
			}
			$t = microtime( true );
			try {
				$res = \WpOrg\Requests\Requests::request_multiple( $batch );
			} catch ( Throwable $e ) {
				$res = array_fill_keys( array_keys( $batch ), $e );
			}
			$ms = (int) ( ( microtime( true ) - $t ) * 1000 );
			foreach ( array_keys( $chunk ) as $i ) {
				$r = $res[ $i ] ?? null;
				if ( $r instanceof \WpOrg\Requests\Response ) {
					$h = array();
					foreach ( $r->headers as $k => $v ) {
						$h[ strtolower( $k ) ] = (string) $v;
					}
					$out[ $i ] = array( 'status' => (int) $r->status_code, 'headers' => $h, 'body' => (string) $r->body, 'ms' => $ms );
				} else {
					$out[ $i ] = array( 'error' => $r instanceof Throwable ? $r->getMessage() : 'no answer' );
				}
			}
		}
		return $out;
	}

	// --- loading pages -----------------------------------------------------------------------------

	/** This site's own address: the host of its home or site URL. */
	public static function same_site( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $own ) {
			if ( strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/** Loads pages of this site only (wp_safe_remote_get, TLS verified), to warm caches and see their status. */
	public static function warm( array $a ) {
		$urls = array();
		foreach ( (array) ( $a['urls'] ?? array() ) as $u ) {
			$u = (string) $u;
			// A path ("/about/") is this site's.
			$urls[] = '' !== $u && '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ? home_url( $u ) : $u;
		}
		foreach ( (array) ( $a['post_ids'] ?? array() ) as $id ) {
			$urls[] = get_permalink( (int) $id );
		}
		if ( ! $urls ) {
			$urls[] = home_url( '/' );
		}
		$out = array();
		foreach ( array_slice( array_filter( $urls ), 0, 50 ) as $u ) {
			if ( ! self::same_site( $u ) ) {
				$out[] = array( 'url' => $u, 'error' => 'Not this site: cache_warm loads only this site\'s own pages (' . home_url( '/' ) . ').' );
				continue;
			}
			$t = microtime( true );
			$r = wp_safe_remote_get( $u, array( 'timeout' => 20, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
			$out[] = is_wp_error( $r )
				? array( 'url' => $u, 'error' => $r->get_error_message() )
				: array(
					'url'    => $u,
					'status' => wp_remote_retrieve_response_code( $r ),
					'ms'     => (int) ( ( microtime( true ) - $t ) * 1000 ),
					'bytes'  => strlen( wp_remote_retrieve_body( $r ) ),
					'cache'  => self::headers( wp_remote_retrieve_headers( $r ) ),
				);
		}
		return array( 'fetched' => $out );
	}

	/** What a response says about caches in front of the site. */
	private static function headers( $headers ) {
		$out = array();
		foreach ( array( 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-varnish', 'x-proxy-cache', 'x-kinsta-cache', 'x-sucuri-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'age', 'cache-control' ) as $h ) {
			$v = $headers[ $h ] ?? null;
			if ( null !== $v && '' !== $v ) {
				$out[ $h ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}
		return $out;
	}
}
