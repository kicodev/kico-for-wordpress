<?php
/**
 * Snapshots: what a write changes, captured before it runs, and put back on
 * rollback. Kico_Ops takes one before each write and sends it to Kico with
 * the result; a rollback restores it after capturing the current state
 * (inverse), so a rollback can be rolled back too.
 *
 * What a snapshot holds (format v2, docs/wordpress.md "Snapshots"):
 * - files: old content of files (paths relative to the WordPress root), and
 *   paths that did not exist (a rollback deletes them);
 * - archives: whole plugin or theme folders as a zip, taken when an upgrader
 *   replaces them;
 * - keys: rows by primary key, as they were before an SQL statement (from
 *   the statement itself, Kico_Sql), and keys the statement created;
 * - rows: rows matching a condition the plugin wrote (a post and its meta,
 *   options by name);
 * - tables: whole tables, where the rows cannot be told (DDL, no key, a
 *   condition that is not the same twice);
 * - created: posts and attachments a tool created.
 *
 * Code Kico runs (php_eval, wp_cli, abilities_run) is recorded while it
 * runs: file changes through Kico_Recorder, $wpdb queries through the
 * "query" filter, and a before/after manifest of wp-content and the root
 * for what neither sees.
 *
 * Nothing here refuses: what could not be captured (too large, unreadable)
 * is said in the snapshot's notes, and the model decides what to do.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

final class Kico_Snapshot {

	const VERSION = 2;

	/** @var array<string, array> By absolute path. */
	public $files    = array();
	public $archives = array();
	/** @var array<string, array> By table: pk, rows (by key), absent (by key), after (by key). */
	public $keys    = array();
	public $rows    = array();
	public $tables  = array();
	public $created = array();
	public $notes   = array();
	public $bytes   = 0;

	/** Set by the capture: record what the run changes (code tools). */
	public $record = false;
	/** Set by the capture: also record files (false: database only). */
	public $record_files = true;
	/** Compare wp-content and the root before and after (off in WP-CLI's process: the parent does it). */
	public $use_manifest = true;
	/** The size limit for this snapshot, when not KICO_SNAPSHOT_MAX_BYTES (what is left of the parent's, in WP-CLI). */
	public $max = null;

	private $pending  = null;
	private $journal  = false;
	private $in_query = false;
	private $tx       = false;
	private $manifest = null;
	private $begun    = false;
	private $ended    = false;
	private $whole    = array();
	private $posts    = array();
	/** Tables the run changed, for the object cache: table => true. */
	private $touched = array();

	public static function max_bytes() {
		return defined( 'KICO_SNAPSHOT_MAX_BYTES' ) ? (int) KICO_SNAPSHOT_MAX_BYTES : 8 * 1024 * 1024;
	}

	public function limit() {
		return null === $this->max ? self::max_bytes() : (int) $this->max;
	}

	public function room( $size, $what ) {
		if ( $this->bytes + $size > $this->limit() ) {
			$this->note( sprintf( 'Not captured: %s (%s; the snapshot limit is %s, KICO_SNAPSHOT_MAX_BYTES).', $what, size_format( $size ), size_format( $this->limit() ) ) );
			return false;
		}
		$this->bytes += $size;
		return true;
	}

	public function note( $text ) {
		if ( ! in_array( $text, $this->notes, true ) && count( $this->notes ) < 100 ) {
			$this->notes[] = $text;
		}
	}

	// --- paths -------------------------------------------------------------------------------------

	/** The WordPress root, normalized, with a trailing slash. */
	public static function root() {
		return trailingslashit( wp_normalize_path( ABSPATH ) );
	}

	/** An absolute path, without . and .. segments. */
	public static function abs( $path ) {
		$path  = Kico_Tools::path( $path );
		$parts = array();
		foreach ( explode( '/', $path ) as $i => $seg ) {
			if ( '..' === $seg ) {
				if ( count( $parts ) > 1 ) {
					array_pop( $parts );
				}
			} elseif ( '.' !== $seg && ( '' !== $seg || 0 === $i ) ) {
				$parts[] = $seg;
			}
		}
		return implode( '/', $parts );
	}

	/** A path relative to the WordPress root (../ for paths outside it); "" is the root. */
	public static function rel( $abs ) {
		$abs  = self::abs( $abs );
		$root = rtrim( self::root(), '/' );
		if ( $abs === $root ) {
			return '';
		}
		if ( 0 === strpos( $abs, $root . '/' ) ) {
			return substr( $abs, strlen( $root ) + 1 );
		}
		$a = explode( '/', trim( $root, '/' ) );
		$b = explode( '/', trim( $abs, '/' ) );
		while ( $a && $b && $a[0] === $b[0] ) {
			array_shift( $a );
			array_shift( $b );
		}
		return str_repeat( '../', count( $a ) ) . implode( '/', $b );
	}

	// --- files -------------------------------------------------------------------------------------

	public function has_file( $abs ) {
		if ( isset( $this->files[ $abs ] ) ) {
			return true;
		}
		foreach ( $this->archives as $dir => $_ ) {
			if ( 0 === strpos( $abs . '/', rtrim( $dir, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** A file (or every file under a folder) as it is now. */
	public function file( $path ) {
		$path = self::abs( $path );
		if ( $this->has_file( $path ) ) {
			return;
		}
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$this->files[ $path ] = array( 'path' => $path, 'dir' => true, 'existed' => true, 'perms' => @fileperms( $path ) & 0777 );
			$items                = @scandir( $path );
			if ( false === $items ) {
				$this->note( "Not captured: the folder $path is not readable by PHP." );
				return;
			}
			foreach ( $items as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$this->file( $path . '/' . $name );
				}
			}
			return;
		}
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			$this->files[ $path ] = array( 'path' => $path, 'existed' => false );
			return;
		}
		if ( is_link( $path ) ) {
			$this->files[ $path ] = array( 'path' => $path, 'existed' => true, 'link' => (string) readlink( $path ) );
			return;
		}
		if ( ! is_readable( $path ) ) {
			$this->note( "Not captured: $path is not readable by PHP." );
			return;
		}
		$size = (int) filesize( $path );
		if ( ! $this->room( (int) ( ceil( $size / 3 ) * 4 ), $path ) ) {
			return;
		}
		$data = file_get_contents( $path );
		if ( false === $data ) {
			$this->note( "Not captured: $path could not be read." );
			return;
		}
		$this->files[ $path ] = array(
			'path'    => $path,
			'existed' => true,
			'content' => base64_encode( $data ),
			'sha256'  => hash( 'sha256', $data ),
			'perms'   => fileperms( $path ) & 0777,
		);
	}

	/** A whole folder as a zip (a plugin or theme an upgrader is about to replace). */
	public function archive( $dir ) {
		$dir = self::abs( $dir );
		if ( isset( $this->archives[ $dir ] ) || isset( $this->files[ $dir ] ) ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			$this->archives[ $dir ] = array( 'path' => $dir, 'existed' => false );
			Kico_Recorder::skip( $dir );
			return;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->file( $dir );
			return;
		}
		$tmp = tempnam( get_temp_dir(), 'kico-archive' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			$this->file( $dir );
			return;
		}
		$count = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			$local = substr( wp_normalize_path( $f->getPathname() ), strlen( $dir ) + 1 );
			if ( $f->isDir() ) {
				$zip->addEmptyDir( $local );
			} else {
				$zip->addFile( $f->getPathname(), $local );
				++$count;
			}
		}
		$zip->close();
		$data = (string) @file_get_contents( $tmp );
		@unlink( $tmp );
		if ( ! $this->room( (int) ( ceil( strlen( $data ) / 3 ) * 4 ), "the folder $dir" ) ) {
			return;
		}
		$this->archives[ $dir ] = array( 'path' => $dir, 'existed' => true, 'zip' => base64_encode( $data ), 'files' => $count );
		// The zip keeps the folder as it was; the recorder need not copy its files.
		Kico_Recorder::skip( $dir );
	}

	// --- rows --------------------------------------------------------------------------------------

	/** Rows of a table matching a condition the plugin wrote (never the model's SQL). */
	public function rows( $table, $where ) {
		global $wpdb;
		foreach ( $this->rows as $r ) {
			if ( $r['table'] === $table && $r['where'] === $where ) {
				return;
			}
		}
		$rows = $wpdb->get_results( "SELECT * FROM `$table` WHERE $where", ARRAY_A );
		$size = strlen( (string) wp_json_encode( $rows ) );
		if ( ! $this->room( $size, "rows of $table where $where" ) ) {
			return;
		}
		$this->rows[]            = array( 'table' => $table, 'where' => $where, 'rows' => $rows ?: array() );
		$this->touched[ $table ] = true;
	}

	/** A whole table, with its definition. */
	public function table( $table, $why = '' ) {
		global $wpdb;
		foreach ( $this->tables as $t ) {
			if ( $t['table'] === $table ) {
				return;
			}
		}
		$this->touched[ $table ] = true;
		$exists                  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			$this->tables[] = array( 'table' => $table, 'existed' => false );
			return;
		}
		$size = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT data_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
		if ( ! $this->room( $size, "the table $table" ) ) {
			return;
		}
		$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
		$rows   = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
		// The real size, now that it is read.
		$this->bytes   += max( 0, strlen( (string) wp_json_encode( $rows ) ) - $size );
		$this->tables[] = array( 'table' => $table, 'existed' => true, 'create' => $create ? $create[1] : '', 'rows' => $rows ?: array() );
		$this->whole[]  = $table;
		$this->note( "The table $table is kept whole" . ( $why ? " ($why)" : '' ) . ': rolling back replaces the table, so changes made to it after this operation are lost.' );
	}

	/** The tables of this site: its prefix exactly (on multisite, not other sites' tables), without Kico's own. */
	public static function site_tables() {
		global $wpdb;
		$all   = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$other = array();
		if ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix ) {
			// The main site's prefix is also the start of every other site's.
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
				if ( 1 !== (int) $id ) {
					$other[] = $wpdb->base_prefix . (int) $id . '_';
				}
			}
		}
		$out = array();
		foreach ( (array) $all as $t ) {
			if ( self::own_table( $t ) ) {
				continue;
			}
			foreach ( $other as $p ) {
				if ( 0 === strpos( $t, $p ) ) {
					continue 2;
				}
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Kico's own tables (audit log, nonces, blobs) on any site: never snapshotted or restored. */
	public static function own_table( $table ) {
		return Kico_Store::is_own_table( $table );
	}

	/** Every table of this site, as far as the limit goes. */
	public function database( array $only = array() ) {
		foreach ( $only ? $only : self::site_tables() as $t ) {
			if ( ! self::own_table( $t ) ) {
				$this->table( $t, $only ? 'asked for' : '' );
			}
		}
	}

	/** A post and its meta. */
	public function post( $id ) {
		global $wpdb;
		$id = (int) $id;
		$this->rows( $wpdb->posts, "ID = $id" );
		$this->rows( $wpdb->postmeta, "post_id = $id" );
		$this->posts[ $id ] = true;
	}

	public function created( $type, $id ) {
		$this->created[]           = array( 'type' => $type, 'id' => (int) $id );
		$this->posts[ (int) $id ] = true;
	}

	// --- rows by key, from SQL statements ------------------------------------------------------------

	private static $key_cache = array();

	/** The columns that identify a row: the primary key, or a unique key of NOT NULL columns. */
	public static function key_of( $table ) {
		$u = self::uniques( $table );
		return $u ? reset( $u ) : null;
	}

	/** Unique keys of a table, the primary key first: name => columns (only keys of NOT NULL columns). */
	public static function uniques( $table ) {
		global $wpdb;
		if ( isset( self::$key_cache[ $table ] ) ) {
			return self::$key_cache[ $table ]['uniques'];
		}
		$keys = array();
		$null = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A ) as $c ) {
			$null[ $c['Field'] ] = 'YES' === $c['Null'];
			if ( false !== stripos( (string) $c['Extra'], 'auto_increment' ) ) {
				self::$key_cache[ $table ]['ai'] = $c['Field'];
			}
		}
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ) as $i ) {
			if ( '0' === (string) $i['Non_unique'] ) {
				$keys[ $i['Key_name'] ][ (int) $i['Seq_in_index'] ] = $i['Column_name'];
			}
		}
		$out = array();
		if ( isset( $keys['PRIMARY'] ) ) {
			ksort( $keys['PRIMARY'] );
			$out['PRIMARY'] = array_values( $keys['PRIMARY'] );
		}
		foreach ( $keys as $name => $cols ) {
			ksort( $cols );
			$cols = array_values( $cols );
			$ok   = true;
			foreach ( $cols as $c ) {
				$ok = $ok && empty( $null[ $c ] );
			}
			if ( 'PRIMARY' !== $name && $ok ) {
				$out[ $name ] = $cols;
			}
		}
		self::$key_cache[ $table ]['uniques'] = $out;
		self::$key_cache[ $table ]['columns'] = array_keys( $null );
		return $out;
	}

	private static function ai_of( $table ) {
		self::uniques( $table );
		return self::$key_cache[ $table ]['ai'] ?? null;
	}

	private static function columns_of( $table ) {
		self::uniques( $table );
		return self::$key_cache[ $table ]['columns'] ?? array();
	}

	private static function q( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	private static function value( $v ) {
		global $wpdb;
		return null === $v ? 'NULL' : "'" . $wpdb->_real_escape( (string) $v ) . "'";
	}

	/** A condition matching rows by these keys (each a list of values in pk order). */
	private static function key_where( array $pk, array $keys, $alias = '' ) {
		$a = $alias ? self::q( $alias ) . '.' : '';
		if ( 1 === count( $pk ) ) {
			return $a . self::q( $pk[0] ) . ' IN (' . implode( ',', array_map( function ( $k ) {
				return self::value( $k[0] );
			}, $keys ) ) . ')';
		}
		$parts = array();
		foreach ( $keys as $k ) {
			$and = array();
			foreach ( $pk as $i => $c ) {
				$and[] = $a . self::q( $c ) . ' = ' . self::value( $k[ $i ] );
			}
			$parts[] = '(' . implode( ' AND ', $and ) . ')';
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	private static function key_values( array $pk, array $row ) {
		$k = array();
		foreach ( $pk as $c ) {
			$k[] = isset( $row[ $c ] ) ? (string) $row[ $c ] : null;
		}
		return $k;
	}

	/**
	 * Rows that hold one of these rows' natural keys (a unique key of the table
	 * other than $pk, as the database has it) under another primary key: an
	 * option deleted and added again since, with a new option_id. Each: the
	 * columns of that key, the row as kept, and the row there now.
	 */
	private static function natural_matches( $table, array $pk, array $rows, $lock = false ) {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$own = array();
		foreach ( $rows as $r ) {
			$own[ wp_json_encode( self::key_values( $pk, $r ) ) ] = true;
		}
		$out  = array();
		$seen = array();
		foreach ( self::uniques( $table ) as $cols ) {
			if ( $cols === $pk ) {
				continue;
			}
			$want = array();
			foreach ( $rows as $r ) {
				$v = self::key_values( $cols, $r );
				if ( ! in_array( null, $v, true ) ) {
					$want[ strtolower( (string) wp_json_encode( $v ) ) ] = array( 'v' => $v, 'row' => $r );
				}
			}
			foreach ( array_chunk( $want, 500, true ) as $chunk ) {
				$where = self::key_where( $cols, array_column( $chunk, 'v' ) );
				foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::q( $table ) . " WHERE $where" . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_A ) as $now ) {
					$pj = wp_json_encode( self::key_values( $pk, $now ) );
					if ( isset( $own[ $pj ] ) || isset( $seen[ $pj ] ) ) {
						continue;
					}
					$seen[ $pj ] = true;
					$vj          = strtolower( (string) wp_json_encode( self::key_values( $cols, $now ) ) );
					$out[]       = array( 'cols' => $cols, 'kept' => $want[ $vj ]['row'] ?? null, 'now' => $now );
				}
			}
		}
		return $out;
	}

	private function keyed( $table ) {
		if ( ! isset( $this->keys[ $table ] ) ) {
			$this->keys[ $table ] = array( 'table' => $table, 'pk' => self::key_of( $table ), 'rows' => array(), 'absent' => array(), 'after' => array() );
		}
		$this->touched[ $table ] = true;
		return $this->keys[ $table ]['pk'];
	}

	private function known( $table, $kj ) {
		return isset( $this->keys[ $table ]['rows'][ $kj ] ) || isset( $this->keys[ $table ]['absent'][ $kj ] );
	}

	/** Keeps the current rows of these keys (first touch wins); keys with no row are kept as absent. */
	private function keep_keys( $table, array $keys, $lock = false ) {
		global $wpdb;
		$pk  = $this->keyed( $table );
		$new = array();
		foreach ( $keys as $k ) {
			$kj = wp_json_encode( $k );
			if ( ! $this->known( $table, $kj ) ) {
				$new[ $kj ] = $k;
			}
		}
		if ( ! $new ) {
			return true;
		}
		$avg = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT avg_row_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
		if ( $this->bytes + count( $new ) * max( 64, $avg ) > $this->limit() ) {
			$this->room( count( $new ) * max( 64, $avg ), sprintf( '%d rows of %s', count( $new ), $table ) );
			return false;
		}
		$found = array();
		foreach ( array_chunk( $new, 500, true ) as $chunk ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::q( $table ) . ' WHERE ' . self::key_where( $pk, array_values( $chunk ) ) . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$kj           = wp_json_encode( self::key_values( $pk, $r ) );
				$found[ $kj ] = $r;
			}
		}
		$size = strlen( (string) wp_json_encode( $found ) );
		if ( ! $this->room( $size, sprintf( '%d rows of %s', count( $found ), $table ) ) ) {
			return false;
		}
		foreach ( $new as $kj => $k ) {
			if ( isset( $found[ $kj ] ) ) {
				$this->keys[ $table ]['rows'][ $kj ] = $found[ $kj ];
			} else {
				$this->keys[ $table ]['absent'][ $kj ] = $k;
			}
		}
		return true;
	}

	/**
	 * Captures what one SQL statement is about to change. $lock (sql_write):
	 * inside a transaction, with the rows locked and the clock fixed until
	 * statement_done(), so what the statement changes is exactly what was kept.
	 */
	public function statement( $sql, $lock = false ) {
		global $wpdb;
		$this->finish_pending();
		$plan = Kico_Sql::plan( $sql );
		if ( 'none' === $plan['kind'] ) {
			return;
		}
		if ( 'unknown' === $plan['kind'] ) {
			$tables = Kico_Tools::tables_of( $sql );
			if ( null === $tables || ! $tables ) {
				$this->note( 'Not captured: which rows or tables a statement changes could not be told (' . $plan['why'] . '): ' . Kico_Store::clip( $sql, 200 ) . ' Pass tables to keep them whole first.' );
				return;
			}
			foreach ( $tables as $t ) {
				$this->table( $t, $plan['why'] );
			}
			return;
		}
		if ( 'tables' === $plan['kind'] ) {
			foreach ( $plan['tables'] as $t ) {
				if ( ! self::own_table( $t ) ) {
					$this->table( $t, $plan['why'] );
				}
			}
			return;
		}
		if ( $lock && ! $this->tx ) {
			$wpdb->query( 'START TRANSACTION' );
			$ts = $wpdb->get_var( 'SELECT UNIX_TIMESTAMP(NOW(6))' );
			if ( $ts ) {
				$wpdb->query( 'SET timestamp = ' . (float) $ts );
			}
			$this->tx = true;
		}
		if ( 'rows' === $plan['kind'] ) {
			foreach ( $plan['targets'] as $t ) {
				$this->capture_target( $plan, $t, $lock );
			}
			return;
		}
		$this->capture_insert( $plan, $lock );
	}

	private function capture_target( array $plan, array $t, $lock ) {
		global $wpdb;
		$table = $t['table'];
		$alias = $t['alias'];
		if ( null === $table ) {
			// An unqualified column of a multi-table UPDATE: the table that has it.
			$hits = array();
			foreach ( $plan['aliases'] as $a => $tb ) {
				if ( array_intersect( $t['columns'], self::columns_of( $tb ) ) ) {
					$hits[ $a ] = $tb;
				}
			}
			if ( 1 !== count( $hits ) ) {
				foreach ( array_unique( $plan['aliases'] ) as $tb ) {
					$this->table( $tb, 'the columns it sets could not be told apart' );
				}
				return;
			}
			$alias = key( $hits );
			$table = reset( $hits );
		}
		if ( self::own_table( $table ) ) {
			return;
		}
		$pk = self::key_of( $table );
		if ( ! $pk ) {
			$this->table( $table, 'it has no primary or unique key' );
			return;
		}
		if ( 'UPDATE' === $plan['op'] && array_intersect( $pk, (array) ( $t['columns'] ?? array() ) ) ) {
			$this->table( $table, 'the statement changes its key' );
			return;
		}
		$a    = $t['single'] ? '' : self::q( $alias ) . '.';
		$cols = implode( ', ', array_map( function ( $c ) use ( $a ) {
			return $a . self::q( $c );
		}, $pk ) );
		$sql  = "SELECT $cols FROM {$t['from']}" . ( '' !== $t['where'] ? " WHERE {$t['where']}" : '' );
		if ( $t['single'] ) {
			$sql .= ( '' !== $t['order'] ? " ORDER BY {$t['order']}" : '' ) . ( '' !== $t['limit'] ? " LIMIT {$t['limit']}" : '' );
		}
		$suppress = $wpdb->suppress_errors( true );
		$keys     = $wpdb->get_results( $sql . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_N );
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( $error ) {
			$this->table( $table, 'its rows could not be read: ' . $error );
			return;
		}
		$uniq = array();
		foreach ( (array) $keys as $k ) {
			$uniq[ wp_json_encode( $k ) ] = array_map( 'strval', $k );
		}
		if ( $this->keep_keys( $table, array_values( $uniq ), $lock ) && 'DELETE' === $plan['op'] && $t['single'] ) {
			$this->pending = array( 'check' => 'delete', 'table' => $table, 'count' => count( $uniq ) );
		}
	}

	private function capture_insert( array $plan, $lock ) {
		global $wpdb;
		$table = $plan['table'];
		if ( self::own_table( $table ) ) {
			return;
		}
		$uniques = self::uniques( $table );
		$pk      = self::key_of( $table );
		$ai      = self::ai_of( $table );
		if ( ! $pk ) {
			$this->table( $table, 'it has no primary or unique key' );
			return;
		}
		$idx     = array_flip( $plan['columns'] );
		$lookups = array();
		$missing = false;
		foreach ( $uniques as $name => $cols ) {
			// An auto-increment key the statement does not set cannot conflict.
			if ( 1 === count( $cols ) && $cols[0] === $ai && ! isset( $idx[ $ai ] ) ) {
				continue;
			}
			$tuples = array();
			foreach ( $plan['rows'] as $row ) {
				$vals = array();
				foreach ( $cols as $c ) {
					if ( ! isset( $idx[ $c ] ) || null === $row[ $idx[ $c ] ] ) {
						$vals = null;
						break;
					}
					$vals[] = $row[ $idx[ $c ] ];
				}
				if ( null === $vals ) {
					$tuples = null;
					break;
				}
				$tuples[] = '(' . implode( ',', $vals ) . ')';
			}
			if ( null === $tuples ) {
				$missing = true;
				continue;
			}
			$lookups[] = '(' . implode( ',', array_map( array( __CLASS__, 'q' ), $cols ) ) . ') IN (' . implode( ',', $tuples ) . ')';
		}
		$overwrites = $plan['replace'] || $plan['update'];
		$by_range   = ! $overwrites && $ai && array( $ai ) === $pk && ! isset( $idx[ $ai ] );
		if ( ( $overwrites && $missing ) || ( ! $lookups && ! $by_range ) ) {
			$this->table( $table, 'the rows it writes cannot be told from the statement' );
			return;
		}
		$where = $lookups ? implode( ' OR ', $lookups ) : '';
		if ( $where ) {
			$suppress = $wpdb->suppress_errors( true );
			$keys     = $wpdb->get_results( 'SELECT ' . implode( ', ', array_map( array( __CLASS__, 'q' ), $pk ) ) . ' FROM ' . self::q( $table ) . " WHERE $where" . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_N );
			$error    = $wpdb->last_error;
			$wpdb->suppress_errors( $suppress );
			if ( $error ) {
				$this->table( $table, 'its rows could not be read: ' . $error );
				return;
			}
			$this->keep_keys( $table, array_map( function ( $k ) {
				return array_map( 'strval', $k );
			}, (array) $keys ), $lock );
		} else {
			$this->keyed( $table );
		}
		$this->pending = array( 'check' => 'insert', 'table' => $table, 'where' => $where, 'range' => $by_range, 'count' => count( $plan['rows'] ) );
		// wpdb sets insert_id only for a statement that starts with INSERT or REPLACE and keeps
		// the last one otherwise (often the audit log's): 0 until this statement sets it.
		$wpdb->insert_id = 0;
	}

	/** After an INSERT ran: the keys it created. After a DELETE: whether it removed more than was kept. */
	private function finish_pending( $affected = null ) {
		global $wpdb;
		$p = $this->pending;
		if ( ! $p ) {
			return;
		}
		$this->pending = null;
		$table         = $p['table'];
		if ( 'delete' === $p['check'] ) {
			if ( null !== $affected && $affected > $p['count'] ) {
				$this->note( sprintf( 'The DELETE on %s removed %d rows, the snapshot kept %d: rows removed by triggers or cascades are not in it.', $table, $affected, $p['count'] ) );
			}
			return;
		}
		$insert_id = (int) $wpdb->insert_id;
		$pk        = $this->keys[ $table ]['pk'] ?? self::key_of( $table );
		$keys      = array();
		if ( $p['where'] ) {
			$keys = (array) $wpdb->get_results( 'SELECT ' . implode( ', ', array_map( array( __CLASS__, 'q' ), $pk ) ) . ' FROM ' . self::q( $table ) . " WHERE {$p['where']}", ARRAY_N );
		}
		if ( $p['range'] && $insert_id <= 0 && ! $keys ) {
			$this->note( sprintf( 'Which rows an INSERT on %s created could not be told: rolling back leaves them.', $table ) );
		}
		if ( $p['range'] && $insert_id > 0 ) {
			$keys = array_merge( $keys, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::q( $pk[0] ) . ' FROM ' . self::q( $table ) . ' WHERE ' . self::q( $pk[0] ) . ' BETWEEN %d AND %d', $insert_id, $insert_id + max( 1, $p['count'] ) - 1 ), ARRAY_N ) );
		}
		foreach ( $keys as $k ) {
			$k  = array_map( 'strval', $k );
			$kj = wp_json_encode( $k );
			if ( ! $this->known( $table, $kj ) ) {
				$this->keys[ $table ]['absent'][ $kj ] = $k;
				$this->bytes                         += strlen( $kj ) + 4;
			}
		}
	}

	/** sql_write: the statement ran (or failed); release the transaction. */
	public function statement_done( $affected, $failed = false ) {
		global $wpdb;
		if ( ! $failed ) {
			$this->finish_pending( $affected );
		} else {
			$this->pending = null;
		}
		if ( $this->tx ) {
			$wpdb->query( $failed ? 'ROLLBACK' : 'COMMIT' );
			$wpdb->query( 'SET timestamp = DEFAULT' );
			$this->tx = false;
		}
	}

	/** The "query" filter while code runs: each write through $wpdb is captured before it runs. */
	public function on_query( $query ) {
		if ( $this->in_query || ! $this->journal || ! Kico_Sql::is_write( $query ) ) {
			return $query;
		}
		$this->in_query = true;
		try {
			$this->statement( $query, false );
		} catch ( Throwable $e ) {
			$this->note( 'A query could not be captured: ' . $e->getMessage() );
		} finally {
			$this->in_query = false;
		}
		return $query;
	}

	// --- the run -----------------------------------------------------------------------------------

	/** Before the tool runs: start recording, when the capture asked for it. */
	public function begin() {
		if ( $this->begun ) {
			return;
		}
		$this->begun = true;
		if ( ! $this->record ) {
			return;
		}
		$this->journal = true;
		add_filter( 'query', array( $this, 'on_query' ), PHP_INT_MAX );
		if ( $this->record_files ) {
			if ( $this->use_manifest ) {
				$this->manifest = self::manifest();
			}
			add_filter( 'upgrader_pre_install', array( $this, 'on_upgrade' ), 1, 2 );
			if ( ! Kico_Recorder::start( $this ) ) {
				$this->note( 'Files changed by this call are not recorded: PHP did not let the plugin watch file writes (stream_wrapper_register).' );
			}
		}
	}

	/** An upgrader is about to replace a plugin or theme: keep the folder as a zip. */
	public function on_upgrade( $ok, $extra ) {
		$dir = null;
		if ( ! empty( $extra['plugin'] ) && false !== strpos( (string) $extra['plugin'], '/' ) ) {
			$dir = WP_PLUGIN_DIR . '/' . dirname( (string) $extra['plugin'] );
		} elseif ( ! empty( $extra['theme'] ) ) {
			$dir = get_theme_root( (string) $extra['theme'] ) . '/' . $extra['theme'];
		}
		if ( $dir ) {
			// Reads pass through the recorder, and the zip is written in the temp folder it skips.
			$this->archive( $dir );
		}
		return $ok;
	}

	/** Adds what another process recorded (WP-CLI with kico-cli-recorder.php). */
	public function merge( array $child ) {
		foreach ( (array) ( $child['files'] ?? array() ) as $f ) {
			$abs = self::abs( $f['path'] );
			if ( ! $this->has_file( $abs ) ) {
				$f['path']           = $abs;
				$this->files[ $abs ] = $f;
			}
		}
		foreach ( (array) ( $child['archives'] ?? array() ) as $a ) {
			$abs = self::abs( $a['path'] );
			if ( ! isset( $this->archives[ $abs ] ) ) {
				$a['path']              = $abs;
				$this->archives[ $abs ] = $a;
			}
		}
		foreach ( (array) ( $child['keys'] ?? array() ) as $k ) {
			$t = $k['table'];
			if ( ! isset( $this->keys[ $t ] ) ) {
				$this->keys[ $t ] = array( 'table' => $t, 'pk' => $k['pk'], 'rows' => array(), 'absent' => array(), 'after' => array() );
			}
			foreach ( (array) $k['rows'] as $r ) {
				$kj = wp_json_encode( self::key_values( $k['pk'], $r ) );
				if ( ! $this->known( $t, $kj ) ) {
					$this->keys[ $t ]['rows'][ $kj ] = $r;
				}
			}
			foreach ( (array) $k['absent'] as $v ) {
				$kj = wp_json_encode( $v );
				if ( ! $this->known( $t, $kj ) ) {
					$this->keys[ $t ]['absent'][ $kj ] = $v;
				}
			}
			$this->touched[ $t ] = true;
		}
		foreach ( (array) ( $child['tables'] ?? array() ) as $t ) {
			$this->tables[]                = $t;
			$this->touched[ $t['table'] ] = true;
		}
		foreach ( (array) ( $child['notes'] ?? array() ) as $n ) {
			$this->note( $n );
		}
		$this->bytes += (int) ( $child['bytes'] ?? 0 );
	}

	/**
	 * After the tool ran (or from a shutdown handler after a fatal error):
	 * stop recording, compare the manifest, note each kept row's state now
	 * (for "changed since" on rollback), purge caches. Returns what to add to
	 * the result.
	 */
	public function end( $tool = '', array $args = array(), $ok = true ) {
		if ( $this->ended ) {
			return array();
		}
		$this->ended = true;
		$this->stop();
		$this->after();
		$this->invalidate();
		$posts = array_keys( $this->posts );
		foreach ( $this->created as $c ) {
			if ( 'post' === $c['type'] ) {
				$posts[] = (int) $c['id'];
			}
		}
		return Kico_Tools::after_write( $tool, $posts, 'none' !== $this->kind() );
	}

	/** Stops recording: the recorder, the journal, the open statement, the manifest. */
	public function stop() {
		Kico_Recorder::stop();
		remove_filter( 'upgrader_pre_install', array( $this, 'on_upgrade' ), 1 );
		if ( $this->journal ) {
			remove_filter( 'query', array( $this, 'on_query' ), PHP_INT_MAX );
			$this->journal = false;
		}
		$this->finish_pending();
		if ( $this->tx ) {
			$this->statement_done( null, false );
		}
		if ( null !== $this->manifest ) {
			$this->compare( $this->manifest, self::manifest() );
			$this->manifest = null;
		}
	}

	/** Takes over what another snapshot captured (rollback: the current state before restoring). */
	public function absorb( Kico_Snapshot $o ) {
		foreach ( array( 'files', 'archives', 'keys', 'rows', 'tables', 'created', 'notes', 'whole', 'posts', 'touched' ) as $k ) {
			$this->$k = $o->$k;
		}
		$this->bytes = $o->bytes;
	}

	/**
	 * The object cache after the write: the posts and options it changed, by
	 * key; everything when a table was kept whole or is not one WordPress
	 * caches by key. Without a persistent object cache, only this request's.
	 */
	private function invalidate() {
		global $wpdb;
		if ( ! $this->touched && ! $this->posts ) {
			return;
		}
		if ( ! wp_using_ext_object_cache() || $this->tables ) {
			wp_cache_flush();
			return;
		}
		$posts   = array_keys( $this->posts );
		$options = array();
		$flush   = false;
		foreach ( $this->rows as $r ) {
			if ( $r['table'] === $wpdb->options ) {
				foreach ( $r['rows'] as $row ) {
					$options[] = $row['option_name'];
				}
			} elseif ( $r['table'] !== $wpdb->posts && $r['table'] !== $wpdb->postmeta ) {
				$flush = true;
			}
		}
		foreach ( $this->keys as $t => $k ) {
			$ids = array();
			foreach ( $k['absent'] as $v ) {
				$ids[] = (int) $v[0];
			}
			if ( $t === $wpdb->posts ) {
				foreach ( $k['rows'] as $r ) {
					$posts[] = (int) $r['ID'];
				}
				$posts = array_merge( $posts, $ids );
			} elseif ( $t === $wpdb->postmeta ) {
				foreach ( $k['rows'] as $r ) {
					$posts[] = (int) $r['post_id'];
				}
				if ( $ids ) {
					$posts = array_merge( $posts, array_map( 'intval', (array) $wpdb->get_col( 'SELECT post_id FROM ' . self::q( $t ) . ' WHERE meta_id IN (' . implode( ',', $ids ) . ')' ) ) );
				}
			} elseif ( $t === $wpdb->options ) {
				foreach ( $k['rows'] as $r ) {
					$options[] = $r['option_name'];
				}
				if ( $ids ) {
					$options = array_merge( $options, (array) $wpdb->get_col( 'SELECT option_name FROM ' . self::q( $t ) . ' WHERE option_id IN (' . implode( ',', $ids ) . ')' ) );
				}
			} else {
				$flush = true;
			}
		}
		if ( $flush ) {
			wp_cache_flush();
			return;
		}
		foreach ( array_unique( $posts ) as $id ) {
			clean_post_cache( (int) $id );
		}
		if ( $options ) {
			foreach ( array_unique( $options ) as $name ) {
				wp_cache_delete( $name, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/** Each kept key's row as it is after the write, to tell on rollback whether it changed since. */
	private function after() {
		global $wpdb;
		foreach ( $this->keys as $t => $k ) {
			$all = array();
			foreach ( $k['rows'] as $kj => $r ) {
				$all[ $kj ] = self::key_values( $k['pk'], $r );
			}
			foreach ( $k['absent'] as $kj => $v ) {
				$all[ $kj ] = $v;
			}
			$now = array();
			foreach ( array_chunk( $all, 500, true ) as $chunk ) {
				foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::q( $t ) . ' WHERE ' . self::key_where( $k['pk'], array_values( $chunk ) ), ARRAY_A ) as $r ) {
					$now[ wp_json_encode( self::key_values( $k['pk'], $r ) ) ] = self::row_hash( $r );
				}
			}
			foreach ( $all as $kj => $_ ) {
				$this->keys[ $t ]['after'][ $kj ] = $now[ $kj ] ?? null;
			}
			$this->bytes += 50 * count( $all );
		}
	}

	private static function row_hash( array $r ) {
		return substr( sha1( (string) wp_json_encode( array_map( function ( $v ) {
			return null === $v ? null : (string) $v;
		}, $r ) ) ), 0, 16 );
	}

	// --- the manifest ------------------------------------------------------------------------------

	/** size, mtime and inode of every file in wp-content and the root (bounded). */
	public static function manifest() {
		$out   = array();
		$start = microtime( true );
		$max   = defined( 'KICO_MANIFEST_MAX_FILES' ) ? (int) KICO_MANIFEST_MAX_FILES : 100000;
		$skip  = array();
		foreach ( array( WP_CONTENT_DIR . '/cache', WP_CONTENT_DIR . '/upgrade', WP_CONTENT_DIR . '/upgrade-temp-backup' ) as $d ) {
			$skip[ wp_normalize_path( $d ) ] = true;
		}
		$root = rtrim( self::root(), '/' );
		foreach ( (array) @scandir( $root ) as $n ) {
			if ( '' !== $n && '.' !== $n && '..' !== $n && is_file( "$root/$n" ) ) {
				$st                 = @stat( "$root/$n" );
				$out[ "$root/$n" ] = $st ? $st['size'] . ':' . $st['mtime'] . ':' . $st['ino'] : '?';
			}
		}
		$parent = dirname( $root ) . '/wp-config.php';
		if ( ! isset( $out[ "$root/wp-config.php" ] ) && @is_file( $parent ) ) {
			$st             = @stat( $parent );
			$out[ $parent ] = $st ? $st['size'] . ':' . $st['mtime'] . ':' . $st['ino'] : '?';
		}
		$stack = array( wp_normalize_path( WP_CONTENT_DIR ) );
		while ( $stack ) {
			$dir   = array_pop( $stack );
			$items = @scandir( $dir );
			if ( false === $items ) {
				continue;
			}
			$out[ $dir . '/' ] = 'dir';
			foreach ( $items as $n ) {
				if ( '.' === $n || '..' === $n ) {
					continue;
				}
				$p = "$dir/$n";
				if ( is_dir( $p ) && ! is_link( $p ) ) {
					if ( ! isset( $skip[ $p ] ) ) {
						$stack[] = $p;
					}
					continue;
				}
				$st        = @lstat( $p );
				$out[ $p ] = $st ? $st['size'] . ':' . $st['mtime'] . ':' . $st['ino'] : '?';
				if ( count( $out ) >= $max || microtime( true ) - $start > 5 ) {
					$out["\0truncated"] = true;
					return $out;
				}
			}
		}
		return $out;
	}

	private function compare( array $before, array $after ) {
		if ( isset( $before["\0truncated"] ) || isset( $after["\0truncated"] ) ) {
			$this->note( 'The site has too many files to compare them all before and after; changes PHP\'s file functions did not make (another process, an extension) may be missing from this snapshot.' );
		}
		$missed = array();
		foreach ( $after as $p => $sig ) {
			if ( "\0truncated" === $p ) {
				continue;
			}
			$abs = rtrim( $p, '/' );
			if ( $this->has_file( $abs ) || Kico_Recorder::skipped( $abs ) ) {
				continue;
			}
			if ( ! isset( $before[ $p ] ) ) {
				// Created by something the recorder did not see: a rollback deletes it.
				$this->files[ $abs ] = 'dir' === $sig ? array( 'path' => $abs, 'dir' => true, 'existed' => false ) : array( 'path' => $abs, 'existed' => false );
			} elseif ( $before[ $p ] !== $sig ) {
				$missed[] = self::rel( $abs );
			}
		}
		foreach ( $before as $p => $sig ) {
			if ( "\0truncated" !== $p && ! isset( $after[ $p ] ) && ! $this->has_file( rtrim( $p, '/' ) ) && ! Kico_Recorder::skipped( rtrim( $p, '/' ) ) ) {
				$missed[] = self::rel( rtrim( $p, '/' ) ) . ' (deleted)';
			}
		}
		if ( $missed ) {
			$this->note( sprintf( 'Changed outside PHP\'s file functions, so their old content is not in the snapshot: %s%s.', implode( ', ', array_slice( $missed, 0, 20 ) ), count( $missed ) > 20 ? sprintf( ' and %d more', count( $missed ) - 20 ) : '' ) );
		}
	}

	// --- export ------------------------------------------------------------------------------------

	public function kind() {
		$kinds = array();
		foreach ( array( 'files', 'archives', 'keys', 'rows', 'tables', 'created' ) as $k ) {
			if ( $this->$k ) {
				$kinds[] = $k;
			}
		}
		return $kinds ? implode( '+', $kinds ) : 'none';
	}

	public function export() {
		$files = array();
		foreach ( $this->files as $f ) {
			$f['path'] = self::rel( $f['path'] );
			$files[]   = $f;
		}
		$archives = array();
		foreach ( $this->archives as $a ) {
			$a['path']  = self::rel( $a['path'] );
			$archives[] = $a;
		}
		$keys = array();
		foreach ( $this->keys as $k ) {
			if ( $k['rows'] || $k['absent'] ) {
				$keys[] = array(
					'table'  => $k['table'],
					'pk'     => $k['pk'],
					'rows'   => array_values( $k['rows'] ),
					'absent' => array_values( $k['absent'] ),
					'after'  => $k['after'],
				);
			}
		}
		$out = array(
			'v'        => self::VERSION,
			'root'     => self::root(),
			'kind'     => $this->kind(),
			'files'    => $files,
			'archives' => $archives,
			'keys'     => $keys,
			'rows'     => $this->rows,
			'tables'   => $this->tables,
			'created'  => $this->created,
			'notes'    => array_values( $this->notes ),
		);
		$out['bytes'] = strlen( (string) wp_json_encode( $out ) );
		return $out;
	}

	// --- rollback ----------------------------------------------------------------------------------

	/** The current state of everything a snapshot covers (before restoring it). */
	public static function inverse( array $s ) {
		global $wpdb;
		$now = new self();
		foreach ( $s['archives'] ?? array() as $a ) {
			$now->archive( $a['path'] );
		}
		foreach ( $s['files'] ?? array() as $f ) {
			$now->file( $f['path'] );
		}
		foreach ( $s['keys'] ?? array() as $k ) {
			$keys = array();
			foreach ( $k['rows'] as $r ) {
				$keys[] = self::key_values( $k['pk'], $r );
			}
			foreach ( $k['absent'] as $v ) {
				$keys[] = $v;
			}
			$now->keys[ $k['table'] ] = array( 'table' => $k['table'], 'pk' => $k['pk'], 'rows' => array(), 'absent' => array(), 'after' => array() );
			// Rows the rollback replaces by natural key are kept too, so it can be rolled back.
			foreach ( self::natural_matches( $k['table'], $k['pk'], $k['rows'] ) as $m ) {
				$keys[] = self::key_values( $k['pk'], $m['now'] );
			}
			$now->keep_keys( $k['table'], $keys );
		}
		foreach ( $s['rows'] ?? array() as $r ) {
			$now->rows( $r['table'], $r['where'] );
		}
		foreach ( $s['tables'] ?? array() as $t ) {
			$now->table( $t['table'] );
		}
		foreach ( $s['created'] ?? array() as $c ) {
			$now->post( $c['id'] );
			if ( 'attachment' === $c['type'] ) {
				$now->note( sprintf( 'The files of attachment %d are deleted by this rollback and not kept.', $c['id'] ) );
			}
		}
		// The rollback's own after-state is the restored one; its keys' "after" is filled by end().
		return $now;
	}

	/**
	 * Puts back what a snapshot recorded. Every write is checked; anything that
	 * did not take makes the result ok: false, with what was done and what failed.
	 * Files are checked first: when one cannot be written, nothing is changed.
	 *
	 * A kept row whose primary key changed since is matched by its natural key
	 * (the table's other unique keys, read from the database) and replaces the
	 * row holding it now; matched_by_natural_key lists them.
	 *
	 * @return array ok, done, failed, warnings, changed_since, matched_by_natural_key.
	 */
	public static function restore( array $s, array $o = array() ) {
		global $wpdb;
		$done     = array();
		$failed   = array();
		$warnings = array();
		$changed  = array();
		$natural  = array();

		$files = array();
		foreach ( $s['files'] ?? array() as $f ) {
			$f['path']             = self::abs( $f['path'] );
			$files[ $f['path'] ] = $f;
		}
		$archives = array();
		foreach ( $s['archives'] ?? array() as $a ) {
			$a['path']                = self::abs( $a['path'] );
			$archives[ $a['path'] ] = $a;
		}

		// 1. Can every file be put back? If not, change nothing.
		$blocked = array();
		foreach ( $files as $p => $f ) {
			$why = Kico_Tools::cannot_write( $p, empty( $f['existed'] ) ? 'delete' : 'write' );
			if ( $why ) {
				$blocked[] = $why;
			}
		}
		foreach ( $archives as $p => $a ) {
			$why = Kico_Tools::cannot_write( $p, 'replace' );
			if ( $why ) {
				$blocked[] = $why;
			}
		}
		if ( $blocked && empty( $o['partial'] ) ) {
			return array(
				'ok'     => false,
				'error'  => 'Nothing was rolled back: ' . count( $blocked ) . ' file(s) cannot be put back. ' . implode( ' ', array_slice( $blocked, 0, 10 ) ) . ' Fix the permissions (env shows the PHP user and owners) and roll back again, or pass partial: true to restore what can be.',
				'done'   => array(),
				'failed' => $blocked,
			);
		}

		// 2. The database, in a transaction: tables whose definition changed are recreated first.
		$recreate = array();
		foreach ( $s['tables'] ?? array() as $t ) {
			if ( self::own_table( $t['table'] ) ) {
				continue;
			}
			if ( ! empty( $t['existed'] ) ) {
				$current = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::q( $t['table'] ), ARRAY_N );
				if ( ! $current || self::schema( $current[1] ) !== self::schema( $t['create'] ) ) {
					$recreate[ $t['table'] ] = true;
				}
			}
			$warnings[] = sprintf( 'The table %s was restored whole: changes made to it after the operation are gone.', $t['table'] );
		}
		$db = ! empty( $s['rows'] ) || ! empty( $s['tables'] ) || ! empty( $s['keys'] );
		try {
			foreach ( $s['tables'] ?? array() as $t ) {
				$table = $t['table'];
				if ( self::own_table( $table ) ) {
					continue;
				}
				if ( empty( $t['existed'] ) ) {
					self::sql( 'DROP TABLE IF EXISTS ' . self::q( $table ) );
					$done[] = "dropped $table";
				} elseif ( isset( $recreate[ $table ] ) ) {
					// DDL commits on its own; done before the transaction.
					self::sql( 'DROP TABLE IF EXISTS ' . self::q( $table ) );
					self::sql( $t['create'] );
				}
			}
			if ( $db ) {
				self::sql( 'START TRANSACTION' );
			}
			foreach ( $s['tables'] ?? array() as $t ) {
				$table = $t['table'];
				if ( self::own_table( $table ) || empty( $t['existed'] ) ) {
					continue;
				}
				if ( ! isset( $recreate[ $table ] ) ) {
					self::sql( 'DELETE FROM ' . self::q( $table ) );
				}
				self::insert( $table, $t['rows'] );
				$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::q( $table ) );
				if ( $n !== count( $t['rows'] ) ) {
					throw new RuntimeException( sprintf( '%s has %d rows after restoring %d.', $table, $n, count( $t['rows'] ) ) );
				}
				$done[] = sprintf( 'restored %s (%d rows)', $table, count( $t['rows'] ) );
			}
			foreach ( $s['rows'] ?? array() as $r ) {
				self::sql( 'DELETE FROM ' . self::q( $r['table'] ) . " WHERE {$r['where']}" );
				self::insert( $r['table'], $r['rows'] );
				$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::q( $r['table'] ) . " WHERE {$r['where']}" );
				if ( $n !== count( $r['rows'] ) ) {
					throw new RuntimeException( sprintf( '%s has %d rows where %s after restoring %d.', $r['table'], $n, $r['where'], count( $r['rows'] ) ) );
				}
				$done[] = sprintf( 'restored %d rows of %s where %s', count( $r['rows'] ), $r['table'], $r['where'] );
			}
			foreach ( $s['keys'] ?? array() as $k ) {
				$table = $k['table'];
				if ( self::own_table( $table ) ) {
					continue;
				}
				$pk   = $k['pk'];
				$all  = array();
				$want = array();
				foreach ( $k['rows'] as $r ) {
					$kv                            = self::key_values( $pk, $r );
					$all[ wp_json_encode( $kv ) ]  = $kv;
					$want[ wp_json_encode( $kv ) ] = self::row_hash( $r );
				}
				foreach ( $k['absent'] as $kv ) {
					$all[ wp_json_encode( $kv ) ] = $kv;
				}
				// Changed since the operation? Then a later change is overwritten: said.
				foreach ( array_chunk( $all, 500, true ) as $chunk ) {
					$now = array();
					foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::q( $table ) . ' WHERE ' . self::key_where( $pk, array_values( $chunk ) ) . ' FOR UPDATE', ARRAY_A ) as $r ) {
						$now[ wp_json_encode( self::key_values( $pk, $r ) ) ] = self::row_hash( $r );
					}
					foreach ( $chunk as $kj => $kv ) {
						if ( array_key_exists( $kj, (array) ( $k['after'] ?? array() ) ) && ( $now[ $kj ] ?? null ) !== $k['after'][ $kj ] ) {
							$changed[] = $table . ' ' . implode( ',', $pk ) . '=' . implode( ',', $kv );
						}
					}
					self::sql( 'DELETE FROM ' . self::q( $table ) . ' WHERE ' . self::key_where( $pk, array_values( $chunk ) ) );
				}
				// A kept row whose primary key changed since (deleted and added again,
				// as Elementor's transients or an option re-added with a new option_id):
				// the row now holding its natural key (a unique key of the table) is the
				// one it replaces.
				$matches = self::natural_matches( $table, $pk, $k['rows'], true );
				if ( $matches ) {
					$gone = array();
					foreach ( $matches as $m ) {
						$gone[]    = self::key_values( $pk, $m['now'] );
						$natural[] = sprintf(
							'%s %s=%s (restored as %s=%s; it was %s since)',
							$table,
							implode( ',', $m['cols'] ),
							implode( ',', self::key_values( $m['cols'], $m['now'] ) ),
							implode( ',', $pk ),
							$m['kept'] ? implode( ',', self::key_values( $pk, $m['kept'] ) ) : '?',
							implode( ',', self::key_values( $pk, $m['now'] ) )
						);
					}
					foreach ( array_chunk( $gone, 500 ) as $chunk ) {
						self::sql( 'DELETE FROM ' . self::q( $table ) . ' WHERE ' . self::key_where( $pk, $chunk ) );
					}
				}
				self::insert( $table, $k['rows'] );
				// Check: exactly the kept rows are there again, and the absent keys are gone.
				$check = array();
				foreach ( array_chunk( $all, 500, true ) as $chunk ) {
					foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::q( $table ) . ' WHERE ' . self::key_where( $pk, array_values( $chunk ) ), ARRAY_A ) as $r ) {
						$check[ wp_json_encode( self::key_values( $pk, $r ) ) ] = self::row_hash( $r );
					}
				}
				foreach ( $all as $kj => $_ ) {
					if ( ( $check[ $kj ] ?? null ) !== ( $want[ $kj ] ?? null ) ) {
						throw new RuntimeException( "The row $kj of $table is not as kept after restoring it." );
					}
				}
				$done[] = sprintf( 'restored %d rows of %s and removed %d it had created', count( $k['rows'] ), $table, count( $k['absent'] ) );
			}
			if ( $db ) {
				self::sql( 'COMMIT' );
			}
		} catch ( Throwable $e ) {
			if ( $db ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$failed[] = 'The database was not restored (its changes were undone): ' . $e->getMessage();
			if ( $recreate ) {
				$failed[] = 'These tables were already recreated from the snapshot\'s definition: ' . implode( ', ', array_keys( $recreate ) ) . '.';
			}
			return array( 'ok' => false, 'error' => implode( ' ', $failed ), 'done' => $done, 'failed' => $failed, 'warnings' => $warnings );
		}
		if ( $changed ) {
			$warnings[] = sprintf( '%d row(s) had changed since the operation, and those later changes were overwritten: %s.', count( $changed ), implode( '; ', array_slice( $changed, 0, 20 ) ) );
		}
		if ( $natural ) {
			$done[] = sprintf( 'matched %d row(s) by their natural key, as their primary key had changed since the operation: %s', count( $natural ), implode( '; ', array_slice( $natural, 0, 20 ) ) );
		}

		// 3. Folders kept as a zip.
		foreach ( $archives as $p => $a ) {
			$r = self::restore_archive( $a );
			if ( true === $r ) {
				$done[] = empty( $a['existed'] ) ? 'removed ' . self::rel( $p ) : 'restored the folder ' . self::rel( $p );
			} else {
				$failed[] = $r;
			}
		}

		// 4. Files: folders that existed, then files, then what did not exist (deepest first).
		$dirs = array_filter( $files, function ( $f ) {
			return ! empty( $f['dir'] ) && ! empty( $f['existed'] );
		} );
		ksort( $dirs );
		foreach ( $dirs as $p => $f ) {
			if ( ! is_dir( $p ) && ! Kico_Tools::mkdir( $p ) ) {
				$failed[] = Kico_Tools::cannot_write( $p, 'create' ) ?: "Could not create the folder $p.";
			}
		}
		foreach ( $files as $p => $f ) {
			if ( empty( $f['existed'] ) || ! empty( $f['dir'] ) ) {
				continue;
			}
			if ( isset( $f['link'] ) ) {
				if ( is_link( $p ) && readlink( $p ) === $f['link'] ) {
					continue;
				}
				@unlink( $p );
				$ok = @symlink( $f['link'], $p );
			} else {
				$data = base64_decode( (string) ( $f['content'] ?? '' ) );
				if ( is_dir( $p ) && ! is_link( $p ) ) {
					Kico_Tools::delete_tree( $p );
				}
				$ok = Kico_Tools::put( $p, $data, $f['perms'] ?? null );
				// Verify what is there now.
				clearstatcache( true, $p );
				$ok = $ok && is_file( $p ) && hash_file( 'sha256', $p ) === hash( 'sha256', $data );
			}
			if ( $ok ) {
				$done[] = 'restored ' . self::rel( $p );
			} else {
				$failed[] = ( Kico_Tools::cannot_write( $p, 'write' ) ?: "Could not write $p." ) . ' It is not as it was.';
			}
		}
		$gone = array_filter( $files, function ( $f ) {
			return empty( $f['existed'] );
		} );
		krsort( $gone );
		foreach ( $gone as $p => $f ) {
			if ( ! file_exists( $p ) && ! is_link( $p ) ) {
				continue;
			}
			$ok = is_dir( $p ) && ! is_link( $p ) ? Kico_Tools::delete_tree( $p ) : Kico_Tools::remove_file( $p );
			clearstatcache( true, $p );
			if ( $ok && ! file_exists( $p ) ) {
				$done[] = 'deleted ' . self::rel( $p );
			} else {
				$failed[] = ( Kico_Tools::cannot_write( $p, 'delete' ) ?: "Could not delete $p." ) . ' It is still there.';
			}
		}

		// 5. What the operation created.
		foreach ( $s['created'] ?? array() as $c ) {
			if ( ! get_post( (int) $c['id'] ) ) {
				continue;
			}
			if ( 'attachment' === $c['type'] ) {
				wp_delete_attachment( (int) $c['id'], true );
			} else {
				wp_delete_post( (int) $c['id'], true );
			}
			clean_post_cache( (int) $c['id'] );
			if ( get_post( (int) $c['id'] ) ) {
				$failed[] = sprintf( 'Could not delete %s %d.', $c['type'], $c['id'] );
			} else {
				$done[] = sprintf( 'deleted %s %d', $c['type'], $c['id'] );
			}
		}
		if ( $db ) {
			wp_cache_flush();
			Kico_Tools::elementor_flush();
		}
		$out = array( 'ok' => ! $failed, 'done' => $done );
		if ( $failed ) {
			$out['error']  = 'The rollback is incomplete: ' . implode( ' ', array_slice( $failed, 0, 10 ) );
			$out['failed'] = $failed;
		}
		if ( $warnings ) {
			$out['warnings'] = $warnings;
		}
		if ( $changed ) {
			$out['changed_since'] = array_slice( $changed, 0, 200 );
		}
		if ( $natural ) {
			$out['matched_by_natural_key'] = array_slice( $natural, 0, 200 );
		}
		return $out;
	}

	/** Puts a folder kept as a zip back (an update of this plugin that did not load): true, or what failed. */
	public static function restore_folder( array $a ) {
		$a['path'] = self::abs( $a['path'] );
		return self::restore_archive( $a );
	}

	private static function restore_archive( array $a ) {
		$p = $a['path'];
		if ( is_dir( $p ) && ! Kico_Tools::delete_tree( $p ) ) {
			return ( Kico_Tools::cannot_write( $p, 'replace' ) ?: "Could not remove $p." ) . ' It is not as it was.';
		}
		if ( empty( $a['existed'] ) ) {
			return true;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return "PHP has no ZipArchive, so the folder $p could not be put back from its zip.";
		}
		$tmp = tempnam( get_temp_dir(), 'kico-archive' );
		file_put_contents( $tmp, base64_decode( (string) $a['zip'] ) );
		$zip = new ZipArchive();
		$ok  = true === $zip->open( $tmp ) && Kico_Tools::mkdir( $p ) && $zip->extractTo( $p );
		$n   = $ok ? $zip->numFiles : 0;
		if ( $ok ) {
			$zip->close();
		}
		@unlink( $tmp );
		if ( ! $ok ) {
			return ( Kico_Tools::cannot_write( $p, 'write' ) ?: "Could not extract the folder $p." ) . ' It is not as it was.';
		}
		$count = 0;
		$cache = function_exists( 'opcache_invalidate' );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $p, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			$count += $f->isFile() ? 1 : 0;
			// The zip gives the files their old times: PHP's cache must not keep what was there.
			if ( $cache && $f->isFile() && '.php' === substr( $f->getFilename(), -4 ) ) {
				@opcache_invalidate( $f->getPathname(), true );
			}
		}
		return isset( $a['files'] ) && $count !== (int) $a['files'] ? sprintf( 'The folder %s has %d files after restoring %d.', $p, $count, $a['files'] ) : true;
	}

	/** A table definition without its AUTO_INCREMENT counter, to compare two. */
	private static function schema( $create ) {
		return preg_replace( '/\s+AUTO_INCREMENT=\d+/', '', (string) $create );
	}

	private static function sql( $sql ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$r        = $wpdb->query( $sql );
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( false === $r || $error ) {
			throw new RuntimeException( ( $error ?: 'a query failed' ) . ' (' . Kico_Store::clip( $sql, 160 ) . ')' );
		}
		return $r;
	}

	private static function insert( $table, array $rows ) {
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$cols   = array_keys( $chunk[0] );
			$values = array();
			foreach ( $chunk as $row ) {
				$values[] = '(' . implode( ',', array_map( array( __CLASS__, 'value' ), array_values( $row ) ) ) . ')';
			}
			self::sql( 'INSERT INTO ' . self::q( $table ) . ' (' . implode( ',', array_map( array( __CLASS__, 'q' ), $cols ) ) . ') VALUES ' . implode( ',', $values ) );
		}
	}
}
