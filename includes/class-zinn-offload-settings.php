<?php
/**
 * The one screen this plugin adds — pair, see status, disconnect.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A deliberately small settings screen.
 *
 * ⛔⛔ **THERE IS NO BUCKET FIELD, NO ENDPOINT FIELD AND NO KEY FIELD, AND THAT IS THE
 * PRODUCT.** The owner's instruction was *"controlled from our app"*. Every offload setting —
 * which bucket, whether URLs are rewritten, whether local copies are deleted — is decided in
 * the Zinn dashboard and arrives with the pairing response. This screen shows what those
 * settings currently are and offers exactly two actions: pair, and disconnect.
 *
 * ⭐ Which is not merely tidier. A field here would be a second place a setting is authored,
 * and the two would disagree the first time somebody changed one — with the site's copy
 * winning silently, because it is the one the uploads read.
 */
class Zinn_Offload_Settings {

	/**
	 * The API client.
	 *
	 * @var Zinn_Offload_Client
	 */
	private Zinn_Offload_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Zinn_Offload_Client $client The API client.
	 */
	public function __construct( Zinn_Offload_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Attach the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_zinn_offload_claim', array( $this, 'handle_claim' ) );
		add_action( 'admin_post_zinn_offload_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Register the options page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Zinn® Media Offload', 'zinn-offload' ),
			__( 'Zinn® Media Offload', 'zinn-offload' ),
			'manage_options',
			'zinn-offload',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this site.', 'zinn-offload' ) );
		}
		$settings  = zinn_offload_settings();
		$connected = zinn_offload_is_connected();
		$remaining = $connected ? Zinn_Offload_Sweeper::remaining() : 0;
		$total     = $connected ? Zinn_Offload_Sweeper::total() : 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Zinn® Media Offload', 'zinn-offload' ); ?></h1>

			<?php $this->render_notice(); ?>

