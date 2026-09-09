<?php
/**
 * Putting an attachment — and every size WordPress generated from it — into object storage.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offloads an attachment after WordPress has finished making its thumbnails.
 *
 * ⛔⛔ **THE HOOK IS `wp_generate_attachment_metadata`, NOT `wp_handle_upload`, AND THE
 * DIFFERENCE IS THE WHOLE FEATURE.** `wp_handle_upload` fires when the original file lands,
 * *before* WordPress has produced the thumbnail, medium and large derivatives — so offloading
 * there uploads one file out of the six or seven a theme actually renders. The site would then
 * serve its full-size images from the bucket and every thumbnail from local disk, which looks
 * like it is working and defeats the point.
 *
 * ⛔⛔ **A FAILED OFFLOAD IS NOT A FAILED UPLOAD.** Every path here returns the metadata
 * unchanged on failure and leaves the local file alone. The customer pressed "Upload" in their
 * media library; if our storage is unreachable, the correct outcome is a working local image
 * and a note in the log — never a broken attachment or a media library that refuses to accept
 * files because a third party is down.
 */
class Zinn_Offload_Uploader {

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
		// Priority 20: after WordPress and after any image-optimisation plugin that rewrites
		// the derivatives at the default priority. Uploading at 10 would ship the unoptimised
		// bytes and leave the optimised ones on disk, unread.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'forget' ) );
	}

	/**
	 * Offload one attachment and all of its generated sizes.
	 *
	 * @param array<string, mixed> $metadata      The attachment metadata.
	 * @param int                  $attachment_id The attachment post ID.
	 * @return array<string, mixed> The metadata, unchanged.
	 */
	public function offload( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! zinn_offload_is_connected() ) {
			return $metadata;
		}

		// ⛔⛔ THE CUSTOMER'S CHOICES ARE READ HERE, ON THE UPLOAD THEY GOVERN. A switch that
		// is only stored is the placeholder §2.41 forbids, and it is worse than an absent
		// one: the customer believes new uploads have stopped moving when they have not.
		if ( ! zinn_offload_setting( 'offload_new_uploads', true ) ) {
			return $metadata;
		}

		$excluded = (array) zinn_offload_setting( 'exclude_mime', array() );
		if ( array() !== $excluded ) {
			$mime = (string) get_post_mime_type( (int) $attachment_id );
			if ( '' !== $mime && in_array( $mime, array_map( 'strval', $excluded ), true ) ) {
				return $metadata;
			}
		}

		$files = $this->files_for( $metadata, (int) $attachment_id );

		// ⛔ Applied per FILE, not per attachment: a 3 MB original and its 2 KB thumbnail are
		// different decisions, and moving the thumbnail costs more in the extra connection
		// than it saves in disk. The original is what the floor is really about.
		$floor_kb = (int) zinn_offload_setting( 'min_size_kb', 0 );
		if ( $floor_kb > 0 ) {
			$files = array_filter(
				$files,
				static function ( $path ) use ( $floor_kb ): bool {
					$size = (int) @filesize( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing generated size is not an error worth a warning in a log; it simply is not offloaded.
					return $size >= $floor_kb * KB_IN_BYTES;
				}
			);
		}

		if ( array() === $files ) {
			return $metadata;
		}

		$tickets = $this->client->presign( array_keys( $files ) );
		if ( is_wp_error( $tickets ) ) {
			$this->note( $tickets->get_error_message() );
			return $metadata;
		}

		$uploaded = 0;
		$bytes    = 0;
		foreach ( $tickets as $ticket ) {
			$relative = $this->relative_of( (string) ( $ticket['key'] ?? '' ) );
			if ( '' === $relative || ! isset( $files[ $relative ] ) ) {
				continue;
			}
			$path   = $files[ $relative ];
			$result = $this->client->put_file( (string) ( $ticket['url'] ?? '' ), $path );
			if ( is_wp_error( $result ) ) {
				// ⛔ Stop at the first failure rather than grinding through the rest. If
				// storage is refusing us, the next fifty will refuse too, and each one costs
				// the person watching the spinner another upload timeout.
				$this->note( $result->get_error_message() );
				break;
			}
			++$uploaded;
			$size   = (int) filesize( $path );
			$bytes += max( 0, $size );
		}

		if ( $uploaded > 0 ) {
			// ⭐ Stamped on the ORIGINAL's relative path. The rewriter derives every size's
			// URL from it, so one meta value covers the whole family — and the sweeper's
			// "not yet offloaded" query stays a single meta comparison.
			update_post_meta( (int) $attachment_id, ZINN_OFFLOAD_KEY_META, $this->original_of( $metadata ) );
			$this->count( $uploaded, $bytes );
			$this->maybe_delete_local( $files, (int) $attachment_id, count( $files ) === $uploaded );
		}

		return $metadata;
	}

	/**
	 * Forget an attachment we no longer host.
	 *
	 * ⛔ The object in the bucket is NOT deleted from here. A media file may be referenced by
	 * a post on another site, by a cached page, or by an email already sent, and this plugin
	 * runs on a server we do not control — a delete triggered from here would be an
	 * unauthenticated instruction to destroy customer data. Retention is Zinn's decision,
	 * expressed as a lifecycle rule on the bucket.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return void
	 */
	public function forget( $attachment_id ): void {
		delete_post_meta( (int) $attachment_id, ZINN_OFFLOAD_KEY_META );
	}

	/**
	 * Every local file belonging to this attachment, keyed by its path relative to uploads.
	 *
	 * @param array<string, mixed> $metadata      The attachment metadata.
	 * @param int                  $attachment_id The attachment post ID.
	 * @return array<string, string> Relative key => absolute local path.
	 */
	private function files_for( array $metadata, int $attachment_id ): array {
		$uploads  = wp_get_upload_dir();
		$base     = trailingslashit( (string) $uploads['basedir'] );
		$original = $this->original_of( $metadata );
		if ( '' === $original ) {
			return array();
		}

		$files = array();
		if ( file_exists( $base . $original ) ) {
			$files[ $original ] = $base . $original;
		}

		// The generated sizes live beside the original, so their relative keys share its
		// directory. Deriving them rather than trusting a path from the metadata means a
		// crafted `file` value cannot point us at something outside the uploads tree.
		$dir = ltrim( trailingslashit( dirname( $original ) ), './' );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			$name = isset( $size['file'] ) ? basename( (string) $size['file'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$relative = $dir . $name;
			if ( file_exists( $base . $relative ) ) {
				$files[ $relative ] = $base . $relative;
			}
		}

		unset( $attachment_id );
		return $files;
	}

	/**
	 * The attachment's own file path relative to the uploads directory.
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @return string Relative path, or an empty string.
	 */
	private function original_of( array $metadata ): string {
		$file = isset( $metadata['file'] ) ? (string) $metadata['file'] : '';
		return ltrim( $file, '/' );
	}

	/**
	 * Strip this site's prefix off a key the API returned.
	 *
	 * @param string $key The full object key.
	 * @return string The part relative to this site's prefix.
	 */
	private function relative_of( string $key ): string {
		$settings = zinn_offload_settings();
		$prefix   = trailingslashit( (string) $settings['prefix'] );
		if ( '' !== $settings['prefix'] && str_starts_with( $key, $prefix ) ) {
			return substr( $key, strlen( $prefix ) );
		}
		return $key;
	}

	/**
	 * Remove the local copies, but only when the whole family made it.
	 *
	 * ⛔⛔ **`$complete` is load-bearing and it must never be relaxed to "the original
	 * uploaded".** Deleting local files after a PARTIAL offload leaves the site with
	 * thumbnails that exist in neither place — permanently, because there is no longer a
	 * local original to regenerate them from. That is unrecoverable data loss caused by a
	 * transient network error, which is why the default for `delete_local` is off and why
	 * even with it on this refuses a partial run.
	 *
	 * @param array<string, string> $files         Relative key => absolute local path.
	 * @param int                   $attachment_id The attachment post ID.
	 * @param bool                  $complete      Whether every file uploaded.
	 * @return void
	 */
	private function maybe_delete_local( array $files, int $attachment_id, bool $complete ): void {
		$settings = zinn_offload_settings();
		if ( empty( $settings['delete_local'] ) || ! $complete ) {
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return;
		}
		foreach ( $files as $path ) {
			$wp_filesystem->delete( $path );
		}
		unset( $attachment_id );
	}

	/**
	 * Add to the running totals shown on the settings screen.
	 *
	 * @param int $objects Files offloaded in this pass.
	 * @param int $bytes   Bytes offloaded in this pass.
	 * @return void
	 */
	private function count( int $objects, int $bytes ): void {
		$settings               = zinn_offload_settings();
		$settings['objects']    = (int) $settings['objects'] + $objects;
		$settings['bytes']      = (int) $settings['bytes'] + $bytes;
		$settings['last_error'] = '';
		update_option( ZINN_OFFLOAD_OPTION, $settings, false );
	}

	/**
	 * Record the most recent failure so the settings screen can show it.
	 *
	 * ⛔ Stored rather than only logged. A customer whose media stopped offloading three weeks
	 * ago has no way to read a PHP error log, so the screen has to be able to say what went
	 * wrong — otherwise the failure is silent and the first symptom is a storage bill that
	 * stopped growing.
	 *
	 * @param string $message The failure message.
	 * @return void
	 */
	private function note( string $message ): void {
		$settings               = zinn_offload_settings();
		$settings['last_error'] = sanitize_text_field( $message );
		update_option( ZINN_OFFLOAD_OPTION, $settings, false );
	}
}
