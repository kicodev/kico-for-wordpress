<?php
/**
 * Kico shows the live site in a project's preview. Pages of the site (not
 * wp-admin or the login) may be framed by the Kico instances paired with it;
 * no one else. Only a request the browser makes for a frame
 * (Sec-Fetch-Dest: iframe) is changed, and the site's own
 * Content-Security-Policy is kept: Kico's origins are added to its
 * frame-ancestors, or a frame-ancestors policy is added next to it. A host or
 * security plugin may still send its own header from outside PHP; Kico sees
 * that in the response and shows the site another way.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Frame {

	public static function boot() {
		// Late, after themes and security plugins set their headers in PHP.
		add_action( 'send_headers', array( __CLASS__, 'headers' ), PHP_INT_MAX );
	}

	public static function is_frame_request() {
		return isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		$origins = array_values( array_filter( array_map( array( __CLASS__, 'origin' ), Kico_Keys::kico_origins() ) ) );
		if ( ! $origins ) {
			return;
		}
		// Frame and page answers differ: caches in front must keep them apart.
		header( 'Vary: Sec-Fetch-Dest', false );
		if ( ! self::is_frame_request() ) {
			return;
		}
		$policies = array();
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'Content-Security-Policy:' ) ) {
				$policies[] = trim( substr( $h, strlen( 'Content-Security-Policy:' ) ) );
			}
		}
		$merged = self::merge( $policies, $origins );
		header_remove( 'Content-Security-Policy' );
		foreach ( $merged as $p ) {
			header( 'Content-Security-Policy: ' . $p, false );
		}
		// frame-ancestors now names Kico; browsers ignore X-Frame-Options then, Kico's check does not.
		header_remove( 'X-Frame-Options' );
	}

	/** An origin as CSP takes it (scheme://host[:port]), or '' when it is not one. */
	public static function origin( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) || ! preg_match( '/^[A-Za-z0-9.-]+$/', $p['host'] ) ) {
			return '';
		}
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}

	/**
	 * The site's policies with Kico's origins allowed to frame: added to every
	 * frame-ancestors directive there is ('none' gives way to them), or one
	 * policy "frame-ancestors 'self' <origins>" next to them when none has one.
	 * Every other directive stays as it was.
	 *
	 * @param string[] $headers Values of the Content-Security-Policy headers.
	 * @param string[] $origins Kico origins.
	 * @return string[] The header values to send.
	 */
	public static function merge( array $headers, array $origins ) {
		$out   = array();
		$found = false;
		foreach ( $headers as $header ) {
			$policies = array();
			// One header may carry several policies, comma separated.
			foreach ( explode( ',', $header ) as $policy ) {
				$directives = array();
				foreach ( explode( ';', $policy ) as $d ) {
					$d = trim( $d );
					if ( '' === $d ) {
						continue;
					}
					if ( preg_match( '/^frame-ancestors(\s|$)/i', $d ) ) {
						$found   = true;
						$sources = preg_split( '/\s+/', trim( substr( $d, strlen( 'frame-ancestors' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
						$sources = array_values( array_filter( $sources, function ( $s ) { return "'none'" !== strtolower( $s ); } ) );
						foreach ( $origins as $o ) {
							if ( ! in_array( $o, $sources, true ) ) {
								$sources[] = $o;
							}
						}
						$d = 'frame-ancestors ' . implode( ' ', $sources );
					}
					$directives[] = $d;
				}
				if ( $directives ) {
					$policies[] = implode( '; ', $directives );
				}
			}
			if ( $policies ) {
				$out[] = implode( ', ', $policies );
			}
		}
		if ( ! $found ) {
			$out[] = "frame-ancestors 'self' " . implode( ' ', $origins );
		}
		return $out;
	}
}
