<?php
/**
 * The MCP endpoint (/wp-json/kico/v1/mcp), built on the official WordPress
 * MCP Adapter: each tool is a WordPress ability, listed as its own MCP tool
 * with readOnlyHint and destructiveHint, so Kico's approval rules work per
 * tool. Abilities are kept out of the REST abilities endpoints and of the
 * adapter's default server; only signed Kico requests reach them.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Server {

	/** The server's instructions to the agent (MCP initialize; Kico passes them on). */
	const INSTRUCTIONS = 'Manage this WordPress site: content, Elementor and blocks, media, options, files, the database and PHP. Call env first. Every write is snapshotted and can be rolled back with rollback(op_id).

Visitors may get pages from a cache in front of WordPress (nginx or HestiaCP, Varnish, LiteSpeed, Cloudflare or another CDN; env caches.in_front shows what the home page\'s headers reveal). A request with a query string, cookies or a logged-in session passes such caches by, so it shows the fresh page even while visitors still get the old one.
- Verify a change the way a visitor sees it: cache_check on the page (its address as visitors use it, no cache-busting query), with text to look for.
- Every write and rollback refreshes the home page and the posts it changed in the caches it can reach, and page_cache_in_front in its result says, page by page, whether a visitor now gets the fresh page. If a cache still serves the old page, refresh it (cache_purge with urls or post_ids), or say plainly that visitors see the old version until the cache expires, with how long when it is known.
- Never report a change as done from a fetch that skipped the cache (a ?nc= or other cache-busting query, a logged-in request, the preview\'s ?kico_op).';

	public static function boot() {
		self::load_adapter();
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities' ) );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'server' ) );
		add_filter( 'mcp_adapter_tool_name', array( __CLASS__, 'tool_name' ), 10, 2 );
	}

	/** The MCP Adapter: the canonical plugin when it is active, else the copy bundled here. */
	private static function load_adapter() {
		$bundled = __DIR__ . '/../vendor/autoload_packages.php';
		if ( file_exists( $bundled ) ) {
			require_once $bundled;
		}
		add_action(
			'plugins_loaded',
			function () {
				if ( ! defined( 'WP_MCP_VERSION' ) && class_exists( '\WP\MCP\Plugin' ) ) {
					// Loaded as the plugin it is, without the default server, which
					// would expose public abilities to anyone with an app password.
					add_filter( 'mcp_adapter_create_default_server', '__return_false' );
					\WP\MCP\Plugin::instance();
				}
			},
			5
		);
	}

	public static function category() {
		wp_register_ability_category(
			'kico',
			array(
				'label'       => 'Kico',
				'description' => 'Tools Kico uses to manage this site.',
			)
		);
	}

	/** kico/fs-read is listed as fs_read. */
	public static function tool_name( $name, $ability ) {
		$n = $ability->get_name();
		return 0 === strpos( $n, 'kico/' ) ? str_replace( '-', '_', substr( $n, 5 ) ) : $name;
	}

	private static function obj( array $props, array $required = array() ) {
		$s = array( 'type' => 'object', 'properties' => $props );
		if ( $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	/** Every tool: name => [label, description, annotations, input schema, run, capture]. */
	public static function definitions() {
		$str   = array( 'type' => 'string' );
		$int   = array( 'type' => 'integer' );
		$bool  = array( 'type' => 'boolean' );
		$any   = array();
		$strs  = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );
		$ro    = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
		$write = array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
		$dest  = array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
		$T     = 'Kico_Tools';
		$tables_note = ' tables: names of tables to snapshot first; omitted, every table of the site is snapshotted as far as the size limit goes; [] for none.';
		$code_note   = ' What the code changes is recorded while it runs: files changed through PHP\'s file functions (their old content), files created or changed otherwise (found by comparing wp-content and the root before and after), and rows changed through $wpdb (by key). tables: names of tables to also keep whole first. files: false records the database only; record: false records nothing.';
		$code_args   = array( 'tables' => $strs, 'files' => $bool, 'record' => $bool );
		return array(
			'env'             => array( 'Environment', 'What this site runs on and what it allows: WordPress, PHP and database versions, plugins and theme, page builder, disabled functions, open_basedir, which paths PHP may write, file owner vs PHP user, WP-CLI, memory and time limits, host markers, and the page caches: plugins, and caches.in_front, what sits in front of WordPress as the home page\'s headers show it to a plain visitor (nginx/HestiaCP, Varnish, LiteSpeed, Cloudflare, CDNs). Read this first to choose a route; nothing here refuses.', $ro, self::obj( array() ), array( $T, 'env' ), null ),
			'fs-list'         => array( 'List files', 'List a folder (path relative to the WordPress root, or absolute), depth 1 to 5.', $ro, self::obj( array( 'path' => $str, 'depth' => $int ) ), array( $T, 'fs_list' ), null ),
			'fs-read'         => array( 'Read a file', 'Read a file (text, or base64 for binary). offset and length (default 1 MB, up to 5 MB) page through large files.', $ro, self::obj( array( 'path' => $str, 'offset' => $int, 'length' => $int ), array( 'path' ) ), array( $T, 'fs_read' ), null ),
			'fs-write'        => array( 'Write a file', 'Create or replace a file (themes, plugins, wp-config.php, anything PHP may write). content, or content_base64 when a firewall blocks code in requests. append: true adds to the end. The old file is snapshotted.', $write, self::obj( array( 'path' => $str, 'content' => $str, 'content_base64' => $str, 'append' => $bool ), array( 'path' ) ), array( $T, 'fs_write' ), function ( $a, $s ) {
				$s->file( $a['path'] ?? '' );
			} ),
			'fs-delete'       => array( 'Delete a file', 'Delete a file or a folder with everything in it. Snapshotted first.', $dest, self::obj( array( 'path' => $str ), array( 'path' ) ), array( $T, 'fs_delete' ), function ( $a, $s ) {
				$s->file( $a['path'] ?? '' );
			} ),
			'fs-move'         => array( 'Move a file', 'Move or rename a file or folder.', $write, self::obj( array( 'from' => $str, 'to' => $str ), array( 'from', 'to' ) ), array( $T, 'fs_move' ), function ( $a, $s ) {
				$s->file( $a['from'] ?? '' );
				$s->file( $a['to'] ?? '' );
			} ),
			'sql-read'        => array( 'Query the database', 'Run one SQL statement in a read-only transaction (the database refuses any change) and get the rows. The table prefix is in env. sql_base64 when a firewall blocks SQL in requests.', $ro, self::obj( array( 'sql' => $str, 'sql_base64' => $str, 'max_rows' => $int ) ), array( $T, 'sql_read' ), null ),
			'sql-write'       => array( 'Change the database', 'Run one SQL statement that changes data (UPDATE, INSERT, DELETE, REPLACE, DDL). What it changes is snapshotted first, read from the statement: the rows an UPDATE or DELETE touches and the keys an INSERT writes (by primary key, rows locked until it ran), whole tables for DDL, tables without a key and conditions that are not the same twice. tables: names of tables to keep whole instead.', $dest, self::obj( array( 'sql' => $str, 'sql_base64' => $str, 'tables' => $strs ) ), array( $T, 'sql_write' ), array( $T, 'snapshot_sql' ) ),
			'php-eval'        => array( 'Run PHP', 'Run PHP code inside WordPress (everything loaded, as the admin). echo output and the return value come back; errors are caught. code_base64 when a firewall blocks code in requests.' . $code_note, $dest, self::obj( array( 'code' => $str, 'code_base64' => $str ) + $code_args ), array( $T, 'php_eval' ), array( $T, 'snapshot_code' ) ),
			'wp-cli'          => array( 'Run WP-CLI', 'Run a WP-CLI command when the host has WP-CLI and allows proc_open (env says). args as a list, e.g. ["elementor","flush-css"]. timeout in seconds (default 120). On multisite it runs for this site (--url) unless args name another.' . $code_note, $dest, self::obj( array( 'args' => $strs, 'timeout' => $int ) + $code_args, array( 'args' ) ), array( $T, 'wp_cli' ), array( $T, 'snapshot_code' ) ),
			'posts-query'     => array( 'Find posts and pages', 'List posts, pages or any post type: post_type, status, search, slug, per_page, page.', $ro, self::obj( array( 'post_type' => $str, 'status' => $str, 'search' => $str, 'slug' => $str, 'per_page' => $int, 'page' => $int ) ), array( $T, 'posts_query' ), null ),
			'post-get'        => array( 'Read a post', 'A post or page with its content, meta and terms. Elementor data is summarized; use elementor_get.', $ro, self::obj( array( 'id' => $int, 'full_meta' => $bool ), array( 'id' ) ), array( $T, 'post_get' ), null ),
			'post-save'       => array( 'Save a post', 'Create a post or page (no id; draft unless status says) or update one (id): title, content, excerpt, status, slug, post_type, parent, menu_order, and meta (null deletes a key). Keeps WordPress revisions.', $write, self::obj( array( 'id' => $int, 'title' => $str, 'content' => $str, 'excerpt' => $str, 'status' => $str, 'slug' => $str, 'post_type' => $str, 'parent' => $int, 'menu_order' => $int, 'meta' => array( 'type' => 'object' ) ) ), array( $T, 'post_save' ), function ( $a, $s ) {
				if ( ! empty( $a['id'] ) ) {
					$s->post( $a['id'] );
				}
			} ),
			'post-delete'     => array( 'Delete a post', 'Move a post to the trash, or delete it for good with force: true.', $dest, self::obj( array( 'id' => $int, 'force' => $bool ), array( 'id' ) ), array( $T, 'post_delete' ), function ( $a, $s ) {
				$s->post( $a['id'] ?? 0 );
			} ),
			'media-upload'    => array( 'Upload media', 'Add a file to the media library from content_base64 (with filename) or from a url. title, alt, and post_id to attach it.', $write, self::obj( array( 'filename' => $str, 'content_base64' => $str, 'url' => $str, 'title' => $str, 'alt' => $str, 'post_id' => $int ) ), array( $T, 'media_upload' ), function ( $a, $s ) {} ),
			'options-get'     => array( 'Read options', 'Read site options by name (unserialized).', $ro, self::obj( array( 'names' => $strs ), array( 'names' ) ), array( $T, 'options_get' ), null ),
			'options-update'  => array( 'Change options', 'Set site options: values is { name: value }; null deletes one.', $write, self::obj( array( 'values' => array( 'type' => 'object' ) ), array( 'values' ) ), array( $T, 'options_update' ), array( $T, 'snapshot_options' ) ),
			'abilities-list'  => array( 'Other plugins\' abilities', 'The abilities other plugins registered (Elementor, Yoast, WooCommerce, Rank Math...), with their input schemas.', $ro, self::obj( array( 'category' => $str ) ), array( $T, 'abilities_list' ), null ),
			'abilities-run'   => array( 'Run an ability', 'Run another plugin\'s ability by name with its input. Recorded like php_eval unless the ability says it only reads.' . $code_note, $dest, self::obj( array( 'name' => $str, 'input' => $any ) + $code_args, array( 'name' ) ), array( $T, 'abilities_run' ), function ( $a, $s ) {
				if ( ! Kico_Tools::ability_readonly( $a['name'] ?? '' ) ) {
					Kico_Tools::snapshot_code( $a, $s );
				}
			} ),
			'elementor-get'   => array( 'Read an Elementor page', 'The Elementor layout of a page: an outline of its elements (id, type, widget, text), one element with element_id, or everything with full: true.', $ro, self::obj( array( 'post_id' => $int, 'element_id' => $str, 'full' => $bool ), array( 'post_id' ) ), array( $T, 'elementor_get' ), null ),
			'elementor-patch' => array( 'Change an Elementor element', 'Change one element of an Elementor page and save it through Elementor (revisions, CSS and post_content stay right): settings merges into its settings (null removes a key), element replaces it, remove: true deletes it, insert {parent_id, position, element} adds one.', $write, self::obj( array( 'post_id' => $int, 'element_id' => $str, 'settings' => array( 'type' => 'object' ), 'element' => array( 'type' => 'object' ), 'remove' => $bool, 'insert' => array( 'type' => 'object' ) ), array( 'post_id' ) ), array( $T, 'elementor_patch' ), function ( $a, $s ) {
				$s->post( $a['post_id'] ?? 0 );
			} ),
			'css-regenerate'  => array( 'Regenerate Elementor CSS', 'Regenerate Elementor\'s CSS for one post (post_id) or clear it for the whole site.', array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), self::obj( array( 'post_id' => $int ) ), array( $T, 'css_regenerate' ), function ( $a, $s ) {} ),
			'blocks-get'      => array( 'Read blocks', 'The Gutenberg blocks of a post as a tree with paths (0, 0.1, ...).', $ro, self::obj( array( 'post_id' => $int ), array( 'post_id' ) ), array( $T, 'blocks_get' ), null ),
			'blocks-patch'    => array( 'Change a block', 'Change the block at path: attrs merges attributes, block replaces it with markup, insert puts markup before it, remove: true deletes it. Saved as a revision.', $write, self::obj( array( 'post_id' => $int, 'path' => $str, 'attrs' => array( 'type' => 'object' ), 'block' => $str, 'insert' => $str, 'remove' => $bool ), array( 'post_id', 'path' ) ), array( $T, 'blocks_patch' ), function ( $a, $s ) {
				$s->post( $a['post_id'] ?? 0 );
			} ),
			'cache-warm'      => array( 'Load pages', 'Request pages of this site (urls or paths on it, post_ids, or the home page) to warm its caches, and see their status, time and cache headers. It does not refresh a copy a cache already holds (cache_purge does) and is not how a visitor sees the page (cache_check is). Only this site\'s own addresses; TLS is verified.', array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), self::obj( array( 'urls' => $strs, 'post_ids' => array( 'type' => 'array', 'items' => $int ) ) ), array( $T, 'cache_warm' ), null ),
			'cache-purge'     => array( 'Purge page caches', 'Purge the page cache plugins the site has (env lists them: WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed...) and refresh the cache in front of WordPress (nginx/HestiaCP, Varnish, Cloudflare through its plugin): post_ids for those pages, their archives and the home page, urls (paths or addresses of this site) for any other pages, else the whole site in the plugins and the home page in front. page_cache_in_front says, page by page, whether a visitor now gets the fresh page. object_cache: true also flushes the object cache. Writes and rollbacks do this on their own for the home page and the posts they changed; use it for other pages a change shows on, and for changes made another way.', array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), self::obj( array( 'post_ids' => array( 'type' => 'array', 'items' => $int ), 'urls' => $strs, 'object_cache' => $bool ) ), array( $T, 'cache_purge' ), null ),
			'cache-check'     => array( 'See a page as a visitor', 'Load a page of this site (url: a path or an address on it; default the home page) the way a plain visitor does: a browser\'s headers, no cookies, no cache-busting query, so caches in front answer as they do for visitors. Returns the status, whether the answer came from a cache and the cache headers (X-Cache, CF-Cache-Status, X-Varnish, Age...), and for each of text whether the page contains it. fresh: true also loads a copy that asks caches to step aside (same_as_visitor compares them). Reads only. Use it to verify a change as visitors will see it.', $ro, self::obj( array( 'url' => $str, 'text' => $strs, 'fresh' => $bool ) ), array( $T, 'cache_check' ), null ),
			'audit-log'       => array( 'Audit log', 'The site\'s log of every Kico call (newest first) with full arguments, and whether its hash chain is intact. op_id for one operation.', $ro, self::obj( array( 'limit' => $int, 'op_id' => $str ) ), array( $T, 'audit_log' ), null ),
			'op-status'       => array( 'Operation status', 'Whether an earlier call ran and how it ended, by its op_id: phase (started or done), outcome (ok, error, fatal, timeout, exit) and its result. For a call whose answer did not come back.', $ro, self::obj( array( 'op_id' => $str ), array( 'op_id' ) ), array( $T, 'op_status' ), null ),
			'plugin-update'   => array( 'Update Kico for WordPress', 'Update this plugin to the newest signed release Kico serves, through WordPress\'s own plugin upgrader (as wp-admin\'s Update now; the package is checked against Kico\'s release signature). The plugin\'s folder is snapshotted first, so rollback(op_id) puts the old version back. After the update a request of its own checks that the new version loads; when it does not, the old folder is put back at once (rolled_back). version is the version on disk after the call.', $dest, self::obj( array() ), array( 'Kico_Updates', 'update_self' ), array( 'Kico_Updates', 'snapshot_update' ) ),
			'rollback'        => array( 'Roll back an operation', 'Undo one earlier write by its op_id: Kico adds the snapshot it keeps. The current state is snapshotted first, so a rollback can be rolled back. Every file and row is checked after it is put back; ok: false says what did not take. When a file cannot be written, nothing is changed unless partial: true. A table kept whole is replaced whole (later changes to it are lost), and rows changed since the operation are named in changed_since.', $dest, self::obj( array( 'op_id' => $str, 'snapshot' => array( 'type' => 'object' ), 'partial' => $bool ), array( 'op_id' ) ), array( $T, 'rollback' ), function ( $a, $s ) {
				if ( is_array( $a['snapshot'] ?? null ) ) {
					$s->absorb( Kico_Snapshot::inverse( $a['snapshot'] ) );
				}
			} ),
		);
	}

	public static function abilities() {
		foreach ( self::definitions() as $name => $d ) {
			list( $label, $description, $annotations, $schema, $run, $capture ) = $d;
			$tool = str_replace( '-', '_', $name );
			wp_register_ability(
				'kico/' . $name,
				array(
					'label'               => $label,
					'description'         => $description,
					'category'            => 'kico',
					'input_schema'        => $schema,
					'execute_callback'    => function ( $input = array() ) use ( $tool, $run, $capture ) {
						$args = is_array( $input ) ? $input : array();
						return Kico_Ops::run(
							$tool,
							$args,
							$capture ? function ( $s ) use ( $capture, $args ) {
								call_user_func( $capture, $args, $s );
							} : null,
							function ( $s ) use ( $run, $args ) {
								return call_user_func( $run, $args, $s );
							}
						);
					},
					'permission_callback' => array( 'Kico_Auth', 'bound_user_can' ),
					'meta'                => array(
						'annotations'  => $annotations,
						'show_in_rest' => false,
						'mcp'          => array( 'public' => false ),
					),
				)
			);
		}
	}

	public static function server( $adapter ) {
		$tools = array();
		foreach ( array_keys( self::definitions() ) as $name ) {
			$tools[] = 'kico/' . $name;
		}
		$adapter->create_server(
			'kico',
			Kico_Auth::NS,
			'mcp',
			'Kico for WordPress',
			self::INSTRUCTIONS,
			KICO_WP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			array(),
			array(),
			array( 'Kico_Auth', 'bound_user_can' )
		);
	}
}
