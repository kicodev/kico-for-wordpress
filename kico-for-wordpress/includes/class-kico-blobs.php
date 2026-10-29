<?php
/**
 * Blobs of the transport (docs/wordpress.md, "Chunked transfer"): bodies
 * Kico uploads in chunks (direction "in"), answers staged for Kico to fetch
 * in chunks ("out"), and the results of operations kept for fetch by id
 * ("op"). Each is opaque bytes, kept as base64 rows of the blob table and
 * bound to the key that made it: another key finds nothing.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Blobs {

	// Raw bytes per stored row of a staged or kept blob (512 KB of base64,
	// well under any max_allowed_packet).
	const PIECE = 393216;

	// Expired blobs answer "expired" for this long, then they are purged.
	const GRACE = 3600;

	private static function table() {
		Kico_Store::maybe_install();
		return Kico_Store::blob_table();
	}

	/**
	 * One chunk of an upload from Kico.
	 *
	 * @param array  $p     id, seq, count, size, sha256, data (base64).
	 * @param string $keyid The key of the request.
	 * @return array|WP_Error { received, complete }
	 */
	public static function put( array $p, $keyid ) {
		global $wpdb;
		$id    = strtolower( (string) ( $p['id'] ?? '' ) );
		$seq   = (int) ( $p['seq'] ?? -1 );
		$count = (int) ( $p['count'] ?? 0 );
		$size  = (int) ( $p['size'] ?? -1 );
		$sha   = strtolower( (string) ( $p['sha256'] ?? '' ) );
		$data  = (string) ( $p['data'] ?? '' );
		if ( ! Kico_Transport::is_uuid( $id ) || $count < 1 || $seq < 0 || $seq >= $count || $size < 0 || $size > Kico_Transport::DECODED_MAX || ! preg_match( '/^[0-9a-f]{64}$/', $sha ) || false === base64_decode( $data, true ) ) {
			return Kico_Transport::error( 'kico_blob', 'A chunk needs id (uuid), seq, count, size, sha256 and data (base64).', 422, array( 'reason' => 'invalid' ) );
		}
		$t     = self::table();
		$other = $wpdb->get_row( $wpdb->prepare( "SELECT keyid, direction, count, size, sha256 FROM $t WHERE id = %s LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $other && ( $other['keyid'] !== $keyid || 'in' !== $other['direction'] ) ) {
			return Kico_Transport::error( 'kico_blob', 'No such blob.', 404, array( 'reason' => 'missing' ) );
		}
		if ( $other && ( (int) $other['count'] !== $count || (int) $other['size'] !== $size || $other['sha256'] !== $sha ) ) {
			return Kico_Transport::error( 'kico_blob', 'This chunk does not belong to the blob of that id (count, size or sha256 differ).', 422, array( 'reason' => 'hash' ) );
		}
		$now = time();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"REPLACE INTO $t (id, seq, count, direction, keyid, size, sha256, data, created_at, expires_at) VALUES (%s, %d, %d, 'in', %s, %d, %s, %s, %d, %d)", // phpcs:ignore WordPress.DB
				$id,
				$seq,
				$count,
				$keyid,
				$size,
				$sha,
				$data,
				$now,
				$now + Kico_Transport::BLOB_TTL
			)
		);
		if ( $wpdb->last_error ) {
			return Kico_Transport::error( 'kico_blob', 'Storing the chunk failed: ' . $wpdb->last_error, 500, array( 'reason' => 'storage' ) );
		}
		$received = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB
		$complete = $received >= $count;
		if ( $complete ) {
			$check = self::verify( $id, 'in', $keyid, $size, $sha );
			if ( is_wp_error( $check ) ) {
				self::delete( $id, $keyid );
				return $check;
			}
		}
		return array( 'id' => $id, 'seq' => $seq, 'received' => $received, 'count' => $count, 'complete' => $complete );
	}

	/** Size and hash of a stored blob, streamed over its rows. */
	private static function verify( $id, $direction, $keyid, $size, $sha ) {
		global $wpdb;
		$t     = self::table();
		$h     = hash_init( 'sha256' );
		$total = 0;
		$seqs  = $wpdb->get_col( $wpdb->prepare( "SELECT seq FROM $t WHERE id = %s AND direction = %s AND keyid = %s ORDER BY seq", $id, $direction, $keyid ) ); // phpcs:ignore WordPress.DB
		foreach ( $seqs as $seq ) {
			$part = base64_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $t WHERE id = %s AND seq = %d", $id, $seq ) ) ); // phpcs:ignore WordPress.DB
			$total += strlen( $part );
			hash_update( $h, $part );
		}
		if ( $total !== (int) $size || ! hash_equals( (string) $sha, hash_final( $h ) ) ) {
			return Kico_Transport::error( 'kico_blob', sprintf( 'The blob does not match its size or sha256 (%d bytes received of %d).', $total, $size ), 422, array( 'reason' => 'hash' ) );
		}
		return true;
	}

	/**
	 * A blob's first row, or why there is none.
	 *
	 * @return array|WP_Error
	 */
	public static function info( $id, $direction, $keyid ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, count, direction, keyid, size, sha256, expires_at FROM $t WHERE id = %s AND seq = 0", strtolower( (string) $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row || $row['keyid'] !== $keyid || $row['direction'] !== $direction ) {
			return Kico_Transport::error( 'kico_blob', 'No such blob.', 404, array( 'reason' => 'missing' ) );
		}
		if ( (int) $row['expires_at'] < time() ) {
			return Kico_Transport::error( 'kico_blob', 'The blob expired.', 410, array( 'reason' => 'expired' ) );
		}
		return $row;
	}

	/**
	 * An uploaded blob as one string, checked against what the envelope says.
	 * The transport deletes it once the request was answered (not when it was
	 * refused as kico_busy: Kico sends the same request again).
	 *
	 * @return string|WP_Error
	 */
	public static function take( $id, $size, $sha, $keyid ) {
		global $wpdb;
		$info = self::info( $id, 'in', $keyid );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$t        = self::table();
		$received = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE id = %s", $info['id'] ) ); // phpcs:ignore WordPress.DB
		if ( $received < (int) $info['count'] ) {
			return Kico_Transport::error( 'kico_blob', sprintf( 'The blob is incomplete (%d of %d chunks).', $received, $info['count'] ), 404, array( 'reason' => 'missing' ) );
		}
		if ( (int) $info['size'] !== (int) $size || ! hash_equals( (string) $info['sha256'], strtolower( (string) $sha ) ) ) {
			return Kico_Transport::error( 'kico_blob', 'The blob does not match the size or sha256 the request names.', 422, array( 'reason' => 'hash' ) );
		}
		$bytes = self::read( $info['id'], 'in', $keyid );
		if ( strlen( $bytes ) !== (int) $size || ! hash_equals( (string) $info['sha256'], hash( 'sha256', $bytes ) ) ) {
			return Kico_Transport::error( 'kico_blob', 'The blob does not match its size or sha256.', 422, array( 'reason' => 'hash' ) );
		}
		return $bytes;
	}

	/** Every row of a blob, concatenated. */
	public static function read( $id, $direction, $keyid ) {
		global $wpdb;
		$t    = self::table();
		$out  = '';
		$seqs = $wpdb->get_col( $wpdb->prepare( "SELECT seq FROM $t WHERE id = %s AND direction = %s AND keyid = %s ORDER BY seq", $id, $direction, $keyid ) ); // phpcs:ignore WordPress.DB
		foreach ( $seqs as $seq ) {
			$out .= base64_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $t WHERE id = %s AND seq = %d", $id, $seq ) ) ); // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	/**
	 * Bytes of a staged blob, from offset, at most length.
	 *
	 * @return string
	 */
	public static function range( $id, $direction, $keyid, $offset, $length ) {
		global $wpdb;
		$t     = self::table();
		$first = intdiv( $offset, self::PIECE );
		$last  = intdiv( max( $offset, $offset + $length - 1 ), self::PIECE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT seq, data FROM $t WHERE id = %s AND direction = %s AND keyid = %s AND seq BETWEEN %d AND %d ORDER BY seq", $id, $direction, $keyid, $first, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$bytes = '';
		foreach ( $rows as $r ) {
			$bytes .= base64_decode( (string) $r['data'] );
		}
		return (string) substr( $bytes, $offset - $first * self::PIECE, $length );
	}

	/**
	 * Stores bytes as a blob (an answer for Kico to fetch, or an operation's
	 * kept result), replacing one of the same id.
	 *
	 * @return array|WP_Error { id, size, sha256 }
	 */
	public static function stage( $id, $direction, $bytes, $ttl, $keyid ) {
		global $wpdb;
		$t     = self::table();
		$size  = strlen( $bytes );
		$sha   = hash( 'sha256', $bytes );
		$count = max( 1, (int) ceil( $size / self::PIECE ) );
		$now   = time();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"INSERT INTO $t (id, seq, count, direction, keyid, size, sha256, data, created_at, expires_at) VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %d, %d)", // phpcs:ignore WordPress.DB
					$id,
					$i,
					$count,
					$direction,
					$keyid,
					$size,
					$sha,
					base64_encode( (string) substr( $bytes, $i * self::PIECE, self::PIECE ) ),
					$now,
					$now + (int) $ttl
				)
			);
			if ( $wpdb->last_error ) {
				$error = $wpdb->last_error;
				$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB
				return Kico_Transport::error( 'kico_blob', 'Staging the answer failed: ' . $error, 500, array( 'reason' => 'storage' ) );
			}
		}
		return array( 'id' => $id, 'size' => $size, 'sha256' => $sha );
	}

	/**
	 * Deletes a blob of this key (any direction unless one is named).
	 *
	 * @param string|string[] $ids       One id or several.
	 * @param string          $keyid     The key.
	 * @param string          $direction Only blobs of this direction ('' for any).
	 * @return int Rows deleted.
	 */
	public static function delete( $ids, $keyid, $direction = '' ) {
		global $wpdb;
		$t = self::table();
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$id = strtolower( (string) $id );
			if ( ! Kico_Transport::is_uuid( $id ) ) {
				continue;
			}
			$n += (int) ( '' === $direction
				? $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id = %s AND keyid = %s", $id, $keyid ) ) // phpcs:ignore WordPress.DB
				: $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id = %s AND keyid = %s AND direction = %s", $id, $keyid, $direction ) ) ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}

	/** Drops blobs past their time (on every blob request, and daily). */
	public static function purge() {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE expires_at < %d LIMIT 2000", time() - self::GRACE ) ); // phpcs:ignore WordPress.DB
	}
}
