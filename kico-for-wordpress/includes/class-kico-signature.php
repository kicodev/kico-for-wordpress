<?php
/**
 * HTTP Message Signatures (RFC 9421), the subset Kico uses: Ed25519 over the
 * method, path, query and Content-Digest (RFC 9530), with created, nonce and
 * keyid parameters; version 2 covers the @authority too (the host the request
 * was sent to). Kico signs every request with the private key it keeps
 * sealed; the site only has the public key.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Signature {

	const LABEL      = 'kico';
	const COMPONENTS    = array( '@method', '@path', '@query', 'content-digest' );
	const COMPONENTS_V2 = array( '@method', '@authority', '@path', '@query', 'content-digest' );
	// What the pair answer tells Kico: this plugin checks version 2.
	const VERSION = 2;
	// Clock skew allowed between Kico and the site, in seconds.
	const SKEW = 60;

	/**
	 * The parts of a signed request as the site received it.
	 *
	 * @return array{method:string,path:string,query:string,body:string,headers:array<string,string>}
	 */
	public static function current_request() {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared byte for byte with what Kico signed.
		$q     = strpos( $uri, '?' );
		$path  = false === $q ? $uri : substr( $uri, 0, $q );
		$query = false === $q ? '?' : substr( $uri, $q );
		if ( '' === $query ) {
			$query = '?';
		}
		$headers = array();
		foreach ( array( 'signature-input' => 'HTTP_SIGNATURE_INPUT', 'signature' => 'HTTP_SIGNATURE', 'content-digest' => 'HTTP_CONTENT_DIGEST' ) as $name => $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$headers[ $name ] = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		return array(
			'method'      => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'authorities' => self::authorities(),
			'path'        => $path,
			'query'       => $query,
			'body'        => self::body(),
			'headers'     => $headers,
		);
	}

	/**
	 * The hosts this request may have been sent to: the Host header, the
	 * X-Forwarded-Host of a proxy in front that rewrote it, and the site's own
	 * addresses (a proxy that sends its backend's name as Host). Lower case,
	 * without the scheme's default port (as Kico's URL.host). What the
	 * signature binds is this site, not another one Kico talks to.
	 *
	 * @return string[]
	 */
	public static function authorities() {
		$hosts = array();
		foreach ( array( 'HTTP_HOST', 'HTTP_X_FORWARDED_HOST' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$hosts[] = trim( explode( ',', (string) wp_unslash( $_SERVER[ $key ] ) )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		foreach ( array( 'home_url', 'site_url' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$u = wp_parse_url( call_user_func( $fn, '/' ) );
				if ( ! empty( $u['host'] ) ) {
					$hosts[] = $u['host'] . ( isset( $u['port'] ) ? ':' . $u['port'] : '' );
				}
			}
		}
		$out = array();
		foreach ( $hosts as $host ) {
			$host = preg_replace( '/:(80|443)$/', '', strtolower( $host ) );
			if ( '' !== $host ) {
				$out[ $host ] = true;
			}
		}
		return array_keys( $out );
	}

	private static $body = null;

	public static function body() {
		if ( null === self::$body ) {
			$raw        = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$body = false === $raw ? '' : $raw;
		}
		return self::$body;
	}

	/**
	 * The signature parameters, or null when the header is missing or malformed.
	 *
	 * @param string $input Signature-Input header.
	 * @return array|null
	 */
	public static function parse_input( $input ) {
		if ( ! preg_match( '/^\s*' . self::LABEL . '=\(([^)]*)\)((?:;[a-z]+=(?:"[^"]*"|[0-9]+))*)\s*$/', $input, $m ) ) {
			return null;
		}
		preg_match_all( '/"([^"]*)"/', $m[1], $c );
		$params = array();
		preg_match_all( '/;([a-z]+)=(?:"([^"]*)"|([0-9]+))/', $m[2], $p, PREG_SET_ORDER );
		foreach ( $p as $one ) {
			$params[ $one[1] ] = '' !== $one[2] ? $one[2] : ( isset( $one[3] ) ? $one[3] : '' );
		}
		return array(
			'components' => $c[1],
			'params'     => $params,
			// The exact serialization signed as @signature-params.
			'raw'        => '(' . $m[1] . ')' . $m[2],
		);
	}

	public static function base( $method, $path, $query, $digest, $params_raw, $authority = null ) {
		return '"@method": ' . $method . "\n"
			. ( null !== $authority ? '"@authority": ' . $authority . "\n" : '' )
			. '"@path": ' . $path . "\n"
			. '"@query": ' . $query . "\n"
			. '"content-digest": ' . $digest . "\n"
			. '"@signature-params": ' . $params_raw;
	}

	public static function digest( $body ) {
		return 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':';
	}

	/**
	 * Checks a request's signature against a public key.
	 *
	 * @param array    $req        From current_request().
	 * @param callable $public_key Called with the keyid, returns the base64 public key or null.
	 * @return array{ok:bool,reason?:string,keyid?:string,nonce?:string,created?:int}
	 */
	public static function verify( array $req, callable $public_key ) {
		if ( empty( $req['headers']['signature-input'] ) || empty( $req['headers']['signature'] ) || empty( $req['headers']['content-digest'] ) ) {
			return array( 'ok' => false, 'reason' => 'unsigned' );
		}
		$input = self::parse_input( $req['headers']['signature-input'] );
		$v2    = $input && self::COMPONENTS_V2 === $input['components'];
		if ( ! $input || ( ! $v2 && self::COMPONENTS !== $input['components'] ) ) {
			return array( 'ok' => false, 'reason' => 'malformed' );
		}
		$params = $input['params'];
		if ( empty( $params['keyid'] ) || empty( $params['nonce'] ) || empty( $params['created'] ) || ( isset( $params['alg'] ) && 'ed25519' !== $params['alg'] ) ) {
			return array( 'ok' => false, 'reason' => 'malformed' );
		}
		if ( ! hash_equals( self::digest( $req['body'] ), $req['headers']['content-digest'] ) ) {
			return array( 'ok' => false, 'reason' => 'digest', 'keyid' => $params['keyid'] );
		}
		if ( ! preg_match( '/^\s*' . self::LABEL . '=:([A-Za-z0-9+\/=]+):\s*$/', $req['headers']['signature'], $s ) ) {
			return array( 'ok' => false, 'reason' => 'malformed' );
		}
		$key = call_user_func( $public_key, $params['keyid'] );
		if ( ! $key ) {
			return array( 'ok' => false, 'reason' => 'unknown-key' );
		}
		$sig = base64_decode( $s[1], true );
		$pub = base64_decode( $key, true );
		if ( false === $sig || false === $pub || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return array( 'ok' => false, 'reason' => 'malformed' );
		}
		$verified = false;
		foreach ( $v2 ? ( isset( $req['authorities'] ) ? (array) $req['authorities'] : array() ) : array( null ) as $authority ) {
			$base = self::base( $req['method'], $req['path'], $req['query'], $req['headers']['content-digest'], $input['raw'], $authority );
			if ( sodium_crypto_sign_verify_detached( $sig, $base, $pub ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			// The key is known: Kico signed something, but not what arrived here.
			return array( 'ok' => false, 'reason' => 'signature', 'keyid' => $params['keyid'] );
		}
		$created = (int) $params['created'];
		$out     = array( 'ok' => true, 'keyid' => $params['keyid'], 'nonce' => $params['nonce'], 'created' => $created );
		if ( abs( time() - $created ) > self::SKEW ) {
			// Signed by the key, so it is Kico: it may hear about the clock.
			return array_merge( $out, array( 'ok' => false, 'reason' => 'clock' ) );
		}
		return $out;
	}
}
