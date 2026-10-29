<?php
/**
 * Mail to the site's administrators when Kico is connected, disconnected or
 * gets a new key: every administrator of the site (and on multisite every
 * super admin) and the site's admin email. A mail that WordPress could not
 * send is kept and shown in Settings > Kico.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Mail {

	const FAILURES = 'kico_mail_failures';

	/** Everyone who hears about Kico on this site. */
	public static function recipients() {
		$to = array( (string) get_option( 'admin_email' ) );
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'user_email' ) ) ) as $u ) {
			$to[] = (string) $u->user_email;
		}
		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$to[] = (string) $u->user_email;
				}
			}
		}
		return array_values( array_unique( array_filter( $to, 'is_email' ) ) );
	}

	/**
	 * Sends one mail to every recipient; failures are kept for wp-admin.
	 *
	 * @param string $subject Subject, without the site name.
	 * @param string $text    Plain text.
	 */
	public static function send( $subject, $text ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] %s', $site, $subject );
		$errors  = array();
		$catch   = function ( $error ) use ( &$errors ) {
			$errors[] = $error instanceof WP_Error ? $error->get_error_message() : 'unknown error';
		};
		add_action( 'wp_mail_failed', $catch );
		foreach ( self::recipients() as $address ) {
			if ( ! wp_mail( $address, $subject, $text ) && ! $errors ) {
				$errors[] = 'wp_mail returned false';
			}
			if ( $errors ) {
				self::failed( $address, $subject, end( $errors ) );
				$errors = array();
			}
		}
		remove_action( 'wp_mail_failed', $catch );
	}

	private static function failed( $address, $subject, $error ) {
		$list   = self::failures();
		$list[] = array( 'at' => time(), 'to' => $address, 'subject' => $subject, 'error' => substr( (string) $error, 0, 300 ) );
		update_option( self::FAILURES, array_slice( $list, -10 ), false );
	}

	/** The last mails that could not be sent. */
	public static function failures() {
		$list = get_option( self::FAILURES, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function clear_failures() {
		delete_option( self::FAILURES );
	}

	private static function footer() {
		return "\n\n" . sprintf(
			/* translators: %s: the Settings > Kico address. */
			__( "Who is connected, and every call Kico made: %s\nIf this was not you or someone you trust, click Disconnect there, or add define( 'KICO_DISABLED', true ); to wp-config.php.", 'kico' ),
			admin_url( 'options-general.php?page=kico' )
		);
	}

	private static function who( array $account ) {
		$who = trim( ( $account['name'] ?? '' ) . ( ! empty( $account['email'] ) ? ' <' . $account['email'] . '>' : '' ) );
		return '' !== $who ? $who : __( 'a Kico user', 'kico' );
	}

	/**
	 * Connected, or the same Kico connected again with a new key.
	 *
	 * @param WP_User $user     The admin the key acts as.
	 * @param string  $origin   Kico's address.
	 * @param array   $account  Kico's account (name, email).
	 * @param string  $replaced The key id the new key replaces, if any.
	 */
	public static function paired( $user, $origin, array $account, $replaced = '' ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$text = $replaced
			? sprintf(
				/* translators: 1: site name, 2: Kico address, 3: Kico account, 4: WordPress user. */
				__( "%1\$s got a new Kico key from %2\$s (%3\$s), acting as the WordPress user %4\$s. The previous key no longer works.", 'kico' ),
				$site,
				$origin,
				self::who( $account ),
				$user->user_login
			)
			: sprintf(
				/* translators: 1: site name, 2: Kico address, 3: Kico account, 4: WordPress user. */
				__( "%1\$s was connected to Kico (%2\$s) by %3\$s, acting as the WordPress user %4\$s.\n\nKico's assistant and agents can now run PHP and SQL on this site, read and change its files (wp-config.php too), and manage users, content, plugins and themes, whatever DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS say. Every change is logged here and snapshotted in Kico.", 'kico' ),
				$site,
				$origin,
				self::who( $account ),
				$user->user_login
			);
		self::send( $replaced ? __( 'New Kico key', 'kico' ) : __( 'Connected to Kico', 'kico' ), $text . self::footer() );
	}

	/**
	 * Disconnected, in Kico or in wp-admin.
	 *
	 * @param array  $key  The key that went (kico_origin, kico_account).
	 * @param string $from "kico" or "wp-admin".
	 */
	public static function disconnected( array $key, $from ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$by   = 'kico' === $from ? __( 'in Kico', 'kico' ) : sprintf( /* translators: %s: user login. */ __( 'in wp-admin by %s', 'kico' ), wp_get_current_user()->user_login );
		$text = sprintf(
			/* translators: 1: site name, 2: Kico address, 3: Kico account, 4: where. */
			__( '%1$s was disconnected from Kico (%2$s, %3$s) %4$s. That key no longer works.', 'kico' ),
			$site,
			$key['kico_origin'] ?? '',
			self::who( isset( $key['kico_account'] ) && is_array( $key['kico_account'] ) ? $key['kico_account'] : array() ),
			$by
		);
		self::send( __( 'Disconnected from Kico', 'kico' ), $text . self::footer() );
	}
}
