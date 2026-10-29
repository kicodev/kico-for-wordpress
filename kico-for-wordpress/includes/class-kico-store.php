<?php
/**
 * The plugin's tables: the audit log (hash-chained, so a changed or removed
 * entry shows), the nonces of signed requests (each used once) and the blobs
 * of the transport (chunked bodies, staged answers, kept operations).
 * tables() lists them; snapshots never include any of them.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Store {

	const DB_VERSION = '2';

	// Every table of the plugin, by its name after a site's prefix.
	const TABLES = array( 'kico_audit', 'kico_nonces', 'kico_blobs' );

	public static function boot() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
	}

	public static function maybe_install() {
		if ( get_option( 'kico_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'kico_audit';
	}

	public static function nonce_table() {
		global $wpdb;
		return $wpdb->prefix . 'kico_nonces';
	}

	public static function blob_table() {
		global $wpdb;
		return $wpdb->prefix . 'kico_blobs';
	}

	/** The plugin's own tables of this site. */
	public static function tables() {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $t ) {
			$out[] = $wpdb->prefix . $t;
		}
		return $out;
	}

	/**
	 * Whether a table is one of the plugin's, of this site or of any site of
	 * a multisite network ({base prefix}{blog id}_kico_audit, ...).
	 *
	 * @param string $table Table name.
	 */
	public static function is_own_table( $table ) {
		global $wpdb;
		$table = (string) $table;
		foreach ( self::TABLES as $t ) {
			if ( $wpdb->prefix . $t === $table ) {
				return true;
			}
			$base = (string) $wpdb->base_prefix;
			if ( '' !== $base && 0 === strpos( $table, $base ) && preg_match( '/^(?:\d+_)?' . preg_quote( $t, '/' ) . '$/', substr( $table, strlen( $base ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::audit_table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  op_id char(36) NOT NULL,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  keyid varchar(64) NOT NULL DEFAULT '',
  tool varchar(64) NOT NULL,
  args longtext NOT NULL,
  result longtext NOT NULL,
  snapshot varchar(32) NOT NULL DEFAULT '',
  ok tinyint(1) NOT NULL DEFAULT 1,
  prev_hash char(64) NOT NULL DEFAULT '',
  hash char(64) NOT NULL,
  phase varchar(16) NOT NULL DEFAULT '',
  outcome varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY op_id (op_id)
) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::nonce_table() . " (
  nonce varchar(64) NOT NULL,
  expires bigint(20) NOT NULL,
  PRIMARY KEY  (nonce)
) $charset;"
		);
		// Chunks are kept as base64 text, so every query stays ASCII (wpdb
		// checks other queries against the table's charset and refuses binary).
		dbDelta(
			'CREATE TABLE ' . self::blob_table() . " (
  id char(36) NOT NULL,
  seq int(10) unsigned NOT NULL,
  count int(10) unsigned NOT NULL,
  direction varchar(8) NOT NULL,
  keyid varchar(64) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  created_at bigint(20) NOT NULL,
  expires_at bigint(20) NOT NULL,
  PRIMARY KEY  (id,seq),
  KEY expires_at (expires_at)
) $charset;"
		);
		update_option( 'kico_db_version', self::DB_VERSION, false );
	}

	/** A nonce of a signed request; false when it was seen before. */
	public static function use_nonce( $nonce, $created ) {
		global $wpdb;
		if ( ! is_string( $nonce ) || strlen( $nonce ) < 16 || strlen( $nonce ) > 64 ) {
			return false;
		}
		self::maybe_install();
		// Kept past the time a signature is accepted, then dropped.
		$expires = (int) $created + 2 * Kico_Signature::SKEW + 60;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::nonce_table() . ' WHERE expires < %d LIMIT 200', time() ) ); // phpcs:ignore WordPress.DB
		$done = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::nonce_table() . ' (nonce, expires) VALUES (%s, %d)', $nonce, $expires ) ); // phpcs:ignore WordPress.DB
		return 1 === $done;
	}

	/** Text kept in the log: long values are cut, with their size and hash. */
	public static function clip( $value, $max = 65536 ) {
		$text = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( strlen( (string) $text ) <= $max ) {
			return (string) $text;
		}
		return substr( (string) $text, 0, $max ) . sprintf( "\n[... %d bytes in all, sha256 %s]", strlen( $text ), hash( 'sha256', $text ) );
	}

	/**
	 * A name for a MySQL named lock: at most 64 characters, and server-wide,
	 * so it names the database too.
	 *
	 * @param string $prefix What the lock is for.
	 * @param string $what   The table or scope it guards.
	 */
	public static function lock_name( $prefix, $what ) {
		return $prefix . md5( DB_NAME . '/' . $what );
	}

	/**
	 * Appends an entry to the audit log, chained to the one before. Appending
	 * is serialized with a named lock, so concurrent calls never chain to the
	 * same entry.
	 *
	 * @param array $e op_id, tool, args, result, ok, snapshot, keyid, phase ("started" or "done", default "done"), outcome.
	 * @return array The entry as stored.
	 */
	public static function audit( array $e ) {
		global $wpdb;
		self::maybe_install();
		$phase = (string) ( $e['phase'] ?? 'done' );
		$row   = array(
			'op_id'      => (string) $e['op_id'],
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'user_id'    => get_current_user_id(),
			'keyid'      => (string) ( $e['keyid'] ?? ( Kico_Auth::key()['keyid'] ?? '' ) ),
			'tool'       => (string) $e['tool'],
			'args'       => self::clip( $e['args'] ?? array() ),
			'result'     => self::clip( $e['result'] ?? '', 8192 ),
			'snapshot'   => (string) ( $e['snapshot'] ?? '' ),
			'ok'         => empty( $e['ok'] ) ? 0 : 1,
			'phase'      => $phase,
			'outcome'    => (string) ( $e['outcome'] ?? ( 'started' === $phase ? '' : ( empty( $e['ok'] ) ? 'error' : 'ok' ) ) ),
			'prev_hash'  => '',
		);
		$lock   = self::lock_name( 'kico_audit_', self::audit_table() );
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ); // phpcs:ignore WordPress.DB
		try {
			$row['prev_hash'] = (string) $wpdb->get_var( 'SELECT hash FROM ' . self::audit_table() . ' ORDER BY id DESC LIMIT 1' ); // phpcs:ignore WordPress.DB
			$row['hash']      = self::hash_of( $row );
			$wpdb->insert( self::audit_table(), $row ); // phpcs:ignore WordPress.DB
			$row['id'] = (int) $wpdb->insert_id;
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB
			}
		}
		return $row;
	}

	/**
	 * An entry's hash: over the previous hash and the entry's fields, in a
	 * fixed order. Entries written before phases existed (phase '') hash
	 * without phase and outcome, as they were written.
	 *
	 * @param array $r The entry (id and hash are ignored).
	 */
	private static function hash_of( array $r ) {
		$h = array();
		foreach ( array( 'op_id', 'created_at', 'user_id', 'keyid', 'tool', 'args', 'result', 'snapshot', 'ok' ) as $k ) {
			$h[ $k ] = $r[ $k ] ?? '';
		}
		$h['user_id'] = (int) $h['user_id'];
		$h['ok']      = (int) $h['ok'];
		if ( '' !== (string) ( $r['phase'] ?? '' ) ) {
			$h['phase']   = (string) $r['phase'];
			$h['outcome'] = (string) ( $r['outcome'] ?? '' );
		}
		$h['prev_hash'] = (string) ( $r['prev_hash'] ?? '' );
		return hash( 'sha256', $h['prev_hash'] . "\n" . wp_json_encode( $h, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * The entries of one operation, oldest first.
	 *
	 * @param string $op_id Operation id.
	 * @return array[]
	 */
	public static function op_entries( $op_id ) {
		global $wpdb;
		self::maybe_install();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::audit_table() . ' WHERE op_id = %s ORDER BY id ASC', $op_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** The newest entries, and whether the chain is intact over them. */
	public static function audit_list( $limit = 50, $op_id = '' ) {
		global $wpdb;
		self::maybe_install();
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $op_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::audit_table() . ' WHERE op_id = %s ORDER BY id DESC', $op_id ), ARRAY_A ) // phpcs:ignore WordPress.DB
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::audit_table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$intact = true;
		foreach ( $rows as $i => $r ) {
			if ( ! hash_equals( self::hash_of( $r ), $r['hash'] ) || ( ! $op_id && isset( $rows[ $i + 1 ] ) && $rows[ $i + 1 ]['hash'] !== $r['prev_hash'] ) ) {
				$intact = false;
			}
		}
		// The oldest entry left follows the last one retention removed (Kico_Retention).
		$oldest = $rows ? $rows[ count( $rows ) - 1 ] : null;
		if ( ! $op_id && $oldest && (int) $oldest['id'] === (int) $wpdb->get_var( 'SELECT MIN(id) FROM ' . self::audit_table() ) && ! Kico_Retention::anchored( $oldest ) ) { // phpcs:ignore WordPress.DB
			$intact = false;
		}
		return array( 'entries' => $rows, 'chain_intact' => $intact );
	}
}