			<?php if ( ! $connected ) : ?>
				<p>
					<?php
					echo esc_html__(
						'Open your Zinn Digital® dashboard, turn on media offload for this site, and paste the pairing code it shows you. No access key is needed — this site never holds one.',
						'zinn-offload'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'zinn_offload_claim' ); ?>
					<input type="hidden" name="action" value="zinn_offload_claim" />
					<p>
						<label for="zinn-offload-code"><?php echo esc_html__( 'Pairing code', 'zinn-offload' ); ?></label><br />
						<input
							type="text"
							id="zinn-offload-code"
							name="zinn_offload_code"
							class="regular-text"
							autocomplete="off"
							spellcheck="false"
							required
						/>
					</p>
					<?php submit_button( __( 'Connect this site', 'zinn-offload' ) ); ?>
				</form>
			<?php else : ?>
				<table class="widefat striped" style="max-width:48rem">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Status', 'zinn-offload' ); ?></th>
							<td><?php echo esc_html__( 'Connected to Zinn Digital®', 'zinn-offload' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Media served from', 'zinn-offload' ); ?></th>
							<td>
								<?php
								echo $settings['rewrite_urls'] && '' !== (string) $settings['public_base_url']
									? esc_html( (string) $settings['public_base_url'] )
									: esc_html__( 'This server (offloaded copies are being kept, but not served yet)', 'zinn-offload' );
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Local copies', 'zinn-offload' ); ?></th>
							<td>
								<?php
								echo $settings['delete_local']
									? esc_html__( 'Removed once every size has uploaded', 'zinn-offload' )
									: esc_html__( 'Kept on this server', 'zinn-offload' );
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Offloaded so far', 'zinn-offload' ); ?></th>
							<td>
								<?php
								printf(
									/* translators: 1: number of files, 2: human-readable size. */
									esc_html__( '%1$s files, %2$s', 'zinn-offload' ),
									esc_html( number_format_i18n( (int) $settings['objects'] ) ),
									esc_html( size_format( (int) $settings['bytes'] ) )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Still on this server', 'zinn-offload' ); ?></th>
							<td>
								<?php
								// ⛔ Shown as "N of M", never as a bare N. On its own a zero
								// here reads as "everything is offloaded" — and it is exactly
								// what a broken query, a renamed meta key and an empty media
								// library all return. The total is the control that says
								// which zero this is.
								printf(
									/* translators: 1: attachments not yet offloaded, 2: total attachments. */
									esc_html__( '%1$s of %2$s attachments', 'zinn-offload' ),
									esc_html( number_format_i18n( $remaining ) ),
									esc_html( number_format_i18n( $total ) )
								);
								?>
								<p class="description">
									<?php
									echo esc_html__(
										'Older media moves in the background, a few files an hour, and picks up where it left off. Nothing is deleted from this server unless your dashboard says so.',
										'zinn-offload'
									);
									?>
								</p>
							</td>
						</tr>
						<?php if ( '' !== (string) $settings['last_error'] ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Last problem', 'zinn-offload' ); ?></th>
								<td><?php echo esc_html( (string) $settings['last_error'] ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem">
					<?php wp_nonce_field( 'zinn_offload_disconnect' ); ?>
					<input type="hidden" name="action" value="zinn_offload_disconnect" />
					<?php submit_button( __( 'Disconnect this site', 'zinn-offload' ), 'secondary' ); ?>
					<p class="description">
						<?php
						echo esc_html__(
							'New uploads will stay on this server. Media already moved keeps working — its address does not change.',
							'zinn-offload'
						);
						?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Redeem a pairing code.
	 *
	 * @return void
	 */
	public function handle_claim(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this site.', 'zinn-offload' ) );
		}
		check_admin_referer( 'zinn_offload_claim' );

		$code = isset( $_POST['zinn_offload_code'] )
			? sanitize_text_field( wp_unslash( $_POST['zinn_offload_code'] ) )
			: '';
		if ( '' === $code ) {
			$this->redirect_with( 'error', __( 'Paste the pairing code from your Zinn Digital® dashboard.', 'zinn-offload' ) );
		}

		$result = $this->client->claim( $code );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with( 'error', $result->get_error_message() );
		}
		$this->redirect_with(
			'success',
			__( 'Connected. New uploads will be stored with Zinn Digital® from now on, and your existing media will move in the background.', 'zinn-offload' )
		);
	}

	/**
	 * Forget the token.
	 *
	 * ⛔⛔ **This clears the TOKEN and keeps `prefix` and `public_base_url`.** Media already
	 * offloaded is addressed by URL inside this site's published posts; forgetting where it
	 * lives would break every one of those images. The administrator asked to stop uploading,
	 * not to blank their archive.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this site.', 'zinn-offload' ) );
		}
		check_admin_referer( 'zinn_offload_disconnect' );

		$settings               = zinn_offload_settings();
		$settings['token']      = '';
		$settings['last_error'] = '';
		update_option( ZINN_OFFLOAD_OPTION, $settings, false );
		wp_clear_scheduled_hook( 'zinn_offload_sweep' );

		$this->redirect_with(
			'success',
			__( 'Disconnected. Media already moved keeps working; new uploads stay on this server.', 'zinn-offload' )
		);
	}

	/**
	 * Bounce back to the screen carrying a message.
	 *
	 * ⛔ The message goes in a transient keyed to the user, not in the query string. A
	 * message in the URL is reflected onto the page, which is a self-XSS vector even after
	 * escaping, and it survives being copied into a support ticket.
	 *
	 * @param string $kind    Either `success` or `error`.
	 * @param string $message What to show.
	 * @return never
	 */
	private function redirect_with( string $kind, string $message ) {
		set_transient(
			'zinn_offload_notice_' . get_current_user_id(),
			array(
				'kind'    => $kind,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=zinn-offload' ) );
		exit;
	}

	/**
	 * Show and consume a one-shot notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key    = 'zinn_offload_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === ( $notice['kind'] ?? '' ) ? 'error' : 'success',
			esc_html( (string) $notice['message'] )
		);
	}
}
