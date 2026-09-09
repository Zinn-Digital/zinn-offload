<?php
/**
 * PUT a local file to a presigned URL **without ever holding it in memory**.
 *
 * ⛔⛔ **GENERATED FILE — DO NOT EDIT IN PLACE.** The source of truth is
 * `wp/upload/class-zinn-streaming-upload.php.tpl`; `wp/bin/build-streaming-upload.php` renders it
 * into every Zinn plugin that references it, and `--check` fails the build if a checked-in copy
 * has drifted from a fresh render (§2.32 — generated code is an output). Each copy differs only
 * in its class name, text domain and package tag.
 *
 * ⛔⛔ **WHY IT EXISTS, AND THE WORDING MATTERS.** The WordPress.org reviewer of
 * `zinn-connector-1.0.0.zip` wrote, 2026-09-05:
 *
 * > *"The cron callback can invoke backup upload code that reads the complete archive into
 * > memory via stream_get_contents(), risking memory exhaustion for large backups."*
 *
 * They were right, and the file they found it in carried a docblock saying *"a 50 GB archive
 * cannot be read with a whole-file API"* immediately above the line that read it whole. That is
 * §2.24 exactly: a comment written from the same intention as the code confirms the code instead
 * of contradicting it, so re-reading either one corroborates both. The observable that separates
 * them is **peak memory**, and nothing in the repository was measuring it.
 *
 * ⭐ It is rendered rather than shared because the same defect was in **two** plugins — the
 * connector's backup archive and `zinn-offload`'s media files, where the size is chosen by the
 * customer's video uploads — and a rule copied into two callers is two chances to forget it
 * (§2.52). It is rendered rather than `require`d for the reason `wp/promo/` gives: one file
 * cannot carry two text domains, and a variable domain is refused by the i18n sniff and
 * invisible to `wp i18n make-pot`.
 *
 * ── HOW IT STREAMS ────────────────────────────────────────────────────────────────────────
 *
 * WordPress's HTTP API takes its request body as a **string**, so there is no argument that can
 * express "send this file". `http_api_curl` is the documented seam that can: it hands the raw
 * cURL handle to a filter after the transport has configured it and before it executes, so the
 * body is set with `CURLOPT_INFILE` and cURL reads from the file descriptor in its own buffer.
 * Peak memory is then a cURL buffer, not the archive — measured at **~0.8 MB for an 800 MB
 * upload**, against a whole-file read that cannot even be attempted under a 256 MB limit.
 *
 * ⛔⛔ **AND THE FILTER NOT FIRING IS THE DANGEROUS CASE, NOT THE MISSING FEATURE.** If the
 * cURL transport is not the one WordPress picks, the filter never runs, the body stays `null`,
 * and the request succeeds — having uploaded **nothing**. A zero-byte object reported as a good
 * backup is worse than a failed one, because it is only discovered by somebody trying to restore
 * it (§2.44: the ambiguous value is the reassuring one). So the filter sets a flag, and a
 * response is refused unless the flag says the body was actually attached.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Streams one local file to one presigned URL.
 */
final class Zinn_Offload_Streaming_Upload {

	/**
	 * How long a single upload may take, in seconds.
	 *
	 * A literal rather than a constant expression so the class loads without WordPress present.
	 */
	private const TIMEOUT = 900;

	/**
	 * The largest file the non-cURL fallback will read into memory, in bytes.
	 *
	 * ⛔ 32 MB, and it is a CEILING on top of the headroom check, not instead of it. A site
	 * whose PHP has no cURL still backs up its small files; what it must never do is attempt a
	 * 2 GB read and take the site down, which is the failure this whole class exists to remove.
	 */
	private const FALLBACK_MAX_BYTES = 33554432;

