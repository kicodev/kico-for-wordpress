<?php
/**
 * The transport (docs/wordpress.md, "Transport (protocol v1)"): Kico sends
 * every request to a site that supports it as a signed envelope, the
 * JSON-RPC body gzipped and base64-encoded, so firewalls in front of the
 * site see no SQL, PHP or HTML. Bodies too large for one request travel as
 * blobs in chunks (Kico_Blobs). Answers to enveloped requests are enveloped
 * too, and staged as a blob when larger than Kico asks for.
 *
 * Kico_Auth::gate verifies the signature over the bytes on the wire first
 * (rest_pre_dispatch, priority 0); the envelope is opened after it, at
 * priority 1, so the MCP Adapter sees the plain JSON-RPC message. A request
 * that is not an envelope passes through unchanged.
 *
 * Also here: fetch by id of an operation (the op route), and the per-site
 * write lock (one write at a time; another gets kico_busy, 429).
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Transport {

	const V           = 1;
	const DECODED_MAX = 67108864;
	const CHUNK       = 65536;
	const BLOB_TTL    = 3600;
	const OP_KEEP     = 86400;
	const REPLY_MAX   = 120000;
	// Seconds Kico waits before it sends a write again while another holds the lock.
	const RETRY_AFTER = 5;

	/** The envelope of this request, once opened: enc, op_id, reply_max. */
	private static $env = null;

	/** The JSON-RPC message of this request, when it is one. */
	private static $rpc = null;

	/** Whether the op id of the envelope was taken by an operation. */
	private static $op_taken = false;

	/** The name of the write lock this request holds. */
	private static $write_lock = null;

	/** This answer goes out as it is (a blob get). */
	private static $plain = false;

	/** The uploaded blob this request's body came from, deleted once answered. */
	private static $used_blob = '';

	public static function boot() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'open' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'seal' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'kico_purge', array( 'Kico_Blobs', 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		// While an operation runs, a fatal error (WordPress's fatal error handler
		// ends in wp_die) or a wp_die of the code is the operation's to record
		// and answer (Kico_Ops), not an HTML or JSON page of WordPress.
		foreach ( array( 'wp_die_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_ajax_handler' ) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'die_handler' ), 1000 );
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'kico_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kico_purge' );
		}
	}

	public static function die_handler( $handler ) {
		return Kico_Ops::active() ? array( 'Kico_Ops', 'die_handler' ) : $handler;
	}

	// --- facts -------------------------------------------------------------------------------------

	/** The encodings this PHP can read and write. */
	public static function encodings() {
		return function_exists( 'gzencode' ) && function_exists( 'gzdecode' ) ? array( 'gzip+base64', 'base64' ) : array( 'base64' );
	}

	/** What the plugin supports, as JSON (pair response, env). */
	public static function info() {
		return array( 'v' => self::V, 'enc' => self::encodings(), 'chunk' => self::CHUNK );
	}

	/** The Kico-Transport header. */
	public static function header_value() {
		return sprintf( 'v=%d, enc="%s", chunk=%d', self::V, self::encodings()[0], self::CHUNK );
	}

	public static function enveloped() {
		return null !== self::$env;
	}

	/** The largest answer Kico takes inline for this request. */
	public static function reply_max() {
		return self::$env ? self::$env['reply_max'] : self::REPLY_MAX;
	}

	/**
	 * The op id Kico gave this request (once: the first operation takes it).
	 *
	 * @return string '' when there is none.
	 */
	public static function take_op_id() {
		if ( self::$op_taken || ! self::$env ) {
			return '';
		}
		self::$op_taken = true;
		return (string) self::$env['op_id'];
	}

	/** The JSON-RPC id of this request. */
	public static function rpc_id() {
		return is_array( self::$rpc ) && array_key_exists( 'id', self::$rpc ) ? self::$rpc['id'] : null;
	}

	public static function is_uuid( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
	}

	public static function error( $code, $message, $status, array $data = array() ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) + $data );
	}

	private static function is_kico_route( $route ) {
		return 0 === strpos( (string) $route, '/' . Kico_Auth::NS . '/' );
	}

	// --- requests ----------------------------------------------------------------------------------

	/**
	 * Opens an envelope after the gate let the request through, and takes the
	 * write lock for a write.
	 *
	 * @param mixed           $result  Response so far (an error when the gate refused).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 */
	public static function open( $result, $server, $request ) {
		$route = $request->get_route();
		if ( null !== $result || ! self::is_kico_route( $route ) || '/' . Kico_Auth::NS . '/pair' === $route ) {
			return $result;
		}
		$raw = (string) $request->get_body();
		$msg = null;
		// Cheap test first: an envelope starts with its marker.
		if ( false !== strpos( substr( $raw, 0, 200 ), '"kico_envelope"' ) ) {
			$env = json_decode( $raw, true );
			if ( ! is_array( $env ) || empty( $env['kico_envelope'] ) ) {
				return self::error( 'kico_envelope', 'The envelope is not JSON.', 400 );
			}
			$body = self::decode( $env );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$request->set_body( $body );
			$request->set_header( 'content-type', 'application/json' );
			self::$env = array(
				'enc'       => (string) $env['enc'],
				'op_id'     => isset( $env['op_id'] ) && self::is_uuid( strtolower( (string) $env['op_id'] ) ) ? strtolower( (string) $env['op_id'] ) : '',
				'reply_max' => isset( $env['reply_max'] ) ? max( 4096, min( self::DECODED_MAX, (int) $env['reply_max'] ) ) : self::REPLY_MAX,
			);
			if ( ! empty( $env['ack'] ) && is_array( $env['ack'] ) ) {
				Kico_Blobs::delete( array_slice( $env['ack'], 0, 500 ), self::keyid(), 'op' );
			}
			$msg = json_decode( $body, true );
		} elseif ( '/' . Kico_Auth::NS . '/mcp' === $route ) {
			$msg = json_decode( $raw, true );
		}
		self::$rpc = is_array( $msg ) ? $msg : null;
		if ( '/' . Kico_Auth::NS . '/mcp' === $route && self::$rpc && 'tools/call' === ( self::$rpc['method'] ?? '' ) && self::is_write( (string) ( self::$rpc['params']['name'] ?? '' ) ) && ! self::lock_write() ) {
			return self::error( 'kico_busy', 'Another write is running on this site. Kico sends this one again shortly; it did not run.', 429, array( 'retry_after' => self::RETRY_AFTER ) );
		}
		return $result;
	}

	/**
	 * The JSON body an envelope carries.
	 *
	 * @param array $env The envelope.
	 * @return string|WP_Error
	 */
	private static function decode( array $env ) {
		$enc = (string) ( $env['enc'] ?? '' );
		if ( ! in_array( $enc, self::encodings(), true ) ) {
			return self::error( 'kico_envelope', sprintf( 'Unknown encoding "%s"; this site reads %s.', $enc, implode( ', ', self::encodings() ) ), 400 );
		}
		$has_data = isset( $env['data'] );
		$has_blob = isset( $env['blob'] );
		if ( $has_data === $has_blob ) {
			return self::error( 'kico_envelope', 'An envelope carries data or blob, never both or neither.', 400 );
		}
		if ( $has_data ) {
			$bytes = base64_decode( (string) $env['data'], true );
			if ( false === $bytes ) {
				return self::error( 'kico_envelope', 'The envelope\'s data is not base64.', 400 );
			}
		} else {
			$b = is_array( $env['blob'] ) ? $env['blob'] : array();
			$bytes = Kico_Blobs::take( strtolower( (string) ( $b['id'] ?? '' ) ), (int) ( $b['size'] ?? -1 ), (string) ( $b['sha256'] ?? '' ), self::keyid() );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			self::$used_blob = strtolower( (string) $b['id'] );
		}
		return self::unpack( $bytes, $enc );
	}

	/**
	 * The pair route's body: plain JSON, or an envelope with data (Kico sends
	 * one when the connect form said this plugin reads them), since the body
	 * carries text a firewall may block. Its signature covers the envelope.
	 *
	 * @return string|WP_Error
	 */
	public static function pair_body( $raw ) {
		if ( false === strpos( substr( $raw, 0, 200 ), '"kico_envelope"' ) ) {
			return $raw;
		}
		$env = json_decode( $raw, true );
		if ( ! is_array( $env ) || isset( $env['blob'] ) ) {
			return self::error( 'kico_envelope', 'A pairing envelope carries data.', 400 );
		}
		return self::decode( $env );
	}

	/**
	 * Decoded bytes of a body, at most DECODED_MAX.
	 *
	 * @return string|WP_Error
	 */
	private static function unpack( $bytes, $enc ) {
		if ( 'gzip+base64' === $enc ) {
			$out = @gzdecode( $bytes, self::DECODED_MAX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $out ) {
				return self::error( 'kico_envelope', sprintf( 'The body is not gzip, or larger than %d bytes decoded.', self::DECODED_MAX ), 400 );
			}
			return $out;
		}
		if ( strlen( $bytes ) > self::DECODED_MAX ) {
			return self::error( 'kico_envelope', sprintf( 'The body is larger than %d bytes.', self::DECODED_MAX ), 400 );
		}
		return $bytes;
	}

	private static function pack( $json, $enc ) {
		return 'gzip+base64' === $enc ? gzencode( $json, 6 ) : $json;
	}

	private static function keyid() {
		$key = Kico_Auth::key();
		return $key ? (string) $key['keyid'] : '';
	}

	/** Whether a tool changes the site (its annotations say so). */
	public static function is_write( $tool ) {
		$defs = Kico_Server::definitions();
		$name = str_replace( '_', '-', $tool );
		return isset( $defs[ $name ] ) && empty( $defs[ $name ][2]['readonly'] );
	}

	/** One write at a time on this site: false when another holds the lock. */
	private static function lock_write() {
		global $wpdb;
		$name = Kico_Store::lock_name( 'kico_write_', $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { // phpcs:ignore WordPress.DB
			return false;
		}
		self::$write_lock = $name;
		return true;
	}

	/** Lets the next write run (also released when the request ends). */
	public static function unlock_write() {
		global $wpdb;
		if ( self::$write_lock ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$write_lock ) ); // phpcs:ignore WordPress.DB
			self::$write_lock = null;
		}
	}

	// --- answers -----------------------------------------------------------------------------------

	/**
	 * Every answer of a signed Kico request says what the transport supports;
	 * answers to envelopes are enveloped, except WordPress errors.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 */
	public static function seal( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! self::is_kico_route( $request->get_route() ) ) {
			return $response;
		}
		$pair  = '/' . Kico_Auth::NS . '/pair' === $request->get_route();
		$state = Kico_Auth::state();
		if ( ( $pair && 200 === $response->get_status() ) || ! empty( $state['ok'] ) || 'clock' === ( $state['reason'] ?? '' ) ) {
			$response->header( 'Kico-Transport', self::header_value() );
			// The version the site runs from now on, so Kico's card follows updates made anywhere.
			$response->header( 'Kico-Plugin', Kico_Updates::version() );
		}
		$data = $response->get_data();
		if ( is_array( $data ) && 'kico_busy' === ( $data['code'] ?? '' ) ) {
			$response->header( 'Retry-After', (string) self::RETRY_AFTER );
		} else {
			self::forget_blob();
		}
		if ( '/' . Kico_Auth::NS . '/mcp' === $request->get_route() ) {
			$data = self::send_op_once( $data );
			$response->set_data( $data );
		}
		if ( ! self::$env || self::$plain || null === $data || self::is_rest_error( $data ) ) {
			return $response;
		}
		$response->set_data( self::wrap( $data ) );
		return $response;
	}

	public static function forget_blob() {
		if ( '' !== self::$used_blob ) {
			Kico_Blobs::delete( self::$used_blob, self::keyid(), 'in' );
			self::$used_blob = '';
		}
	}

	private static function is_rest_error( $data ) {
		return is_array( $data ) && ! isset( $data['jsonrpc'] ) && isset( $data['code'], $data['message'] ) && is_string( $data['code'] ) && is_string( $data['message'] );
	}

	/**
	 * "_kico_op" goes once: in structuredContent. The adapter writes the text
	 * part from the same result, so it is taken out there.
	 *
	 * @param mixed $data A JSON-RPC answer.
	 */
	private static function send_op_once( $data ) {
		if ( null === $data || ! self::$rpc || 'tools/call' !== ( self::$rpc['method'] ?? '' ) ) {
			return $data;
		}
		// The adapter's answer holds its own record objects: made plain JSON
		// objects first (stdClass, so {} stays an object), then changed.
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( ! is_string( $json ) || false === strpos( $json, '_kico_op' ) ) {
			return $data;
		}
		$obj = json_decode( $json );
		unset( $json );
		if ( ! is_object( $obj ) || ! isset( $obj->result->content ) || ! is_array( $obj->result->content ) ) {
			return $data;
		}
		foreach ( $obj->result->content as $part ) {
			if ( ! is_object( $part ) || 'text' !== ( $part->type ?? '' ) || ! is_string( $part->text ?? null ) || false === strpos( $part->text, '"_kico_op"' ) ) {
				continue;
			}
			$parsed = json_decode( $part->text );
			if ( is_object( $parsed ) && property_exists( $parsed, '_kico_op' ) ) {
				unset( $parsed->_kico_op );
				$part->text = (string) wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			}
		}
		return $obj;
	}

	/**
	 * An answer in an envelope: inline when it fits the request's reply_max,
	 * else staged as a blob for Kico to fetch.
	 *
	 * @param mixed $data The answer.
	 * @return array
	 */
	public static function wrap( $data ) {
		$enc    = self::$env ? self::$env['enc'] : self::encodings()[0];
		$json   = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$packed = self::pack( $json, $enc );
		unset( $json );
		// The envelope around data adds about 50 bytes.
		if ( (int) ( ceil( strlen( $packed ) / 3 ) * 4 ) + 64 <= self::reply_max() ) {
			return array( 'kico_envelope' => 1, 'enc' => $enc, 'data' => base64_encode( $packed ) );
		}
		$staged = Kico_Blobs::stage( wp_generate_uuid4(), 'out', $packed, self::BLOB_TTL, self::keyid() );
		if ( is_wp_error( $staged ) ) {
			// Nothing better to do than answer inline; Kico may still take it.
			return array( 'kico_envelope' => 1, 'enc' => $enc, 'data' => base64_encode( $packed ) );
		}
		$chunk = self::chunk_out();
		return array(
			'kico_envelope' => 1,
			'enc'           => $enc,
			'blob'          => array(
				'id'     => $staged['id'],
				'count'  => max( 1, (int) ceil( $staged['size'] / $chunk ) ),
				'size'   => $staged['size'],
				'sha256' => $staged['sha256'],
			),
		);
	}

	/** Raw bytes per chunk of a staged answer: each blob get stays under reply_max. */
	private static function chunk_out() {
		return max( 1024, (int) floor( self::reply_max() / 2 ) );
	}

	/**
	 * Answers the request now, outside the REST server (an operation's fatal
	 * error or exit, from Kico_Ops::shutdown).
	 *
	 * @param array $structured The tool result, with _kico_op.
	 */
	public static function answer_now( array $structured ) {
		self::forget_blob();
		$text = $structured;
		unset( $text['_kico_op'] );
		$data = array(
			'jsonrpc' => '2.0',
			'id'      => self::rpc_id(),
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
				'isError'           => true,
				'structuredContent' => $structured,
			),
		);
		if ( self::$env ) {
			$data = self::wrap( $data );
		}
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Content-Length' );
		}
		http_response_code( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Kico-Transport: ' . self::header_value() );
		header( 'Kico-Plugin: ' . Kico_Updates::version() );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// --- routes ------------------------------------------------------------------------------------

	public static function routes() {
		foreach ( array( 'blob', 'op' ) as $name ) {
			register_rest_route(
				Kico_Auth::NS,
				'/' . $name,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'route_' . $name ),
					'permission_callback' => array( 'Kico_Auth', 'bound_user_can' ),
				)
			);
		}
	}

	/**
	 * Chunks of uploads and of staged answers.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_blob( $request ) {
		$p      = (array) $request->get_json_params();
		$keyid  = self::keyid();
		$action = (string) ( $p['action'] ?? '' );
		Kico_Blobs::purge();
		if ( 'put' === $action ) {
			$r = Kico_Blobs::put( $p, $keyid );
			return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
		}
		if ( 'get' === $action ) {
			$id   = strtolower( (string) ( $p['id'] ?? '' ) );
			$info = Kico_Blobs::info( $id, 'out', $keyid );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			$chunk = self::chunk_out();
			$count = max( 1, (int) ceil( (int) $info['size'] / $chunk ) );
			$seq   = (int) ( $p['seq'] ?? -1 );
			if ( $seq < 0 || $seq >= $count ) {
				return self::error( 'kico_blob', sprintf( 'seq is 0 to %d.', $count - 1 ), 422, array( 'reason' => 'invalid' ) );
			}
			self::$plain = true;
			return rest_ensure_response( array( 'id' => $id, 'seq' => $seq, 'count' => $count, 'data' => base64_encode( Kico_Blobs::range( $id, 'out', $keyid, $seq * $chunk, $chunk ) ) ) );
		}
		if ( 'delete' === $action ) {
			Kico_Blobs::delete( (string) ( $p['id'] ?? '' ), $keyid );
			return rest_ensure_response( array( 'deleted' => true ) );
		}
		return self::error( 'kico_blob', 'action is put, get or delete.', 422, array( 'reason' => 'invalid' ) );
	}

	/**
	 * An operation by id, and acknowledging kept ones.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_op( $request ) {
		$p      = (array) $request->get_json_params();
		$action = (string) ( $p['action'] ?? '' );
		if ( 'get' === $action ) {
			$op = self::op_info( strtolower( (string) ( $p['op_id'] ?? '' ) ) );
			return is_wp_error( $op ) ? $op : rest_ensure_response( $op );
		}
		if ( 'ack' === $action ) {
			$ids = isset( $p['op_ids'] ) && is_array( $p['op_ids'] ) ? array_slice( $p['op_ids'], 0, 500 ) : array();
			return rest_ensure_response( array( 'acked' => Kico_Blobs::delete( $ids, self::keyid(), 'op' ) > 0 ? count( $ids ) : 0 ) );
		}
		return self::error( 'kico_op', 'action is get or ack.', 422 );
	}

	/**
	 * What the site knows of an operation: its phase, outcome and result, and
	 * the snapshot while the site keeps it.
	 *
	 * @param string $op_id Operation id.
	 * @return array|WP_Error
	 */
	public static function op_info( $op_id ) {
		$unknown = self::error( 'kico_op_unknown', 'This site never started an operation with that id: the call did not reach the tool.', 404 );
		if ( ! self::is_uuid( $op_id ) ) {
			return $unknown;
		}
		$keyid   = self::keyid();
		$started = null;
		$done    = null;
		foreach ( Kico_Store::op_entries( $op_id ) as $e ) {
			if ( $keyid && $e['keyid'] && $e['keyid'] !== $keyid ) {
				continue;
			}
			if ( 'started' === $e['phase'] ) {
				$started = $e;
			} else {
				$done = $e;
			}
		}
		if ( ! $started && ! $done ) {
			return $unknown;
		}
		$kept = null;
		if ( $keyid ) {
			$bytes = Kico_Blobs::read( $op_id, 'op', $keyid );
			if ( '' !== $bytes ) {
				$json = function_exists( 'gzdecode' ) ? @gzdecode( $bytes, self::DECODED_MAX ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$kept = json_decode( false === $json ? $bytes : $json, true );
			}
		}
		$time   = function ( $e ) {
			return $e ? str_replace( ' ', 'T', $e['created_at'] ) . 'Z' : null;
		};
		$first  = $started ? $started : $done;
		$result = is_array( $kept ) && array_key_exists( 'result', $kept ) ? $kept['result'] : ( $done ? json_decode( $done['result'], true ) : null );
		if ( $done && null === $result && '' !== $done['result'] ) {
			$result = $done['result'];
		}
		return array(
			'op_id'       => $op_id,
			'tool'        => $first['tool'],
			'phase'       => $done ? 'done' : 'started',
			'ok'          => $done ? (bool) (int) $done['ok'] : null,
			'outcome'     => $done ? ( '' !== $done['outcome'] ? $done['outcome'] : ( (int) $done['ok'] ? 'ok' : 'error' ) ) : null,
			'started_at'  => $time( $first ),
			'finished_at' => $time( $done ),
			'result'      => $result,
			'_kico_op'    => is_array( $kept ) && isset( $kept['_kico_op'] ) ? $kept['_kico_op'] : null,
		);
	}
}
