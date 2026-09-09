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
		// ⛔⛔ ON `init`, NOT `plugins_loaded`. WordPress 6.7 raises *"Translation loading …
		// triggered too early"* for any `__()` before `init`, and a settings page is
		// translated labels by construction. Measured on WordPress 7.1.
		add_action( 'init', array( $this, 'declare_page' ), 5 );
		add_action( 'admin_post_zinn_offload_claim', array( $this, 'handle_claim' ) );
		add_action( 'admin_post_zinn_offload_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Declare the screen through the shared Zinn settings framework.
	 *
	 * ⛔⛔ **THIS PLUGIN HAD NO CUSTOMER-CHANGEABLE SETTING AT ALL.** Its screen showed a
	 * pairing form, five read-only rows and a Disconnect button; `rewrite_urls` and
	 * `delete_local` were written by our panel and a site owner could not see them, let alone
	 * change them. The owner's *"customisable options … so they can properly contorl it"* is
	 * exactly this plugin's gap, and every field below is read by the code that does the work.
	 *
	 * @return void
	 */
	public function declare_page(): void {
		Zinn_Offload_Admin_UI::register(
			array(
				'title'      => __( 'Media Offload', 'zinn-offload' ),
				'option'     => ZINN_OFFLOAD_OPTION,
				'position'   => 20,
				'connection' => array( __CLASS__, 'status' ),
				'tabs'       => array(
					'connection' => array(
						'title'  => __( 'Connection', 'zinn-offload' ),
						'fields' => array(),
					),
					'offloading' => array(
						'title'  => __( 'What gets moved', 'zinn-offload' ),
						'fields' => array( __CLASS__, 'offload_fields' ),
					),
					'serving'    => array(
						'title'  => __( 'How media is served', 'zinn-offload' ),
						'fields' => array( __CLASS__, 'serving_fields' ),
					),
				),
				'screens'    => array(
					array(
						'id'     => 'connection',
						'title'  => __( 'Connection', 'zinn-offload' ),
						'render' => array( $this, 'render_connection_tab' ),
					),
				),
				'actions'    => array(
					array(
						'id'       => 'zinn_offload_sweep_now',
						'label'    => __( 'Move a batch now', 'zinn-offload' ),
						'callback' => array( __CLASS__, 'sweep_now' ),
					),
				),
			)
		);
	}

	/**
	 * What is moved off this server, and what is left alone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function offload_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'What gets moved', 'zinn-offload' ),
				'description' => __( 'Media already moved is never affected by a change here — its address does not change.', 'zinn-offload' ),
			),
			array(
				'key'            => 'offload_new_uploads',
				'type'           => 'toggle',
				'label'          => __( 'New uploads', 'zinn-offload' ),
				'checkbox_label' => __( 'Move new uploads to Zinn® storage as they arrive', 'zinn-offload' ),
				'default'        => true,
			),
			array(
				'key'            => 'sweep_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Existing media', 'zinn-offload' ),
				'checkbox_label' => __( 'Keep moving the media library that is already here', 'zinn-offload' ),
				'description'    => __( 'A few files an hour, in the background, picking up where it left off. Turning this off pauses it; nothing already moved comes back.', 'zinn-offload' ),
				'default'        => true,
			),
			array(
				'key'         => 'sweep_batch',
				'type'        => 'number',
				'label'       => __( 'Files per hourly pass', 'zinn-offload' ),
				'description' => __( 'Each attachment is several files and every one is a full upload. Raise this on a fast host with a generous execution limit; lower it if passes are being cut short.', 'zinn-offload' ),
				'min'         => 1,
				'max'         => 100,
				'default'     => 10,
				'show_if'     => array( 'sweep_enabled' => true ),
			),
			array(
				'key'         => 'min_size_kb',
				'type'        => 'number',
				'label'       => __( 'Leave files smaller than', 'zinn-offload' ),
				'description' => __( 'Kilobytes. A tiny icon costs more in the extra connection than it saves in disk, so there is rarely a reason to move one. Set to 0 to move everything.', 'zinn-offload' ),
				'min'         => 0,
				'max'         => 10240,
				'default'     => 0,
			),
			array(
				'key'         => 'exclude_mime',
				'type'        => 'multiselect',
				'label'       => __( 'Never move these types', 'zinn-offload' ),
				'description' => __( 'Useful when a plugin reads a file straight off the disk — a font, a PDF a form builder stamps, an audio file a player seeks inside.', 'zinn-offload' ),
				'choices'     => array( __CLASS__, 'mime_choices' ),
				'default'     => array(),
			),
		);
	}

	/**
	 * How media reaches a visitor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function serving_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'How media is served', 'zinn-offload' ),
				'description' => __( 'These two are the ones to be careful with — read each description before changing it.', 'zinn-offload' ),
			),
			array(
				'key'            => 'rewrite_urls',
				'type'           => 'toggle',
				'label'          => __( 'Serve from Zinn® storage', 'zinn-offload' ),
				'checkbox_label' => __( 'Point image and file addresses at Zinn® storage', 'zinn-offload' ),
				'description'    => __( 'Off, files are uploaded but still served from this server — which is the safe state while you check everything arrived. On, visitors are served from storage and this server stops carrying the traffic.', 'zinn-offload' ),
				'default'        => false,
			),
			array(
				'key'            => 'delete_local',
				'type'           => 'toggle',
				'label'          => __( 'Free the disk space', 'zinn-offload' ),
				'checkbox_label' => __( 'Delete the local copy once every size has uploaded', 'zinn-offload' ),
				'description'    => __( 'This is the point of offloading, and it is also the irreversible half. Leave it off until “Serve from Zinn® storage” is on and you have checked your site — a local copy is the only thing that saves you if something was missed.', 'zinn-offload' ),
				'default'        => false,
				'show_if'        => array( 'rewrite_urls' => true ),
			),
			array(
				'type'  => 'notice',
				'kind'  => 'warning',
				'label' => __( 'Deleting local copies cannot be undone from this screen. Take a backup first — the Zinn® Connector plugin can do that for you.', 'zinn-offload' ),
			),
		);
	}

	/**
	 * The MIME types this site actually has, as a choice list.
	 *
	 * ⛔ Derived from the media library rather than from WordPress's full allowed list: a
	 * screen offering forty types a site has never uploaded is a screen nobody reads to the
	 * bottom of.
	 *
	 * @return array<string, string>
	 */
	public static function mime_choices(): array {
		global $wpdb;
		$types = $wpdb->get_col(
			"SELECT DISTINCT post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type <> '' ORDER BY post_mime_type"
		);
		$out   = array();
		foreach ( (array) $types as $type ) {
			$out[ (string) $type ] = (string) $type;
		}
		return $out;
	}

	/**
	 * Move one batch on demand.
	 *
	 * @return array<string, string>
	 */
	public static function sweep_now(): array {
		if ( ! zinn_offload_is_connected() ) {
			return array(
				'kind'    => 'error',
				'message' => __( 'This site is not connected to Zinn® storage yet, so there is nowhere to move media to.', 'zinn-offload' ),
			);
		}
		$moved = ( new Zinn_Offload_Sweeper( new Zinn_Offload_Client() ) )->run();
		return array(
			'kind'    => 'success',
			'message' => sprintf(
				/* translators: %d: how many attachments were moved. */
				_n( '%d attachment moved.', '%d attachments moved.', $moved, 'zinn-offload' ),
				$moved
			),
		);
	}

	/**
	 * Whether this site is connected, and how far through its library it is.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings = zinn_offload_settings();

		if ( ! zinn_offload_is_connected() ) {
			return array(
				'state'   => 'disconnected',
				'summary' => __( 'This site is not connected to Zinn® storage.', 'zinn-offload' ),
				'reason'  => __( 'Nothing is wrong — it has simply not been paired yet. Media stays on this server until it is.', 'zinn-offload' ),
			);
		}

		$remaining = Zinn_Offload_Sweeper::remaining();
		$total     = Zinn_Offload_Sweeper::total();

		if ( '' !== (string) $settings['last_error'] ) {
			return array(
				'state'      => 'degraded',
				'summary'    => __( 'Connected, but the last upload failed.', 'zinn-offload' ),
				'reason'     => (string) $settings['last_error'],
				'action'     => array(
					'label'  => __( 'Try a batch now', 'zinn-offload' ),
					'action' => 'zinn_offload_sweep_now',
				),
				'details'    => self::detail_rows( $settings, $remaining, $total ),
				'checked_at' => (int) $settings['last_checked'],
			);
		}

		return array(
			'state'      => 'connected',
			'summary'    => 0 === $remaining
				? __( 'Connected. Every attachment has been moved.', 'zinn-offload' )
				: sprintf(
					/* translators: 1: attachments still local, 2: total attachments. */
					__( 'Connected. %1$s of %2$s attachments still on this server.', 'zinn-offload' ),
					number_format_i18n( $remaining ),
					number_format_i18n( $total )
				),
			'details'    => self::detail_rows( $settings, $remaining, $total ),
			'checked_at' => (int) $settings['last_checked'],
		);
	}

	/**
	 * The rows under the status headline.
	 *
	 * ⛔ "N of M", never a bare N. On its own a zero reads as "everything is offloaded" — and
	 * it is what a broken query, a renamed meta key and an empty media library all return.
	 * The total is the control that says which zero this is (§2.44).
	 *
	 * @param array<string, mixed> $settings  The stored settings.
	 * @param int                  $remaining Attachments not yet moved.
	 * @param int                  $total     Attachments in total.
	 * @return array<int, array<string, string>>
	 */
	private static function detail_rows( array $settings, int $remaining, int $total ): array {
		return array(
			array(
				'label' => __( 'Moved so far', 'zinn-offload' ),
				'value' => sprintf(
					/* translators: 1: number of files, 2: human-readable size. */
					__( '%1$s files, %2$s', 'zinn-offload' ),
					number_format_i18n( (int) $settings['objects'] ),
					size_format( (int) $settings['bytes'] )
				),
			),
			array(
				'label' => __( 'Still on this server', 'zinn-offload' ),
				'value' => sprintf(
					/* translators: 1: attachments not yet offloaded, 2: total attachments. */
					__( '%1$s of %2$s attachments', 'zinn-offload' ),
					number_format_i18n( $remaining ),
					number_format_i18n( $total )
				),
			),
			array(
				'label' => __( 'Served from', 'zinn-offload' ),
				'value' => $settings['rewrite_urls'] && '' !== (string) $settings['public_base_url']
					? (string) $settings['public_base_url']
					: __( 'this server', 'zinn-offload' ),
			),
		);
	}

	/**
	 * The Connection tab: pair the site, or unpair it.
	 *
	 * ⛔ The status card above this — drawn by the shared framework — has already said
	 * whether the site is connected, how far through its library it is and what went wrong
	 * if anything did. This tab is the ACTION, and it does not repeat the diagnosis. The
	 * five-row read-only table that used to live here is gone for exactly that reason: two
	 * descriptions of one state is one description and one thing that goes stale.
	 *
	 * @return void
	 */
	public function render_connection_tab(): void {
		$connected = zinn_offload_is_connected();
		$this->render_notice();
		?>
		<?php if ( ! $connected ) : ?>
			<h2><?php echo esc_html__( 'Connect this site', 'zinn-offload' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Open your Zinn Digital® dashboard, go to Storage → Media offload, and press “Pair a site”. Paste the code below.', 'zinn-offload' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'zinn_offload_claim' ); ?>
				<input type="hidden" name="action" value="zinn_offload_claim" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="zinn_offload_code"><?php echo esc_html__( 'Pairing code', 'zinn-offload' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="zinn_offload_code"
								name="zinn_offload_code"
								class="regular-text"
								autocomplete="off"
								spellcheck="false"
								required
							/>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Connect this site', 'zinn-offload' ) ); ?>
			</form>
		<?php else : ?>
			<h2><?php echo esc_html__( 'Disconnect this site', 'zinn-offload' ); ?></h2>
			<p>
				<?php echo esc_html__( 'New uploads will stay on this server. Media already moved keeps working — its address does not change.', 'zinn-offload' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'zinn_offload_disconnect' ); ?>
				<input type="hidden" name="action" value="zinn_offload_disconnect" />
				<?php submit_button( __( 'Disconnect this site', 'zinn-offload' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
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
		wp_safe_redirect( Zinn_Offload_Admin_UI::page_url( 'connection' ) );
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
