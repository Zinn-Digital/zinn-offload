<?php
/**
 * Talking to Zinn Digital — claiming a pairing code, and asking for presigned upload URLs.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class in this plugin that makes an outbound request to Zinn Digital.
 *
 * ⛔⛔ **THIS CLASS NEVER TOUCHES THE BUCKET.** It asks Zinn for a signed URL and hands it
 * back; `Zinn_Offload_Uploader` is what PUTs bytes to that URL. The split is deliberate: it
 * means there is exactly one place a Zinn credential is used and exactly one place a bucket
 * is written to, and neither of them can quietly grow the other's job.
 */
class Zinn_Offload_Client {

	/**
	 * How long to wait on the Zinn API.
	 *
	 * ⛔ Short, because this runs INSIDE a media upload — the person who pressed "Upload" is
	 * watching a spinner. A long timeout here does not make an outage succeed; it makes the
	 * admin screen hang before failing anyway, and `Zinn_Offload_Uploader` is written so a
	 * failed offload leaves the file working locally.
	 */
	private const API_TIMEOUT = 8;

	/**
	 * How long to wait on the bucket itself.
	 *
	 * ⭐ Longer than {@see self::API_TIMEOUT} on purpose and the asymmetry is the point: the
	 * first is a small JSON round-trip, the second may be a 200 MB video going to another
	 * continent. One shared constant would either time out real uploads or make an API
	 * outage feel like a hang.
	 */
	private const UPLOAD_TIMEOUT = 300;

	/**
	 * The most keys to ask for in one presign request. Must not exceed the engine's cap.
	 *
	 * ⭐ The batch is why a bulk offload is viable at all: presigning is local cryptography
	 * at Zinn's end with no vendor round-trip, so a 20,000-attachment library costs 200
	 * requests instead of 20,000.
	 */
	public const PRESIGN_BATCH = 100;

