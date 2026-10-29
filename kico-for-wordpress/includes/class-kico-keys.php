<?php
/**
 * The Kico keys this site accepts: only public keys, each bound to the admin
 * who paired it. No secret of Kico's is ever stored on the site. And the
 * pairings an admin started in wp-admin, waiting for Kico (10 minutes).
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Keys {

	const OPTION   = 'kico_keys';
	const PAIRINGS = 'kico_pairings';
	const PAIR_TTL = 600;
	// How long the origin a Kico moved away from may still frame the site.
	const PREVIOUS_DAYS = 90;

	/** @return array<string,array> keyid => key */
	public static function all() {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? $keys : array();
	}

	public static function get( $keyid ) {
		$keys = self::all();
		return isset( $keys[ $keyid ] ) ? $keys[ $keyid ] : null;
	}

	public static function add( $keyid, array $key ) {
		$keys           = self::all();
		$keys[ $keyid ] = $key;
		update_option( self::OPTION, $keys, false );
	}

	public static function remove( $keyid ) {
		$keys = self::all();
		if ( ! isset( $keys[ $keyid ] ) ) {
			return false;
		}
		unset( $keys[ $keyid ] );
		update_option( self::OPTION, $keys, false );
		return true;
	}

	public static function touch( $keyid ) {
		$keys = self::all();
		if ( isset( $keys[ $keyid ] ) && ( empty( $keys[ $keyid ]['last_used_at'] ) || time() - (int) $keys[ $keyid ]['last_used_at'] > 60 ) ) {
			$keys[ $keyid ]['last_used_at'] = time();
			update_option( self::OPTION, $keys, false );
		}
	}

	/**
	 * The origins of the Kico instances paired with this site (for
	 * frame-ancestors): each key's Kico, and where that Kico was before it
	 * moved, until the transition ends.
	 *
	 * @param int|null $now Unix time (tests).
	 */
	public static function kico_origins( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array();
		foreach ( self::all() as $key ) {
			if ( ! empty( $key['kico_origin'] ) ) {
				$out[ $key['kico_origin'] ] = true;
			}
			foreach ( self::previous_origins( $key, $now ) as $origin => $until ) {
				$out[ $origin ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Where a key's Kico was before it moved, while that still counts:
	 * origin => until (Unix time).
	 *
	 * @param array    $key The key.
	 * @param int|null $now Unix time.
	 * @return array<string,int>
	 */
	public static function previous_origins( array $key, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$prev = isset( $key['kico_origins_previous'] ) && is_array( $key['kico_origins_previous'] ) ? $key['kico_origins_previous'] : array();
		return array_filter(
			$prev,
			function ( $until ) use ( $now ) {
				return (int) $until > $now;
			}
		);
	}

	/** Whether a key belongs to the Kico at this origin, now or before it moved. */
	public static function belongs_to( array $key, $origin, $now = null ) {
		return '' !== (string) $origin && ( ( $key['kico_origin'] ?? '' ) === $origin || isset( self::previous_origins( $key, $now )[ $origin ] ) );
	}

	/**
	 * Kico moved (kico.dev to app.kico.dev): the key's Kico is at $origin from
	 * now on. Its old origin may still frame the site for $previous_days days,
	 * so previews keep working while Kico moves; 0 ends that at once, for
	 * every earlier origin of the key.
	 *
	 * @param string   $keyid         The key.
	 * @param string   $origin        Kico's origin now (normalized by the caller).
	 * @param int      $previous_days How long the old origin still counts (0 to 365).
	 * @param int|null $now           Unix time (tests).
	 * @return array|null The key, or null when there is none.
	 */
	public static function set_origin( $keyid, $origin, $previous_days = self::PREVIOUS_DAYS, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$keys = self::all();
		if ( ! isset( $keys[ $keyid ] ) || '' === (string) $origin ) {
			return null;
		}
		$key  = $keys[ $keyid ];
		$days = max( 0, min( 365, (int) $previous_days ) );
		$old  = (string) ( $key['kico_origin'] ?? '' );
		$prev = $days ? self::previous_origins( $key, $now ) : array();
		unset( $prev[ $origin ] );
		if ( $days && '' !== $old && $old !== $origin ) {
			$prev[ $old ] = $now + $days * DAY_IN_SECONDS;
		}
		$key['kico_origin'] = $origin;
		if ( $prev ) {
			$key['kico_origins_previous'] = $prev;
		} else {
			unset( $key['kico_origins_previous'] );
		}
		$keys[ $keyid ] = $key;
		update_option( self::OPTION, $keys, false );
		return $key;
	}

	// --- pairings ------------------------------------------------------------------------------

	/**
	 * The match code of a pairing, shown here and in Kico; Kico computes the same
	 * from the code (server/wordpress-pairing.ts matchCode).
	 *
	 * @param string $code The one-time code.
	 */
	public static function match_code( $code ) {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$h        = hash( 'sha256', 'kico-pair-match:' . $code, true );
		$c        = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$c .= $alphabet[ ord( $h[ $i ] ) % 32 ];
		}
		return substr( $c, 0, 3 ) . '-' . substr( $c, 3 );
	}

	/** Pairings not yet expired: sha256 of the code => user_id, created, expires, match. */
	public static function pairings() {
		$all = get_option( self::PAIRINGS, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $hash => $p ) {
			if ( is_array( $p ) && (int) $p['expires'] >= time() ) {
				$out[ $hash ] = $p;
			}
		}
		return $out;
	}

	private static function save_pairings( array $pairings ) {
		if ( $pairings ) {
			update_option( self::PAIRINGS, $pairings, false );
		} else {
			delete_option( self::PAIRINGS );
		}
	}

	/** Whether an admin started a pairing in the last 10 minutes: the pair route answers only then. */
	public static function pairing_open() {
		return (bool) self::pairings();
	}

	/** A one-time code for the admin's "Connect to Kico", valid 10 minutes. */
	public static function new_pair_code( $user_id ) {
		$code                                = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$pairings                            = self::pairings();
		$pairings[ hash( 'sha256', $code ) ] = array(
			'user_id' => (int) $user_id,
			'created' => time(),
			'expires' => time() + self::PAIR_TTL,
			'match'   => self::match_code( $code ),
		);
		self::save_pairings( $pairings );
		return $code;
	}

	/** The pending pairing of a code, without using it. */
	public static function peek_pair_code( $code ) {
		if ( ! is_string( $code ) || strlen( $code ) < 20 || strlen( $code ) > 200 ) {
			return null;
		}
		$pairings = self::pairings();
		$hash     = hash( 'sha256', $code );
		return isset( $pairings[ $hash ] ) ? $pairings[ $hash ] : null;
	}

	/** The pending pairing of a code, used once. */
	public static function take_pair_code( $code ) {
		$pending = self::peek_pair_code( $code );
		if ( ! $pending ) {
			return null;
		}
		$pairings = self::pairings();
		unset( $pairings[ hash( 'sha256', $code ) ] );
		self::save_pairings( $pairings );
		return $pending;
	}

	/** An admin cancels a pairing in wp-admin, by its match code. */
	public static function cancel_pairing( $match ) {
		$pairings = self::pairings();
		foreach ( $pairings as $hash => $p ) {
			if ( hash_equals( (string) $p['match'], (string) $match ) ) {
				unset( $pairings[ $hash ] );
			}
		}
		self::save_pairings( $pairings );
	}
}