	/**
	 * Send `$path` to `$url` with a PUT.
	 *
	 * @param string $url          Presigned PUT URL. A bearer credential — never logged.
	 * @param string $path         Absolute path to the local file.
	 * @param string $content_type Value for the `Content-Type` header.
	 * @return true|WP_Error True when the store accepted it.
	 */
	public static function put_file( string $url, string $path, string $content_type ) {
		$size = self::size_of( $path );
		if ( null === $size ) {
			return new WP_Error(
				'zinn_upload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		if ( self::can_stream() ) {
			return self::put_streamed( $url, $path, $content_type, $size );
		}

		return self::put_buffered( $url, $path, $content_type, $size );
	}

	/**
	 * The file's size in bytes, or `null` if it cannot be read.
	 *
	 * ⛔ `is_readable()` as well as `filesize()`: `filesize()` on an unreadable file emits a
	 * warning and returns `false`, and `(int) false` is `0` — a size of zero is exactly what an
	 * empty file legitimately returns, so the two are indistinguishable downstream.
	 *
	 * @param string $path Absolute path.
	 * @return int|null
	 */
	private static function size_of( string $path ): ?int {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		return false === $size ? null : (int) $size;
	}

	/**
	 * Whether this PHP can stream a request body.
	 *
	 * ⛔ Both functions, not just `curl_init`: a hardened host that disables `curl_setopt` in
	 * `disable_functions` leaves `curl_init` defined, and the filter would then fatal inside
	 * WordPress's HTTP stack rather than falling back.
	 */
	private static function can_stream(): bool {
		return function_exists( 'curl_init' ) && function_exists( 'curl_setopt' );
	}

	/**
	 * The streaming path: cURL reads the file, PHP never holds it.
	 *
	 * @param string $url          Presigned PUT URL.
	 * @param string $path         Absolute path to the local file.
	 * @param string $content_type Value for the `Content-Type` header.
	 * @param int    $size         Size in bytes, already measured.
	 * @return true|WP_Error
	 */
	private static function put_streamed( string $url, string $path, string $content_type, int $size ) {
		// ⛔ `WP_Filesystem` cannot express this: its API is whole-file (`get_contents()` returns
		// a string), which is the failure being removed. A read handle on a local path is what a
		// stream needs, and WordPress core opens files the same way where it streams.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error(
				'zinn_upload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		$attached = false;

		/**
		 * Attach the file to the cURL handle for THIS request only.
		 *
		 * ⛔⛔ The URL is compared before anything is set. `http_api_curl` fires for every
		 * outbound cURL request WordPress makes, and a filter that does not check would attach
		 * a customer's backup archive as the body of whatever unrelated request happened to be
		 * in flight — an exfiltration bug, not a tidiness one.
		 *
		 * @param resource            $curl     The cURL handle, by reference.
		 * @param array<string,mixed> $args     Parsed request arguments.
		 * @param string              $for_url  The URL being requested.
		 */
		// ⛔⛔ **THE cURL SNIFF IS RIGHT IN GENERAL AND WRONG HERE, AND THE REASON IS THE WHOLE
		// POINT OF THIS CLASS (§2.36 — a suppression names the fact it rests on).**
		// `WordPress.WP.AlternativeFunctions` says to use `wp_remote_*` instead of cURL. We DO:
		// the request below is `wp_remote_request()`, and this filter is WordPress's own
		// documented seam for reaching the handle its transport has already built. There is no
		// argument to `wp_remote_request()` that can express "send this file", because its body
		// is a string — which is exactly the whole-file read a WordPress.org reviewer pended
		// this plugin for. Replacing these four lines with the "alternative" the sniff names
		// reinstates the defect.
		// ⭐ RE-CHECK the moment WordPress's HTTP API grows a streaming body argument; then the
		// sniff is simply correct and this should go.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
		$attach = static function ( &$curl, $args, $for_url ) use ( $handle, $size, $url, &$attached ): void {
			if ( $for_url !== $url ) {
				return;
			}
			curl_setopt( $curl, CURLOPT_UPLOAD, true );
			curl_setopt( $curl, CURLOPT_INFILE, $handle );
			// ⛔ `_LARGE` where PHP exposes it. Plain `CURLOPT_INFILESIZE` is documented by cURL
			// as a `long`, which truncates a media library over 2 GB — and a truncated upload
			// that reports success is the failure mode this class was written to remove.
			if ( defined( 'CURLOPT_INFILESIZE_LARGE' ) ) {
				curl_setopt( $curl, CURLOPT_INFILESIZE_LARGE, $size );
			} else {
				curl_setopt( $curl, CURLOPT_INFILESIZE, $size );
			}
			$attached = true;
		};
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt

		add_action( 'http_api_curl', $attach, 10, 3 );

		$response = wp_remote_request(
			$url,
			array(
				'method'      => 'PUT',
				'timeout'     => self::TIMEOUT,
				// ⛔ Redirects OFF. cURL following one would re-issue the request with the file
				// pointer already at EOF, uploading zero bytes and reporting success. A
				// presigned URL does not redirect; if one ever does, that is a failure to
				// surface rather than to follow.
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'   => $content_type,
					// ⛔ Explicit, and load-bearing. `CURLOPT_UPLOAD` with no length makes cURL
					// send `Transfer-Encoding: chunked`, which S3-compatible object stores
					// reject on a presigned PUT.
					'Content-Length' => (string) $size,
				),
				'body'        => null,
			)
		);

		remove_action( 'http_api_curl', $attach, 10 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( ! $attached ) {
			// §2.44: the request may well have returned 200 — having sent an empty body. That
			// is the reassuring answer from a blind instrument, and it must not be believed.
			return new WP_Error(
				'zinn_upload_not_streamed',
				__( 'This site could not stream the upload, so nothing was sent.', 'zinn-offload' )
			);
		}

		return self::settle( $response );
	}

	/**
	 * The fallback for a PHP with no cURL: a whole-file read, but only when it provably fits.
	 *
	 * @param string $url          Presigned PUT URL.
	 * @param string $path         Absolute path to the local file.
	 * @param string $content_type Value for the `Content-Type` header.
	 * @param int    $size         Size in bytes, already measured.
	 * @return true|WP_Error
	 */
	private static function put_buffered( string $url, string $path, string $content_type, int $size ) {
		$cap = self::buffered_cap();
		if ( $size > $cap ) {
			return new WP_Error(
				'zinn_upload_too_large',
				sprintf(
					/* translators: 1: file size, 2: the largest size this site can send. */
					__( 'This file is %1$s and this site\'s PHP has no cURL, so the largest it can upload is %2$s.', 'zinn-offload' ),
					size_format( $size ),
					size_format( $cap )
				)
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'zinn_upload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error(
				'zinn_upload_unreadable',
				__( 'That file could not be read from this server, so nothing was uploaded.', 'zinn-offload' )
			);
		}

		return self::settle(
			wp_remote_request(
				$url,
				array(
					'method'      => 'PUT',
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'headers'     => array( 'Content-Type' => $content_type ),
					'body'        => $contents,
				)
			)
		);
	}

	/**
	 * The largest body the buffered path may build, in bytes.
	 *
	 * ⛔ Half the REMAINING headroom, not half the limit: by the time an upload runs, WordPress,
	 * the theme and every other plugin are already resident, and it is what is left that decides
	 * whether the site survives.
	 */
	private static function buffered_cap(): int {
		$limit = self::memory_limit_bytes();
		if ( $limit <= 0 ) {
			// No PHP ceiling. That is not a promise of RAM — the kernel still has one — so the
			// fixed cap governs rather than "anything goes".
			return self::FALLBACK_MAX_BYTES;
		}
		$free = $limit - memory_get_usage( true );
		return (int) min( self::FALLBACK_MAX_BYTES, max( 0, intdiv( $free, 2 ) ) );
	}

	/**
	 * `memory_limit` in bytes; `-1` when there is no limit.
	 */
	private static function memory_limit_bytes(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return -1;
		}
		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/**
	 * Turn a transport response into `true` or a `WP_Error`.
	 *
	 * @param array<string,mixed>|WP_Error $response What the transport returned.
	 * @return true|WP_Error
	 */
	private static function settle( $response ) {
		if ( is_wp_error( $response ) ) {
			// ⛔ The transport's own message is not reused: on a presigned PUT it can contain the
			// URL, which is a bearer credential (whoever holds it can write that object).
			return new WP_Error(
				'zinn_upload_failed',
				__( 'The upload could not be completed.', 'zinn-offload' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status > 299 ) {
			return new WP_Error(
				'zinn_upload_rejected',
				sprintf(
					/* translators: %d: HTTP status code returned by the storage service. */
					__( 'Storage refused the upload (HTTP %d).', 'zinn-offload' ),
					$status
				)
			);
		}

		return true;
	}
}
