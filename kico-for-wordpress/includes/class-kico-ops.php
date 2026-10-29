<?php
/**
 * Operations: every tool call is one, logged in the audit log. A write first
 * records what it changes (a snapshot), and the snapshot goes back to Kico
 * with the result (in "_kico_op"), so it is stored outside the site where the
 * agent cannot delete it. Rollback gets the snapshot back from Kico and
 * restores exactly that operation, after recording the current state, so a
 * rollback can be rolled back too.
 *
 * Durable: a "started" entry is written before anything runs, and a "done"
 * entry when the call ends, also when it ends in a PHP fatal error, a time
 * limit or exit (a shutdown function records those and answers itself). A
 * write's result and snapshot are kept for fetch by id (Kico_Transport's op
 * route) until Kico acknowledges them, so an answer lost on the way (a proxy
 * timeout, a firewall page) loses nothing.
 *
 * Nothing here refuses: what could not be captured (too large, unreadable)
 * is said in the snapshot's notes, and the model decides what to do.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Ops {

	// A call that ran longer than this keeps its result for fetch by id even
	// when it is a read: Kico may have stopped waiting (async mode).
	const KEEP_AFTER = 20;

	/** The operation running now: op_id, tool, args, write, snapshot, stage, started_at, done. */
	private static $current = null;

	/** Memory set aside for the shutdown function after running out of it. */
	private static $reserve = null;

	private static $shutdown = false;

	public static function active() {
		return null !== self::$current && empty( self::$current['done'] );
	}

	/** The id of a new operation: Kico's, when it gave a new one. */
	private static function new_id() {
		$id = Kico_Transport::take_op_id();
		if ( $id && ! Kico_Store::op_entries( $id ) ) {
			return $id;
		}
		return wp_generate_uuid4();
	}

	/**
	 * Runs one tool call as an operation.
	 *
	 * @param string        $tool    Tool name.
	 * @param array         $args    Its arguments, as the caller gave them.
	 * @param callable|null $capture Records what the write changes (gets a Kico_Snapshot); null for reads.
	 * @param callable      $run     Does the work; gets the snapshot, returns the result.
	 * @return array
	 */
	public static function run( $tool, array $args, $capture, callable $run ) {
		$op_id = self::new_id();
		// A rollback's snapshot came from Kico, which keeps it; the log names the operation.
		$logged = isset( $args['snapshot'] ) ? array_merge( $args, array( 'snapshot' => '[the snapshot Kico kept for ' . ( $args['op_id'] ?? '?' ) . ']' ) ) : $args;
		// Finish even when Kico stops waiting: the result is kept for it.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$started = Kico_Store::audit(
			array(
				'op_id' => $op_id,
				'tool'  => $tool,
				'args'  => $logged,
				'ok'    => false,
				'phase' => 'started',
			)
		);
		$snapshot      = $capture ? new Kico_Snapshot() : null;
		self::$current = array(
			'op_id'      => $op_id,
			'tool'       => $tool,
			'write'      => null !== $capture,
			'snapshot'   => $snapshot,
			'started'    => $started,
			'stage'      => 'capture',
			'started_at' => microtime( true ),
			'done'       => false,
		);
		if ( ! self::$shutdown ) {
			self::$shutdown = true;
			register_shutdown_function( array( __CLASS__, 'shutdown' ) );
		}
		self::$reserve = str_repeat( ' ', 1024 * 1024 );
		if ( $capture ) {
			try {
				call_user_func( $capture, $snapshot );
			} catch ( Throwable $e ) {
				$snapshot->notes[] = 'Taking the snapshot failed: ' . $e->getMessage();
			}
		}
		self::$current['stage'] = 'run';
		$ok = true;
		try {
			// Code tools: recording starts now (files, $wpdb), and ends below.
			if ( $snapshot ) {
				$snapshot->begin();
			}
			$result = call_user_func( $run, $snapshot );
			if ( is_wp_error( $result ) ) {
				$ok     = false;
				$result = array( 'ok' => false, 'error' => $result->get_error_message(), 'code' => $result->get_error_code() );
			}
		} catch ( Throwable $e ) {
			$ok     = false;
			$result = array( 'ok' => false, 'error' => get_class( $e ) . ': ' . $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine() );
		}
		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}
		// A tool that reports a failure in its result (ok: false) failed too.
		if ( isset( $result['ok'] ) && false === $result['ok'] ) {
			$ok = false;
		}
		// After a write: stop recording, then page and object caches (what was purged comes back).
		if ( $snapshot ) {
			try {
				$result += $snapshot->end( $tool, $args, $ok );
			} catch ( Throwable $e ) {
				$snapshot->notes[] = 'Finishing the snapshot failed: ' . $e->getMessage();
			}
		}
		self::$current['stage'] = 'finish';
		$result = self::finish( $result, $ok, $ok ? 'ok' : 'error' );
		self::$reserve = null;
		return $result;
	}

	/**
	 * Ends the current operation: the "done" entry, the result with the
	 * snapshot for Kico, kept for fetch by id when Kico may not get it.
	 *
	 * @param array  $result  The tool's result.
	 * @param bool   $ok      Whether it worked.
	 * @param string $outcome ok, error, fatal, timeout or exit.
	 * @return array The result as answered.
	 */
	private static function finish( array $result, $ok, $outcome ) {
		$c        = self::$current;
		$snapshot = $c['snapshot'];
		$export   = $snapshot ? self::export( $snapshot ) : null;
		$entry    = Kico_Store::audit(
			array(
				'op_id'    => $c['op_id'],
				'tool'     => $c['tool'],
				'args'     => '',
				'result'   => $result,
				'ok'       => $ok,
				'phase'    => 'done',
				'outcome'  => $outcome,
				'snapshot' => $snapshot ? $snapshot->kind() : '',
			)
		);
		self::$current['done'] = true;
		$op = null;
		if ( $export ) {
			$result['op_id'] = $c['op_id'];
			if ( $export['notes'] ) {
				$result['snapshot_notes'] = $export['notes'];
			}
			// For Kico only: it keeps the snapshot and the log entry, and strips
			// this before the agent sees the result.
			$op = array(
				'op_id'    => $c['op_id'],
				'tool'     => $c['tool'],
				'ok'       => $ok,
				'outcome'  => $outcome,
				'phase'    => 'done',
				'snapshot' => $export,
				'audit'    => array(
					'id'        => $entry['id'],
					'at'        => $entry['created_at'],
					'user_id'   => $entry['user_id'],
					'args'      => $c['started']['args'],
					'result'    => $entry['result'],
					'prev_hash' => $entry['prev_hash'],
					'hash'      => $entry['hash'],
					'started'   => array( 'id' => $c['started']['id'], 'at' => $c['started']['created_at'], 'hash' => $c['started']['hash'] ),
				),
			);
		}
		$long = microtime( true ) - $c['started_at'] > self::KEEP_AFTER;
		if ( $op || $long || ! in_array( $outcome, array( 'ok', 'error' ), true ) || ( function_exists( 'connection_aborted' ) && connection_aborted() ) ) {
			self::keep( $c['op_id'], $result, $op );
		}
		Kico_Transport::unlock_write();
		if ( $op ) {
			$result['_kico_op'] = $op;
		}
		return $result;
	}

	/**
	 * A snapshot as sent: bytes is its real JSON-encoded size. One larger than
	 * Kico takes in an answer is not sent; the notes say so.
	 *
	 * @param Kico_Snapshot $snapshot The snapshot.
	 */
	private static function export( Kico_Snapshot $snapshot ) {
		$export          = $snapshot->export();
		$export['bytes'] = strlen( (string) wp_json_encode( $export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
		$cap             = self::snapshot_cap();
		if ( $export['bytes'] <= $cap ) {
			return $export;
		}
		$notes   = (array) $export['notes'];
		$notes[] = sprintf( 'Not kept: the snapshot came to %s, more than the %s Kico takes; this operation cannot be rolled back from Kico. Pass tables, or a smaller path, to snapshot less.', size_format( $export['bytes'] ), size_format( $cap ) );
		$out     = array(
			'kind'    => $export['kind'],
			'bytes'   => $export['bytes'],
			'notes'   => $notes,
			'dropped' => true,
		);
		if ( isset( $export['v'] ) ) {
			$out['v'] = $export['v'];
		}
		return $out;
	}

	/** The largest snapshot an answer carries: what Kico decodes, less room for the rest. */
	public static function snapshot_cap() {
		// Plain JSON (an older Kico) is read up to 20 MB; envelopes up to DECODED_MAX.
		return Kico_Transport::enveloped() ? Kico_Transport::DECODED_MAX - 8 * 1024 * 1024 : 19000000;
	}

	/** Keeps an operation's result (and snapshot) for fetch by id, OP_KEEP. */
	private static function keep( $op_id, array $result, $op ) {
		$key = Kico_Auth::key();
		if ( ! $key ) {
			return;
		}
		unset( $result['_kico_op'] );
		$json  = (string) wp_json_encode( array( 'result' => $result, '_kico_op' => $op ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$bytes = function_exists( 'gzencode' ) ? gzencode( $json, 6 ) : $json;
		Kico_Blobs::stage( $op_id, 'op', $bytes, Kico_Transport::OP_KEEP, (string) $key['keyid'] );
	}

	/**
	 * wp_die while an operation runs: WordPress's fatal error handler ends in
	 * it, and code may call it. The operation ends here, and so does PHP.
	 *
	 * @param string|WP_Error $message What wp_die was given.
	 */
	public static function die_handler( $message = '' ) {
		$text = is_wp_error( $message ) ? $message->get_error_message() : ( is_scalar( $message ) ? (string) $message : '' );
		self::shutdown( trim( wp_strip_all_tags( $text ) ) );
		exit;
	}

	/**
	 * When PHP stops in the middle of an operation (a fatal error, the time
	 * limit, exit, die or wp_die): the "done" entry with that outcome, the
	 * snapshot kept, and, when nothing was sent yet, the answer to Kico.
	 *
	 * @param string|null $died What wp_die said, when it was wp_die.
	 */
	public static function shutdown( $died = null ) {
		if ( ! self::active() ) {
			return;
		}
		self::$reserve = null;
		$err   = error_get_last();
		$fatal = $err && in_array( (int) $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		if ( $fatal && false !== stripos( (string) $err['message'], 'Allowed memory size' ) ) {
			@ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$c       = self::$current;
		$outcome = ! $fatal ? 'exit' : ( false !== stripos( (string) $err['message'], 'Maximum execution time' ) ? 'timeout' : 'fatal' );
		$error   = $fatal
			? sprintf( 'PHP stopped: %s (%s:%d)', $err['message'], $err['file'], $err['line'] )
			: ( null !== $died
				? 'The code called wp_die' . ( '' !== $died ? ': ' . $died : '' ) . '; the call ended there, and what it did until then stays done.'
				: 'The code ended the request (exit or die) before the call finished; what it did until then stays done.' );
		if ( 'capture' === $c['stage'] && $c['snapshot'] ) {
			$c['snapshot']->notes[] = 'PHP stopped while the snapshot was being taken: it is incomplete.';
		}
		$result = array( 'ok' => false, 'outcome' => $outcome, 'error' => $error, 'op_id' => $c['op_id'] );
		// What the code did until then is recorded; stop the recorder and purge caches.
		if ( $c['snapshot'] && 'capture' !== $c['stage'] ) {
			try {
				$result += $c['snapshot']->end( $c['tool'], array(), false );
			} catch ( Throwable $e ) {
				$c['snapshot']->notes[] = 'Finishing the snapshot failed: ' . $e->getMessage();
			}
		}
		try {
			$result = self::finish( $result, false, $outcome );
		} catch ( Throwable $e ) {
			return;
		}
		if ( ! array_key_exists( '_kico_op', $result ) ) {
			$result['_kico_op'] = null;
		}
		if ( headers_sent() ) {
			return;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		Kico_Transport::answer_now( $result );
	}
}
