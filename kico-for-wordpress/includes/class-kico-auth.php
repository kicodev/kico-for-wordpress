<?php
/**
 * Who is calling: every request to Kico's routes must carry Kico's signature.
 * Unsigned or wrongly signed requests get the same 404 as a route that does
 * not exist, so the endpoint stays unseen. A signed request acts as the admin
 * the key is bound to, whose capability is checked again on every call (on
 * multisite: a super admin, since Kico's tools reach the whole network).
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Auth {

	const NS = 'kico/v1';
	// Pair attempts per IP and 10 minutes, while a pairing is open.
	const PAIR_LIMIT = 20;

	private static $state    = null;
	private static $switched = false;

	public static function boot() {
		add_filter( 'determine_current_user', array( __CLASS__, 'current_user' ), 30 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate' ), 0, 3 );
		add_filter( 'rest_index', array( __CLASS__, 'hide_from_index' ) );
		add_filter( 'rest_namespace_index', array( __CLASS__, 'hide_from_index' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'only_rest' ), 0 );
	}

	/**
	 * Who may connect Kico and whom a key may act as: an administrator; on
	 * multisite a super admin (Kico's PHP, SQL and files reach every site).
	 *
	 * @param int|WP_User $user User.
	 */
	public static function is_admin_user( $user ) {
		$id = $user instanceof WP_User ? (int) $user->ID : (int) $user;
		if ( $id <= 0 ) {
			return false;
		}
		return is_multisite() ? is_super_admin( $id ) : user_can( $id, 'manage_options' );
	}

	/** The role the page and errors name. */
	public static function admin_role() {
		return is_multisite() ? __( 'super admin (network administrator)', 'kico' ) : __( 'administrator', 'kico' );
	}

	/**
	 * Whether this request is for one of Kico's routes, from the address alone
	 * (early, before WordPress routes it): the REST route WordPress will
	 * dispatch, from ?rest_route= when given (it wins over the path) or from
	 * the path under the REST prefix.
	 */
	public static function is_kico_request() {
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( null !== $route ) {
			return 0 === strpos( '/' . ltrim( $route, '/' ), '/' . self::NS . '/' );
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . '/' . trim( rest_get_url_prefix(), '/' ) . '/' . self::NS . '/';
		return 0 === strpos( $path, $prefix );
	}

	/**
	 * The verified signature of this request (checked once).
	 *
	 * @return array{ok:bool,reason?:string,keyid?:string,key?:array}
	 */
	public static function state() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		if ( ! self::is_kico_request() ) {
			return self::$state = array( 'ok' => false, 'reason' => 'not-kico' );
		}
		$v = Kico_Signature::verify(
			Kico_Signature::current_request(),
			function ( $keyid ) {
				$key = Kico_Keys::get( $keyid );
				return $key ? $key['public_key'] : null;
			}
		);
		if ( $v['ok'] && ! Kico_Store::use_nonce( $v['nonce'], $v['created'] ) ) {
			$v = array( 'ok' => false, 'reason' => 'replay' );
		}
		if ( $v['ok'] ) {
			$v['key'] = Kico_Keys::get( $v['keyid'] );
			Kico_Keys::touch( $v['keyid'] );
		}
		return self::$state = $v;
	}

	/** The key of this request, when it is a verified Kico request. */
	public static function key() {
		$s = self::state();
		return $s['ok'] ? $s['key'] + array( 'keyid' => $s['keyid'] ) : null;
	}

	/** A signed request acts as the key's user, on Kico's routes only. */
	public static function current_user( $user_id ) {
		if ( ! self::is_kico_request() ) {
			return $user_id;
		}
		$key = self::key();
		if ( ! $key ) {
			return $user_id;
		}
		self::$switched = true;
		return (int) $key['user_id'];
	}

	/**
	 * A request that looked like Kico's but is not a REST request after all
	 * (REST turned off, a rewrite elsewhere) gets no user from the key.
	 *
	 * @param WP $wp The request.
	 */
	public static function only_rest( $wp ) {
		if ( self::$switched && empty( $wp->query_vars['rest_route'] ) ) {
			self::$switched = false;
			wp_set_current_user( 0 );
		}
	}

	/** The administrator capability, checked on every call. */
	public static function bound_user_can() {
		$key = self::key();
		return $key && self::is_admin_user( (int) $key['user_id'] );
	}

	public static function not_found() {
		return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message, word for word.
	}

	private static function clock_error() {
		return new WP_Error(
			'kico_clock',
			sprintf( 'The site clock and Kico differ by more than %d seconds (site time %d).', Kico_Signature::SKEW, time() ),
			array( 'status' => 401, 'server_time' => time() )
		);
	}

	/**
	 * Kico's routes answer only signed requests; everything else is a 404.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 */
	public static function gate( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NS ) ) {
			if ( self::$switched ) {
				// Signed for a Kico route, dispatched to another one: not with the key's user.
				wp_set_current_user( 0 );
				return self::not_found();
			}
			return $result;
		}
		// Pairing is signed with the new key, which the handler checks itself.
		if ( '/' . self::NS . '/pair' === $route ) {
			return $result;
		}
		$s = self::state();
		if ( ! $s['ok'] ) {
			if ( 'clock' === $s['reason'] ) {
				return self::clock_error();
			}
			// Signed with a key this site holds, but not over what arrived: a proxy
			// rewrote the path, the host or the body. Kico hears what the site saw.
			if ( in_array( $s['reason'], array( 'signature', 'digest' ), true ) && ! empty( $s['keyid'] ) && Kico_Keys::get( $s['keyid'] ) ) {
				$req  = Kico_Signature::current_request();
				$seen = sprintf( ' The site saw %s %s%s at %s.', $req['method'], $req['path'], '?' === $req['query'] ? '' : $req['query'], implode( ' / ', $req['authorities'] ) );
				return new WP_Error(
					'kico_signature',
					( 'digest' === $s['reason']
						? 'The request body that reached the site is not the one Kico signed: something in front of the site (a firewall, a proxy) changed it.'
						: 'The signature does not cover the request as it reached the site: something in front of the site (a proxy, a rewrite) changed its path, query or host.' ) . $seen,
					array(
						'status'      => 401,
						'method'      => $req['method'],
						'path'        => $req['path'],
						'query'       => $req['query'],
						'authorities' => $req['authorities'],
						'reason'      => $s['reason'],
					)
				);
			}
			return self::not_found();
		}
		$key  = $s['key'];
		$user = get_userdata( (int) $key['user_id'] );
		if ( ! $user || ! self::is_admin_user( $user ) ) {
			return new WP_Error(
				'kico_not_admin',
				sprintf(
					'The WordPress user this connection acts as (%1$s) is no longer a %2$s of this site. A %2$s connects Kico again in wp-admin.',
					$user ? $user->user_login : '#' . (int) $key['user_id'],
					self::admin_role()
				),
				array( 'status' => 403 )
			);
		}
		return $result;
	}

	public static function hide_from_index( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values( array_diff( $data['namespaces'], array( self::NS ) ) );
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( 0 === strpos( $route, '/' . self::NS ) ) {
					unset( $data['routes'][ $route ] );
				}
			}
		}
		$response->set_data( $data );
		return $response;
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pair' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/origin',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'origin' ),
				'permission_callback' => array( __CLASS__, 'bound_user_can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'disconnect' ),
				'permission_callback' => array( __CLASS__, 'bound_user_can' ),
			)
		);
	}

	/** Too many pair attempts from this address in 10 minutes. */
	private static function pair_limited() {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$name = 'kico_pair_ip_' . substr( hash( 'sha256', $ip ), 0, 32 );
		$n    = (int) get_transient( $name );
		set_transient( $name, $n + 1, Kico_Keys::PAIR_TTL );
		return $n >= self::PAIR_LIMIT;
	}

	/**
	 * Kico finishes a pairing the admin started in wp-admin: the one-time code,
	 * and the new public key, with the request signed by its private key. The
	 * route is a 404 unless an admin started a pairing in the last 10 minutes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function pair( $request ) {
		if ( ! Kico_Keys::pairing_open() ) {
			return self::not_found();
		}
		if ( self::pair_limited() ) {
			return new WP_Error( 'kico_pair_limit', 'Too many pairing attempts from this address. Wait 10 minutes.', array( 'status' => 429 ) );
		}
		$raw = Kico_Transport::pair_body( Kico_Signature::body() );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || empty( $body['code'] ) || empty( $body['public_key'] ) || empty( $body['keyid'] ) || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', (string) $body['keyid'] ) ) {
			return self::not_found();
		}
		// The code first: without a live one nothing else is looked at.
		if ( ! Kico_Keys::peek_pair_code( (string) $body['code'] ) ) {
			return new WP_Error( 'kico_pair_code', 'This pairing code expired or was already used. Click "Connect to Kico" in wp-admin again.', array( 'status' => 410 ) );
		}
		$keyid = (string) $body['keyid'];
		$v     = Kico_Signature::verify(
			Kico_Signature::current_request(),
			function ( $id ) use ( $keyid, $body ) {
				return $id === $keyid ? (string) $body['public_key'] : null;
			}
		);
		if ( ! $v['ok'] ) {
			return 'clock' === ( $v['reason'] ?? '' ) ? self::clock_error() : self::not_found();
		}
		if ( ! Kico_Store::use_nonce( $v['nonce'], $v['created'] ) ) {
			return self::not_found();
		}
		$pending = Kico_Keys::take_pair_code( (string) $body['code'] );
		if ( ! $pending ) {
			return new WP_Error( 'kico_pair_code', 'This pairing code expired or was already used. Click "Connect to Kico" in wp-admin again.', array( 'status' => 410 ) );
		}
		$user = get_userdata( (int) $pending['user_id'] );
		if ( ! $user || ! self::is_admin_user( $user ) ) {
			return new WP_Error( 'kico_not_admin', sprintf( 'The user who started the pairing is not a %s.', self::admin_role() ), array( 'status' => 403 ) );
		}
		$origin  = isset( $body['kico_origin'] ) ? untrailingslashit( esc_url_raw( (string) $body['kico_origin'] ) ) : '';
		$account = isset( $body['kico_account'] ) && is_array( $body['kico_account'] ) ? array_map( 'sanitize_text_field', array_map( 'strval', $body['kico_account'] ) ) : array();
		// The key this one replaces (the same Kico connecting again) goes.
		$replaced = '';
		if ( ! empty( $body['replaces'] ) && is_string( $body['replaces'] ) ) {
			$old = Kico_Keys::get( $body['replaces'] );
			// The same Kico: at its origin, or where it was before it moved.
			if ( $old && Kico_Keys::belongs_to( $old, $origin ) && Kico_Keys::remove( $body['replaces'] ) ) {
				$replaced = (string) $body['replaces'];
			}
		}
		Kico_Keys::add(
			$keyid,
			array(
				'public_key'   => (string) $body['public_key'],
				'user_id'      => (int) $user->ID,
				'kico_origin'  => $origin,
				'kico_account' => $account,
				'created_at'   => time(),
			)
		);
		wp_set_current_user( $user->ID );
		Kico_Store::audit(
			array(
				'op_id'  => wp_generate_uuid4(),
				'tool'   => 'pair',
				'args'   => array( 'keyid' => $keyid, 'kico_origin' => $origin, 'kico_account' => $account, 'replaces' => $replaced ),
				'ok'     => true,
				'keyid'  => $keyid,
				'result' => $replaced ? 'Paired with Kico (new key, replacing ' . $replaced . ').' : 'Paired with Kico.',
			)
		);
		Kico_Mail::paired( $user, $origin, $account, $replaced );
		return rest_ensure_response(
			Kico_Tools::site_info() + array(
				'signature' => Kico_Signature::VERSION,
				'user'      => array( 'id' => $user->ID, 'login' => $user->user_login, 'name' => $user->display_name, 'email' => $user->user_email ),
			)
		);
	}

	/**
	 * Kico moved to another address (kico.dev to app.kico.dev): the request,
	 * signed with this key, names the key's Kico origin from now on. The
	 * site's previews may be framed from there, and from the old origin for
	 * previous_days more (default 90; 0 ends it). Settings > Kico's address
	 * follows when it was the old one. Only this key changes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function origin( $request ) {
		$key = self::key();
		$p   = (array) $request->get_json_params();
		$new = Kico_Frame::origin( (string) ( $p['kico_origin'] ?? '' ) );
		if ( '' === $new ) {
			return new WP_Error( 'kico_origin', 'kico_origin is the origin of Kico (https://host[:port]).', array( 'status' => 422 ) );
		}
		$days   = isset( $p['previous_days'] ) ? (int) $p['previous_days'] : Kico_Keys::PREVIOUS_DAYS;
		$before = (string) ( $key['kico_origin'] ?? '' );
		$after  = Kico_Keys::set_origin( $key['keyid'], $new, $days );
		if ( ! $after ) {
			return self::not_found();
		}
		// Settings > Kico's address (where Connect to Kico and updates go) follows, unless wp-config.php fixes it.
		$url = (string) get_option( Kico_Admin::KICO_URL_OPTION, '' );
		if ( ! defined( 'KICO_URL' ) && '' !== $url && '' !== $before && untrailingslashit( $url ) === $before && $before !== $new ) {
			update_option( Kico_Admin::KICO_URL_OPTION, $new );
		}
		$previous = Kico_Keys::previous_origins( $after );
		if ( $before !== $new || isset( $p['previous_days'] ) ) {
			Kico_Store::audit(
				array(
					'op_id'  => wp_generate_uuid4(),
					'tool'   => 'origin',
					'args'   => array( 'keyid' => $key['keyid'], 'kico_origin' => $new, 'from' => $before, 'previous_days' => $days ),
					'ok'     => true,
					'keyid'  => $key['keyid'],
					'result' => $before !== $new ? sprintf( 'Kico moved from %s to %s.', $before, $new ) : sprintf( 'Kico stays at %s.', $new ),
				)
			);
		}
		return rest_ensure_response(
			array(
				'kico_origin' => $new,
				'previous'    => array_map(
					function ( $origin, $until ) {
						return array( 'origin' => $origin, 'until' => (int) $until );
					},
					array_keys( $previous ),
					array_values( $previous )
				),
			)
		);
	}

	/** Kico's Disconnect: the key is forgotten here too. */
	public static function disconnect( $request ) {
		$key = self::key();
		Kico_Keys::remove( $key['keyid'] );
		Kico_Store::audit(
			array(
				'op_id'  => wp_generate_uuid4(),
				'tool'   => 'disconnect',
				'args'   => array( 'keyid' => $key['keyid'], 'from' => 'kico' ),
				'ok'     => true,
				'keyid'  => $key['keyid'],
				'result' => 'Disconnected from Kico.',
			)
		);
		Kico_Mail::disconnected( $key, 'kico' );
		return rest_ensure_response( array( 'disconnected' => true ) );
	}
}
