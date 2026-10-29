<?php
/**
 * Settings > Kico: what a connected Kico can do, connecting the site (the
 * admin confirms in Kico, with a match code shown on both sides), who is
 * connected and what Kico did, and disconnecting. On multisite only super
 * admins see it, since Kico's tools reach the whole network.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Admin {

	const KICO_URL_OPTION = 'kico_url';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_post_kico_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_kico_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_kico_cancel_pairing', array( __CLASS__, 'cancel_pairing' ) );
		add_action( 'admin_post_kico_clear_mail', array( __CLASS__, 'clear_mail' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KICO_WP_FILE ), array( __CLASS__, 'links' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( KICO_WP_FILE ), array( __CLASS__, 'links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function kico_url() {
		$url = (string) get_option( self::KICO_URL_OPTION, '' );
		if ( defined( 'KICO_URL' ) ) {
			$url = (string) KICO_URL;
		}
		// Kico Cloud: the app moved from kico.dev to app.kico.dev; kico.dev still forwards to it.
		return untrailingslashit( '' !== $url ? $url : 'https://app.kico.dev' );
	}

	/** The menu's capability: super admins only on multisite. */
	private static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private static function allowed() {
		return Kico_Auth::is_admin_user( get_current_user_id() );
	}

	/** Settings > Kico's address. */
	public static function page_url( $query = '' ) {
		return admin_url( 'options-general.php?page=kico' ) . $query;
	}

	/** Plugins: "Connect to Kico" until the site is connected, then "Settings". */
	public static function links( $links ) {
		if ( self::allowed() ) {
			$label = Kico_Keys::all() ? __( 'Settings', 'kico' ) : __( 'Connect to Kico', 'kico' );
			array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html( $label ) . '</a>' );
		}
		return $links;
	}

	/** Plugins: under the description, whether Kico is connected and when it last called. */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( KICO_WP_FILE ) !== $file || ! self::allowed() ) {
			return $meta;
		}
		$keys = Kico_Keys::all();
		if ( ! $keys ) {
			$meta[] = esc_html__( 'Not connected', 'kico' );
			return $meta;
		}
		$last = 0;
		foreach ( $keys as $k ) {
			$last = max( $last, (int) ( $k['last_used_at'] ?? 0 ) );
		}
		$meta[] = '<a href="' . esc_url( self::page_url( '#kico-audit' ) ) . '">' . esc_html(
			$last
				/* translators: %s: a time span, like "3 minutes". */
				? sprintf( __( 'Connected, last activity %s ago', 'kico' ), human_time_diff( $last ) )
				: __( 'Connected', 'kico' )
		) . '</a>';
		return $meta;
	}

	public static function menu() {
		add_options_page( 'Kico', 'Kico', self::capability(), 'kico', array( __CLASS__, 'page' ) );
	}

	/** Settings > Privacy > Policy Guide: what to tell visitors. */
	public static function privacy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Kico for WordPress',
			wp_kses_post(
				wpautop(
					__( "This site is managed with Kico (https://kico.dev), an AI assistant service run by Unifying Systems LLC. When an administrator connects the site, Kico's assistant and agents can read and change the site's content, settings, files and database, which may include visitors' personal data such as comments, orders, form entries and user accounts.\n\nBefore Kico changes something, a copy of what it changes (a snapshot) is sent to Kico and kept there encrypted, so the change can be undone: by default for 90 days while the site is connected, 30 days after it is disconnected, and the log of what Kico did for a year. The site keeps its own log of every call. Visitors' browsing is not sent to Kico, except pages shown in a Kico project's preview.", 'kico' )
				)
			)
		);
	}

	/** The page's rules that matter for what Kico can do here. */
	private static function file_constants() {
		$out = array();
		foreach ( array( 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ) as $c ) {
			if ( defined( $c ) && constant( $c ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** Settings > Kico's styles and script, on that page only: no fonts or files from anywhere else. */
	public static function assets( $hook ) {
		if ( 'settings_page_kico' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'kico-admin', plugins_url( 'assets/kico-admin.css', KICO_WP_FILE ), array(), KICO_WP_VERSION );
		wp_enqueue_script( 'kico-admin', plugins_url( 'assets/kico-admin.js', KICO_WP_FILE ), array(), KICO_WP_VERSION, true );
		wp_localize_script(
			'kico-admin',
			'kicoAdmin',
			array(
				/* translators: %s: minutes and seconds left, like 9:41. */
				'expiresIn' => __( 'Expires in %s', 'kico' ),
				'expired'   => __( 'Expired. Click Connect to Kico to start again.', 'kico' ),
			)
		);
	}

	private static function asset( $file ) {
		return plugins_url( 'assets/' . $file, KICO_WP_FILE );
	}

	/** A time as "3 minutes ago", with the exact time for a hover and for screen readers. */
	private static function ago( $ts ) {
		$ts = (int) $ts;
		if ( ! $ts ) {
			return '<span class="kico-muted">' . esc_html__( 'Never', 'kico' ) . '</span>';
		}
		$exact = wp_date( 'Y-m-d H:i', $ts );
		$text  = time() - $ts < MINUTE_IN_SECONDS
			? __( 'Just now', 'kico' )
			/* translators: %s: a time span, like "3 minutes". */
			: sprintf( __( '%s ago', 'kico' ), human_time_diff( $ts ) );
		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '" title="' . esc_attr( $exact ) . '">' . esc_html( $text ) . '</time>';
	}

	/** A status: a dot and a word in its colour. */
	private static function status( $tone, $word ) {
		return '<span class="kico-status kico-status--' . esc_attr( $tone ) . '"><span class="kico-dot" aria-hidden="true"></span>' . esc_html( $word ) . '</span>';
	}

	/**
	 * The page's header: the logo, where the site stands with Kico and, before
	 * it is connected, what the plugin is for.
	 *
	 * @param string $state new, pairing, connected or denied.
	 * @param array  $keys  Connections.
	 */
	private static function band( $state, array $keys = array() ) {
		$last    = 0;
		$origins = array();
		foreach ( $keys as $k ) {
			$last = max( $last, (int) ( $k['last_used_at'] ?? 0 ) );
			if ( ! empty( $k['kico_origin'] ) ) {
				$origins[ (string) wp_parse_url( $k['kico_origin'], PHP_URL_HOST ) ] = true;
			}
		}
		?>
		<header class="kico-band kico-band--<?php echo esc_attr( $state ); ?>">
			<div class="kico-band__main">
				<h1 class="kico-band__title">
					<img src="<?php echo esc_url( self::asset( 'kico-logo-on-dark.svg' ) ); ?>" alt="Kico" width="101" height="24">
					<span class="kico-band__product"><?php echo esc_html_x( 'for WordPress', 'after the Kico logo: the plugin\'s name', 'kico' ); ?></span>
					<span class="kico-band__version kico-mono"><?php echo esc_html( KICO_WP_VERSION ); ?></span>
				</h1>
				<?php if ( 'denied' !== $state ) : ?>
					<p class="kico-band__status">
						<?php
						if ( 'connected' === $state ) {
							echo self::status( 'ok', __( 'Connected', 'kico' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status().
							if ( $origins ) {
								echo ' <span class="kico-band__meta kico-mono">' . esc_html( implode( ', ', array_keys( $origins ) ) ) . '</span>';
							}
							echo ' <span class="kico-band__meta">';
							/* translators: %s: a time, like "3 minutes ago". */
							echo wp_kses( sprintf( __( 'Last activity %s', 'kico' ), self::ago( $last ) ), array( 'time' => array( 'datetime' => true, 'title' => true ), 'span' => array( 'class' => true ) ) );
							echo '</span>';
						} elseif ( 'pairing' === $state ) {
							echo self::status( 'wait', __( 'Waiting for Kico', 'kico' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							echo self::status( 'off', __( 'Not connected', 'kico' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</p>
				<?php endif; ?>
				<?php if ( 'new' === $state ) : ?>
					<p class="kico-band__lede"><?php esc_html_e( 'Connects this site to Kico, so your Kico assistant and agents can manage it. Every change is snapshotted and logged, and can be rolled back.', 'kico' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( 'new' === $state ) : ?>
				<img class="kico-band__mascot" src="<?php echo esc_url( self::asset( 'kico-mascot.svg' ) ); ?>" alt="" width="428" height="670">
			<?php endif; ?>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	public static function page() {
		if ( ! self::allowed() ) {
			echo '<div class="wrap kico">';
			self::band( 'denied' );
			echo '<div class="kico-panel"><p>' . esc_html( sprintf( /* translators: %s: role. */ __( 'Only a %s connects this site to Kico.', 'kico' ), Kico_Auth::admin_role() ) ) . '</p></div></div>';
			return;
		}
		$keys     = Kico_Keys::all();
		$pairings = Kico_Keys::pairings();
		$failures = Kico_Mail::failures();
		$done     = isset( $_GET['kico'] ) ? sanitize_key( wp_unslash( $_GET['kico'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$state    = $keys ? 'connected' : ( $pairings ? 'pairing' : 'new' );
		?>
		<div class="wrap kico" data-kico-state="<?php echo esc_attr( $state . ':' . count( $keys ) . ':' . count( $pairings ) ); ?>">
			<?php self::band( $state, $keys ); ?>
			<?php if ( 'disconnected' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Disconnected. Kico can no longer reach this site with that key.', 'kico' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'auto-on' === $done || 'auto-off' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( 'auto-on' === $done ? __( 'Auto-updates of Kico for WordPress are on.', 'kico' ) : __( 'Auto-updates of Kico for WordPress are off.', 'kico' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $failures ) : ?>
				<div class="notice notice-warning kico-mail-failures">
					<p><strong><?php esc_html_e( 'Some Kico notification emails could not be sent.', 'kico' ); ?></strong> <?php esc_html_e( 'Administrators may not know about these changes. Check the site\'s mail setup (an SMTP plugin).', 'kico' ); ?></p>
					<ul>
					<?php foreach ( array_reverse( $failures ) as $f ) : ?>
						<li><span class="kico-mono"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $f['at'] ) ); ?></span> <?php echo esc_html( $f['to'] . ' · ' . $f['subject'] ); ?> <span class="kico-muted"><?php echo esc_html( $f['error'] ); ?></span></li>
					<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kico_clear_mail' ); ?>
						<input type="hidden" name="action" value="kico_clear_mail">
						<p><button class="button"><?php esc_html_e( 'Dismiss', 'kico' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>
			<?php
			if ( 'connected' === $state ) {
				self::pairing_section( $pairings );
				self::connections_section( $keys );
				self::audit_section();
				self::connect_section( $keys, $pairings );
				self::updates_section();
				self::about_section();
			} else {
				self::pairing_section( $pairings );
				self::connect_section( $keys, $pairings );
				self::updates_section();
				self::about_section();
				self::audit_section();
			}
			?>
		</div>
		<?php
	}

	/** Pairings waiting for Kico: the match code, large, with its countdown. */
	private static function pairing_section( array $pairings ) {
		foreach ( $pairings as $p ) :
			$u  = get_userdata( (int) $p['user_id'] );
			$id = 'kico-pairing-' . sanitize_html_class( $p['match'] );
			?>
			<section class="kico-panel kico-pairing" aria-labelledby="<?php echo esc_attr( $id ); ?>">
				<div class="kico-pairing__code">
					<h2 id="<?php echo esc_attr( $id ); ?>" class="kico-h2"><?php esc_html_e( 'Waiting for Kico', 'kico' ); ?></h2>
					<p class="kico-label"><?php esc_html_e( 'Match code', 'kico' ); ?></p>
					<p class="kico-code kico-mono"><?php echo esc_html( $p['match'] ); ?></p>
					<p class="kico-countdown kico-mono" role="timer" data-kico-expires="<?php echo esc_attr( (string) (int) $p['expires'] ); ?>" data-kico-now="<?php echo esc_attr( (string) time() ); ?>">
						<?php
						/* translators: %s: a time of day. */
						echo esc_html( sprintf( __( 'Expires at %s', 'kico' ), wp_date( 'H:i', (int) $p['expires'] ) ) );
						?>
					</p>
				</div>
				<div class="kico-pairing__help">
					<p><?php esc_html_e( 'Kico shows the same match code on its confirm page. Confirm there only if they match; cancel a pairing you did not start.', 'kico' ); ?></p>
					<dl class="kico-facts">
						<dt><?php esc_html_e( 'Started by', 'kico' ); ?></dt>
						<dd><?php echo esc_html( $u ? $u->user_login : '#' . $p['user_id'] ); ?></dd>
					</dl>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kico_cancel_pairing' ); ?>
						<input type="hidden" name="action" value="kico_cancel_pairing">
						<input type="hidden" name="match" value="<?php echo esc_attr( $p['match'] ); ?>">
						<button class="button"><?php esc_html_e( 'Cancel', 'kico' ); ?></button>
					</form>
				</div>
			</section>
			<?php
		endforeach;
	}

	/** Who is connected, as whom, since when, and Disconnect. */
	private static function connections_section( array $keys ) {
		?>
		<section class="kico-section" aria-labelledby="kico-connections">
			<h2 id="kico-connections" class="kico-h2"><?php esc_html_e( 'Connections', 'kico' ); ?></h2>
			<table class="kico-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Kico account', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Kico', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Acts as', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Connected', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Last used', 'kico' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'kico' ); ?></span></th></tr></thead>
				<tbody>
				<?php
				foreach ( $keys as $keyid => $k ) :
					$u       = get_userdata( (int) $k['user_id'] );
					$account = isset( $k['kico_account'] ) && is_array( $k['kico_account'] ) ? $k['kico_account'] : array();
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Kico account', 'kico' ); ?>">
							<span class="kico-strong"><?php echo esc_html( '' !== ( $account['name'] ?? '' ) ? $account['name'] : ( $account['email'] ?? '' ) ); ?></span>
							<?php if ( ! empty( $account['name'] ) && ! empty( $account['email'] ) ) : ?>
								<span class="kico-sub"><?php echo esc_html( $account['email'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $account['org'] ) ) : ?>
								<span class="kico-sub"><?php echo esc_html( $account['org'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Kico', 'kico' ); ?>" class="kico-mono"><?php echo esc_html( $k['kico_origin'] ?? '' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Acts as', 'kico' ); ?>"><?php echo esc_html( $u ? $u->user_login : '#' . $k['user_id'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Connected', 'kico' ); ?>" class="kico-mono"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $k['created_at'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Last used', 'kico' ); ?>"><?php echo self::ago( $k['last_used_at'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ago(). ?></td>
						<td class="kico-table__action">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'kico_disconnect' ); ?>
								<input type="hidden" name="action" value="kico_disconnect">
								<input type="hidden" name="keyid" value="<?php echo esc_attr( $keyid ); ?>">
								<button class="button kico-button-danger" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Kico from this site?', 'kico' ) ); ?>')"><?php esc_html_e( 'Disconnect', 'kico' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/** Connecting: the Kico address and the one Connect button. */
	private static function connect_section( array $keys, array $pairings ) {
		$primary = ! $keys && ! $pairings;
		$form    = function () use ( $primary ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kico-connect">
				<?php wp_nonce_field( 'kico_connect' ); ?>
				<input type="hidden" name="action" value="kico_connect">
				<p class="kico-field">
					<label for="kico_url"><?php esc_html_e( 'Kico address', 'kico' ); ?></label>
					<input id="kico_url" name="kico_url" type="url" class="regular-text kico-mono" value="<?php echo esc_attr( self::kico_url() ); ?>" <?php disabled( defined( 'KICO_URL' ) ); ?> aria-describedby="kico-connect-help">
				</p>
				<p><button class="button <?php echo $primary ? 'button-primary button-hero' : ''; ?>"><?php esc_html_e( 'Connect to Kico', 'kico' ); ?></button></p>
				<p class="description" id="kico-connect-help">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: user login. */
							__( 'You confirm the connection in Kico, after checking that it shows the match code shown here. Kico creates a key pair and keeps the private key; this site stores only the public key, bound to you (%s). The site\'s administrators get an email.', 'kico' ),
							wp_get_current_user()->user_login
						)
					);
					?>
				</p>
			</form>
			<?php
		};
		if ( $keys ) :
			?>
			<details class="kico-panel kico-disclosure">
				<summary><h2 class="kico-h2"><?php esc_html_e( 'Connect another Kico account', 'kico' ); ?></h2></summary>
				<?php $form(); ?>
			</details>
			<?php
			return;
		endif;
		?>
		<section class="kico-panel kico-start" aria-labelledby="kico-connect">
			<div class="kico-start__form">
				<h2 id="kico-connect" class="kico-h2"><?php echo $pairings ? esc_html__( 'Start again', 'kico' ) : esc_html__( 'Connect to Kico', 'kico' ); ?></h2>
				<?php $form(); ?>
			</div>
			<?php if ( ! $pairings ) : ?>
				<ol class="kico-steps">
					<li><?php esc_html_e( 'Click Connect to Kico. Kico opens in a new tab.', 'kico' ); ?></li>
					<li><?php esc_html_e( 'Kico and this page show a match code. Check that they are the same.', 'kico' ); ?></li>
					<li><?php esc_html_e( 'Confirm in Kico. Every administrator of this site gets an email.', 'kico' ); ?></li>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Every call Kico made, and whether the log's hash chain holds. */
	private static function audit_section() {
		$audit = Kico_Store::audit_list( 30 );
		?>
		<section class="kico-section" aria-labelledby="kico-audit">
			<div class="kico-section__head">
				<h2 id="kico-audit" class="kico-h2"><?php esc_html_e( 'Audit log', 'kico' ); ?></h2>
				<?php
				echo ! $audit['entries'] ? '' : ( $audit['chain_intact'] // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status().
					? self::status( 'ok', __( 'The log\'s hash chain is intact.', 'kico' ) )
					: '<strong>' . self::status( 'fail', __( 'The hash chain is broken: an entry was changed or removed.', 'kico' ) ) . '</strong>' );
				?>
			</div>
			<p class="description"><?php echo esc_html( __( 'Every call Kico made, newest first, kept here for 180 days.', 'kico' ) . ' ' . __( 'Kico keeps its own copy.', 'kico' ) ); ?></p>
			<?php if ( ! $audit['entries'] ) : ?>
				<p class="kico-empty"><?php esc_html_e( 'No calls yet. Every call Kico makes on this site appears here, with its arguments.', 'kico' ); ?></p>
			<?php else : ?>
				<table class="kico-table kico-table--audit">
					<thead><tr><th scope="col"><?php esc_html_e( 'When (UTC)', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Tool', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Snapshot', 'kico' ); ?></th><th scope="col"><?php esc_html_e( 'Arguments', 'kico' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $audit['entries'] as $e ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'When (UTC)', 'kico' ); ?>" class="kico-mono kico-nowrap"><?php echo esc_html( $e['created_at'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Tool', 'kico' ); ?>"><code><?php echo esc_html( $e['tool'] ); ?></code></td>
							<td data-label="<?php esc_attr_e( 'Result', 'kico' ); ?>"><?php echo $e['ok'] ? self::status( 'ok', __( 'ok', 'kico' ) ) : self::status( 'fail', __( 'error', 'kico' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php esc_attr_e( 'Snapshot', 'kico' ); ?>" class="kico-mono"><?php echo '' !== (string) $e['snapshot'] ? esc_html( $e['snapshot'] ) : '<span class="kico-muted" aria-label="' . esc_attr__( 'None', 'kico' ) . '">&ndash;</span>'; ?></td>
							<td data-label="<?php esc_attr_e( 'Arguments', 'kico' ); ?>" class="kico-args"><code><?php echo esc_html( mb_substr( $e['args'], 0, 300 ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Updates: WordPress's auto-updates of this plugin (on by default; the
	 * Plugins screen's toggle is the same setting), and what on this site
	 * stops them whatever the setting says.
	 */
	private static function updates_section() {
		$wanted  = Kico_Updates::auto_wanted();
		$blocked = Kico_Updates::auto_blocked();
		$may     = current_user_can( 'update_plugins' );
		?>
		<section class="kico-panel kico-about" id="kico-updates">
			<div class="kico-row">
				<h2 class="kico-h2 kico-row__label"><?php esc_html_e( 'Updates', 'kico' ); ?></h2>
				<div class="kico-row__body kico-prose">
					<p>
						<?php
						if ( ! $wanted ) {
							echo esc_html__( 'Auto-updates are off: a new version of Kico for WordPress waits until someone updates it, in Plugins or from Kico.', 'kico' );
						} elseif ( $blocked ) {
							echo esc_html__( 'Auto-updates are on here, but this site does not let WordPress update plugins on its own (below), so a new version waits until someone updates it, in Plugins or from Kico.', 'kico' );
						} else {
							echo esc_html__( 'Auto-updates are on: WordPress installs a new version of Kico for WordPress on its own, soon after Kico releases it.', 'kico' );
						}
						echo ' ' . esc_html__( 'Every update, also an automatic one, is installed only when its signature verifies with Kico\'s release key. The Plugins screen\'s auto-update link is the same setting.', 'kico' );
						?>
					</p>
					<?php if ( $blocked ) : ?>
						<p><strong><?php echo esc_html( $wanted ? __( 'What stops automatic updates on this site:', 'kico' ) : __( 'Even when on, these would stop automatic updates on this site:', 'kico' ) ); ?></strong></p>
						<ul class="kico-list">
							<?php foreach ( $blocked as $why ) : ?>
								<li><?php echo esc_html( $why ); ?></li>
							<?php endforeach; ?>
						</ul>
						<p><?php echo esc_html( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ? __( 'Update in Kico still works; Plugins offers no updates while DISALLOW_FILE_MODS is set.', 'kico' ) : __( 'Updating by hand, in Plugins or with Update in Kico, still works.', 'kico' ) ); ?></p>
					<?php endif; ?>
					<?php if ( $may ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'kico_auto_update' ); ?>
							<input type="hidden" name="action" value="kico_auto_update">
							<input type="hidden" name="auto" value="<?php echo $wanted ? '0' : '1'; ?>">
							<p><button class="button"><?php echo esc_html( $wanted ? __( 'Turn auto-updates off', 'kico' ) : __( 'Turn auto-updates on', 'kico' ) ); ?></button></p>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/** What a connected Kico can do, what keeps it in check, and how to switch it off. */
	private static function about_section() {
		$consts = self::file_constants();
		?>
		<section class="kico-panel kico-about">
			<div class="kico-row">
				<h2 class="kico-h2 kico-row__label"><?php esc_html_e( 'What a connected Kico can do', 'kico' ); ?></h2>
				<div class="kico-row__body kico-prose">
					<p><?php esc_html_e( 'Kico\'s assistant, the agents its user makes and the dev agents of their Kico projects manage this site through this plugin, as the administrator who connected it, and more than wp-admin allows:', 'kico' ); ?></p>
					<ul class="kico-list">
						<li><?php esc_html_e( 'run any PHP inside WordPress and any SQL on its database;', 'kico' ); ?></li>
						<li><?php esc_html_e( 'read and change every file PHP may, including wp-config.php with the database password and the secret keys;', 'kico' ); ?></li>
						<li><?php esc_html_e( 'create, change and delete users, administrators too, and content, media, menus, options, plugins and themes;', 'kico' ); ?></li>
						<li>
							<?php
							echo esc_html(
								$consts
									? sprintf( /* translators: %s: constant names. */ __( 'this site sets %s, but Kico still installs and edits plugins, themes and files: those settings switch off wp-admin\'s editors and installers, not PHP;', 'kico' ), implode( ' and ', $consts ) )
									: __( 'DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS would not stop it: they switch off wp-admin\'s editors and installers, not PHP;', 'kico' )
							);
							?>
						</li>
						<?php if ( is_multisite() ) : ?>
							<li><?php esc_html_e( 'on this network, reach every site of it (the database and files are shared), which is why only super admins connect it.', 'kico' ); ?></li>
						<?php endif; ?>
					</ul>
					<p><?php esc_html_e( 'What keeps it in check: the key, which only Kico holds; Kico\'s approvals (writes that an event or an incoming mail started wait for its user; what the user asked for, or a schedule they made, runs at once); the snapshot of every change, which leaves the site and is kept encrypted in Kico (90 days while connected, 30 days after disconnecting, the log of calls a year), so any change can be rolled back from Kico; and the log of every call here, below. Every administrator gets an email when Kico is connected, disconnected or gets a new key.', 'kico' ); ?></p>
					<p><?php esc_html_e( 'To revoke: Disconnect below or in Kico (it takes effect at once), add define( \'KICO_DISABLED\', true ); to wp-config.php, or rename the plugin\'s folder.', 'kico' ); ?></p>
				</div>
			</div>
			<div class="kico-row">
				<h2 class="kico-h2 kico-row__label"><?php esc_html_e( 'Off switch', 'kico' ); ?></h2>
				<div class="kico-row__body kico-prose">
					<p><?php esc_html_e( 'Add define( \'KICO_DISABLED\', true ); to wp-config.php to turn the plugin off completely, or rename its folder over FTP.', 'kico' ); ?></p>
				</div>
			</div>
			<div class="kico-row">
				<h2 class="kico-h2 kico-row__label"><?php esc_html_e( 'Privacy', 'kico' ); ?></h2>
				<div class="kico-row__body kico-prose">
					<p>
						<?php
						printf(
							/* translators: %s: link to Settings > Privacy > Policy Guide. */
							esc_html__( 'Kico suggests a paragraph for this site\'s privacy policy, in %s.', 'kico' ),
							'<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'Settings > Privacy > Policy Guide', 'kico' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Starts a pairing: a one-time code, and a page with its match code that
	 * opens Kico in a new tab with the code (in a POST, never in the address).
	 * This tab then shows Settings > Kico with the same match code.
	 */
	public static function connect() {
		if ( ! self::allowed() ) {
			wp_die( esc_html( sprintf( /* translators: %s: role. */ __( 'Only a %s connects Kico.', 'kico' ), Kico_Auth::admin_role() ) ) );
		}
		check_admin_referer( 'kico_connect' );
		if ( ! defined( 'KICO_URL' ) && isset( $_POST['kico_url'] ) ) {
			update_option( self::KICO_URL_OPTION, esc_url_raw( wp_unslash( $_POST['kico_url'] ) ), false );
		}
		$code   = Kico_Keys::new_pair_code( get_current_user_id() );
		$match  = Kico_Keys::match_code( $code );
		$info   = Kico_Tools::site_info();
		$user   = wp_get_current_user();
		$fields = array(
			'code'               => $code,
			'site'               => $info['site'],
			'name'               => $info['name'],
			'mcp'                => $info['mcp_url'],
			'pair'               => rest_url( Kico_Auth::NS . '/pair' ),
			'wp_version'         => $info['wp_version'],
			'plugin_version'     => KICO_WP_VERSION,
			'admin'              => $user->user_login,
			'admin_name'         => $user->display_name,
			'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? '1' : '',
			'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ? '1' : '',
			// Kico then sends the pair request enveloped, past firewalls.
			'transport'          => implode( ',', Kico_Transport::encodings() ),
		);
		$action = self::kico_url() . '/connect/wordpress';
		$back   = admin_url( 'options-general.php?page=kico' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		$css = add_query_arg( 'ver', KICO_WP_VERSION, self::asset( 'kico-admin.css' ) );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Connect to Kico', 'kico' ) . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core attributes.
		echo '<link rel="stylesheet" href="' . esc_url( $css ) . '"></head>'; // phpcs:ignore WordPress.WP.EnqueuedResources -- a page of its own, outside wp-admin.
		echo '<body class="kico-handoff"><main class="kico kico-handoff__main">';
		echo '<header class="kico-band kico-band--pairing"><div class="kico-band__main"><p class="kico-band__title"><img src="' . esc_url( self::asset( 'kico-logo-on-dark.svg' ) ) . '" alt="Kico" width="101" height="24"> <span class="kico-band__product">' . esc_html_x( 'for WordPress', 'after the Kico logo: the plugin\'s name', 'kico' ) . '</span></p></div></header>';
		echo '<div class="kico-panel kico-handoff__panel">';
		echo '<h1 class="kico-h1">' . esc_html__( 'Connect to Kico', 'kico' ) . '</h1>';
		echo '<p class="kico-label">' . esc_html__( 'Match code', 'kico' ) . '</p>';
		echo '<p class="kico-code kico-mono">' . esc_html( $match ) . '</p>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: Kico address. */ __( 'Kico (%s) opens in a new tab and shows a match code. Connect there only if it is the same as this one. Settings > Kico shows it too while the pairing waits (10 minutes).', 'kico' ), self::kico_url() ) ) . '</p>';
		echo '<form id="kico" method="post" target="_blank" rel="noopener noreferrer" action="' . esc_url( $action ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		echo '<p class="kico-handoff__actions"><button class="kico-btn">' . esc_html__( 'Continue to Kico', 'kico' ) . '</button> <a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to Settings > Kico', 'kico' ) . '</a></p></form>';
		echo '</div></main>';
		echo '<script>document.getElementById("kico").addEventListener("submit",function(){setTimeout(function(){location.href=' . wp_json_encode( $back ) . '},500)})</script>';
		echo '</body></html>';
		exit;
	}

	public static function disconnect() {
		if ( ! self::allowed() ) {
			wp_die( esc_html( sprintf( /* translators: %s: role. */ __( 'Only a %s disconnects Kico.', 'kico' ), Kico_Auth::admin_role() ) ) );
		}
		check_admin_referer( 'kico_disconnect' );
		$keyid = isset( $_POST['keyid'] ) ? sanitize_text_field( wp_unslash( $_POST['keyid'] ) ) : '';
		$key   = Kico_Keys::get( $keyid );
		if ( $key && Kico_Keys::remove( $keyid ) ) {
			Kico_Store::audit(
				array(
					'op_id'  => wp_generate_uuid4(),
					'tool'   => 'disconnect',
					'args'   => array( 'keyid' => $keyid, 'from' => 'wp-admin' ),
					'ok'     => true,
					'keyid'  => $keyid,
					'result' => 'Disconnected in wp-admin.',
				)
			);
			Kico_Mail::disconnected( $key, 'wp-admin' );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=kico&kico=disconnected' ) );
		exit;
	}

	public static function cancel_pairing() {
		if ( ! self::allowed() ) {
			wp_die( '' );
		}
		check_admin_referer( 'kico_cancel_pairing' );
		Kico_Keys::cancel_pairing( isset( $_POST['match'] ) ? sanitize_text_field( wp_unslash( $_POST['match'] ) ) : '' );
		wp_safe_redirect( admin_url( 'options-general.php?page=kico' ) );
		exit;
	}

	public static function clear_mail() {
		if ( ! self::allowed() ) {
			wp_die( '' );
		}
		check_admin_referer( 'kico_clear_mail' );
		Kico_Mail::clear_failures();
		wp_safe_redirect( admin_url( 'options-general.php?page=kico' ) );
		exit;
	}
}