	/**
	 * Redeem a pairing code and store the token it returns.
	 *
	 * @param string $code The code an administrator copied from the Zinn dashboard.
	 * @return true|WP_Error True on success, or the reason it failed.
	 */
	public function claim( string $code ) {
		$response = wp_remote_post(
			zinn_offload_api_base() . '/v1/media-offload/claim',
			array(
				'timeout' => self::API_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'code'           => $code,
						'plugin_version' => ZINN_OFFLOAD_VERSION,
						'site_url'       => home_url(),
					)
				),
			)
		);

		$body = $this->decode( $response, array( 201 ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// ⛔ Every value is sanitised even though it came from our own API. A plugin that
		// trusts a remote response because "it is ours" is one DNS hijack away from writing
		// an attacker's URL into every post on the site — and `public_base_url` is exactly
		// the value that would be written there.
		$settings                    = zinn_offload_settings();
		$settings['token']           = sanitize_text_field( (string) ( $body['token'] ?? '' ) );
		$settings['site_id']         = sanitize_text_field( (string) ( $body['site_id'] ?? '' ) );
		$settings['prefix']          = sanitize_text_field( (string) ( $body['prefix'] ?? '' ) );
		$settings['public_base_url'] = esc_url_raw( (string) ( $body['public_base_url'] ?? '' ) );
		$settings['rewrite_urls']    = ! empty( $body['rewrite_urls'] );
		$settings['delete_local']    = ! empty( $body['delete_local'] );
		$settings['last_error']      = '';
		$settings['last_checked']    = time();

		if ( '' === $settings['token'] ) {
			return new WP_Error(
				'zinn_offload_no_token',
				__( 'Zinn Digital® did not return a token. Nothing has been changed on this site.', 'zinn-offload' )
			);
		}

		update_option( ZINN_OFFLOAD_OPTION, $settings, false );
		return true;
	}

	/**
	 * Ask for presigned PUT URLs for a batch of object keys.
	 *
	 * @param string[] $keys Keys relative to this site's prefix.
	 * @return array<int, array<string, string>>|WP_Error One entry per key, or the failure.
	 */
	public function presign( array $keys ) {
		$keys = array_values( array_slice( $keys, 0, self::PRESIGN_BATCH ) );
		if ( array() === $keys ) {
			return array();
		}

		$response = wp_remote_post(
			zinn_offload_api_base() . '/v1/media-offload/uploads',
			array(
				'timeout' => self::API_TIMEOUT,
				'headers' => $this->auth_headers(),
				'body'    => (string) wp_json_encode(
					array(
						'keys'           => $keys,
						'plugin_version' => ZINN_OFFLOAD_VERSION,
					)
				),
			)
		);

		$body = $this->decode( $response, array( 200 ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return is_array( $body['uploads'] ?? null ) ? $body['uploads'] : array();
	}

	/**
	 * PUT one local file to a presigned URL.
	 *
	 * @param string $url       The presigned URL.
	 * @param string $file_path Absolute path to the local file.
	 * @return true|WP_Error True when the bucket accepted it.
	 */
	public function put_file( string $url, string $file_path ) {
		// ⛔ `WP_Filesystem` rather than `file_get_contents`: `WordPress.WP.AlternativeFunctions`
		// requires it, and it is the right call anyway — a site on FTP transport has no direct
		// filesystem access and would fail silently.
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $file_path ) ) {
			return new WP_Error(
				'zinn_offload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		$contents = $wp_filesystem->get_contents( $file_path );
		if ( false === $contents ) {
			return new WP_Error(
				'zinn_offload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		$type     = wp_check_filetype( $file_path );
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => array(
					'Content-Type' => $type['type'] ? $type['type'] : 'application/octet-stream',
				),
				'body'    => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new WP_Error(
				'zinn_offload_upload_failed',
				sprintf(
					/* translators: %d: HTTP status code returned by the storage service. */
					__( 'Storage refused the upload (HTTP %d). The file is still on this server.', 'zinn-offload' ),
					$code
				)
			);
		}
		return true;
	}

	/**
	 * Tell Zinn what this site has offloaded so far.
	 *
	 * ⛔⛔ **A DIAGNOSTIC, NOT A BILL.** These numbers come from this server and Zinn does
	 * not meter from them — the invoice is measured against the bucket. Sending a smaller
	 * number here changes what an administrator sees on the Zinn dashboard and nothing else.
	 *
	 * @param int $objects Files offloaded.
	 * @param int $bytes   Bytes offloaded.
	 * @return array<string, mixed>|WP_Error The current server-side settings, or the failure.
	 */
	public function report( int $objects, int $bytes ) {
		$response = wp_remote_post(
			zinn_offload_api_base() . '/v1/media-offload/report',
			array(
				'timeout' => self::API_TIMEOUT,
				'headers' => $this->auth_headers(),
				'body'    => (string) wp_json_encode(
					array(
						'objects_offloaded' => $objects,
						'bytes_offloaded'   => $bytes,
						'plugin_version'    => ZINN_OFFLOAD_VERSION,
					)
				),
			)
		);
		return $this->decode( $response, array( 200 ) );
	}

	/**
	 * The Authorization header carrying this site's plugin token.
	 *
	 * @return array<string, string> Headers for a Zinn API call.
	 */
	private function auth_headers(): array {
		$settings = zinn_offload_settings();
		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . (string) $settings['token'],
		);
	}

	/**
	 * Turn a `wp_remote_*` result into a decoded body or a WP_Error a human can act on.
	 *
	 * ⛔⛔ **A 401 CLEARS THE TOKEN, and that is the one status handled specially.** It means
	 * an administrator revoked this site from the Zinn dashboard. Holding onto a dead token
	 * would leave the plugin retrying on every upload for ever, and — worse — would leave the
	 * settings screen saying *Connected* while nothing worked. Clearing it makes the site
	 * fall back to local media and say so.
	 *
	 * @param array<string, mixed>|WP_Error $response The raw HTTP result.
	 * @param int[]                         $expected Status codes that mean success.
	 * @return array<string, mixed>|WP_Error
	 */
	private function decode( $response, array $expected ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( in_array( $code, $expected, true ) ) {
			return $body;
		}

		if ( 401 === $code ) {
			$this->forget_token();
			return new WP_Error(
				'zinn_offload_revoked',
				__( 'This site is no longer connected to Zinn® media offload. New uploads will stay on this server. Pair it again from your Zinn Digital® dashboard.', 'zinn-offload' )
			);
		}

		$detail = isset( $body['detail'] ) ? (string) $body['detail'] : '';
		if ( '' === $detail ) {
			$detail = sprintf(
				/* translators: %d: HTTP status code returned by the Zinn Digital API. */
				__( 'Zinn Digital® answered with HTTP %d.', 'zinn-offload' ),
				$code
			);
		}
		return new WP_Error( 'zinn_offload_api', $detail );
	}

	/**
	 * Drop the stored token, keeping everything else.
	 *
	 * ⛔⛔ **`prefix` and `public_base_url` SURVIVE, deliberately.** Media already offloaded
	 * is referenced by URL in this site's published posts. Forgetting where it lives would
	 * break every one of those images — the customer asked to stop uploading, not to blank
	 * their archive.
	 */
	private function forget_token(): void {
		$settings               = zinn_offload_settings();
		$settings['token']      = '';
		$settings['last_error'] = __( 'Disconnected by Zinn Digital®.', 'zinn-offload' );
		update_option( ZINN_OFFLOAD_OPTION, $settings, false );
	}
}
