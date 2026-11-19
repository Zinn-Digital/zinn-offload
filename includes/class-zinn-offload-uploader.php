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
	 * Uploads sub-folders a shop keeps its PAID downloads in, behind its own access checks.
	 *
	 * ⛔⛔ **A PROTECTED DOWNLOAD NEVER LEAVES THE SITE.** WooCommerce (`woocommerce_uploads`),
	 * Easy Digital Downloads (`edd`) and Download Monitor (`dlm_uploads`) put a product's file
	 * in a folder the web server refuses to serve, and hand it out only after checking the
	 * order. Offloading one would put the file at a public storage address and remove the local
	 * copy, so anyone with the link could download what customers pay for. Such a file is kept
	 * local and the store's own download protection keeps working; no filter can change that.
	 *
	 * @since 1.5.9
	 */
	public const PROTECTED_DIRS = array( 'woocommerce_uploads', 'edd', 'dlm_uploads' );

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

		if ( self::is_protected_download( $this->original_of( $metadata ) ) ) {
			return $metadata;
		}

		$should   = true;
		$excluded = (array) zinn_offload_setting( 'exclude_mime', array() );
		if ( array() !== $excluded ) {
			$mime = (string) get_post_mime_type( (int) $attachment_id );
			if ( '' !== $mime && in_array( $mime, array_map( 'strval', $excluded ), true ) ) {
				$should = false;
			}
		}

		/**
		 * Filters whether this attachment is offloaded.
		 *
		 * Runs after the "Offload new uploads" switch (which always wins) and the excluded file
		 * types, so `$should` is what this plugin would do. Return false to keep it local.
		 *
		 * @since 1.5.8
		 *
		 * @param bool                 $should        Whether to offload it.
		 * @param int                  $attachment_id The attachment post ID.
		 * @param array<string, mixed> $metadata      The attachment metadata.
		 */
		if ( ! apply_filters( 'zinn_offload_should_offload', $should, (int) $attachment_id, $metadata ) ) {
			return $metadata;
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

		/**
		 * Filters the files of this attachment that are offloaded (relative key => local path).
		 *
		 * ⛔ It can only REMOVE files: anything it adds or re-points is dropped, so no filter can
		 * send a file from outside this attachment's own family in the uploads folder.
		 *
		 * @since 1.5.8
		 *
		 * @param array<string, string> $files         Relative key => absolute local path.
		 * @param int                   $attachment_id The attachment post ID.
		 * @param array<string, mixed>  $metadata      The attachment metadata.
		 */
		$filtered = apply_filters( 'zinn_offload_files', $files, (int) $attachment_id, $metadata );
		if ( is_array( $filtered ) ) {
			$files = array_intersect_assoc( $files, $filtered );
		}

		if ( array() === $files ) {
			return $metadata;
		}

		/**
		 * Fires before the files of one attachment are sent to storage.
		 *
		 * @since 1.5.8
		 *
		 * @param int                   $attachment_id The attachment post ID.
		 * @param array<string, string> $files         Relative key => absolute local path.
		 */
		do_action( 'zinn_offload_before_upload', (int) $attachment_id, $files );

		$tickets = $this->client->presign( array_keys( $files ) );
		if ( is_wp_error( $tickets ) ) {
			$this->note( $tickets->get_error_message() );
			/** This action is documented below. */
			do_action( 'zinn_offload_failed', (int) $attachment_id, $tickets );
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
				/**
				 * Fires when storage refuses an attachment (nothing more of it is sent this time).
				 *
				 * @since 1.5.8
				 *
				 * @param int       $attachment_id The attachment post ID.
				 * @param \WP_Error $error         What went wrong.
				 */
				do_action( 'zinn_offload_failed', (int) $attachment_id, $result );
				break;
			}
			++$uploaded;
			$size   = (int) filesize( $path );
			$bytes += max( 0, $size );
			/**
			 * Fires after one file is in storage.
			 *
			 * @since 1.5.8
			 *
			 * @param int    $attachment_id The attachment post ID.
			 * @param string $relative      Its key relative to the uploads folder.
			 * @param string $path          Its local path.
			 */
			do_action( 'zinn_offload_file_uploaded', (int) $attachment_id, $relative, $path );
		}

		if ( $uploaded > 0 ) {
			// ⭐ Stamped on the ORIGINAL's relative path. The rewriter derives every size's
			// URL from it, so one meta value covers the whole family — and the sweeper's
			// "not yet offloaded" query stays a single meta comparison.
			update_post_meta( (int) $attachment_id, ZINN_OFFLOAD_KEY_META, $this->original_of( $metadata ) );
			$this->count( $uploaded, $bytes );
			$this->maybe_delete_local( $files, (int) $attachment_id, count( $files ) === $uploaded );
			/**
			 * Fires after an attachment is offloaded.
			 *
			 * @since 1.5.8
			 *
			 * @param int  $attachment_id The attachment post ID.
			 * @param int  $uploaded      Files now in storage.
			 * @param int  $bytes         Their size in bytes.
			 * @param bool $complete      Whether every file of the attachment made it.
			 */
			do_action( 'zinn_offload_attachment_offloaded', (int) $attachment_id, $uploaded, $bytes, count( $files ) === $uploaded );
		}

		return $metadata;
	}

	/**
	 * An attachment is being deleted here: delete its offloaded files too, then forget it.
	 *
	 * ⭐ Since 1.6.0. Deleting media in WordPress is how the customer frees space at Zinn
	 * (and how uploads resume once a plan's storage is full), so the files Zinn holds for
	 * this attachment are deleted with it. Nothing is deleted on anyone else's initiative:
	 * this runs only inside WordPress's own `wp_delete_attachment`, after WordPress has
	 * checked the user's `delete_post` capability and the request's nonce on every screen
	 * and REST route that reaches it. It adds no endpoint of its own.
	 *
	 * The request names only this attachment's keys, relative to this site's prefix; Zinn
	 * joins them onto that prefix and refuses anything else, so this site can never delete
	 * another site's file. A failure never blocks the WordPress delete: it is recorded on
	 * the settings screen (last error) and the files stay where they are.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return void
	 */
	public function forget( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$offloaded     = (string) get_post_meta( $attachment_id, ZINN_OFFLOAD_KEY_META, true );

		/**
		 * Filters whether deleting an attachment deletes its offloaded files at Zinn.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $delete        Default true.
		 * @param int  $attachment_id The attachment post ID.
		 */
		$delete = (bool) apply_filters( 'zinn_offload_delete_remote_on_delete', true, $attachment_id );

		if ( '' !== $offloaded && $delete ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$keys     = self::keys_for( is_array( $metadata ) ? $metadata : array(), $offloaded );
			$settings = zinn_offload_settings();
			$result   = $this->client->delete_objects( self::full_keys( $keys, (string) $settings['prefix'] ) );
			if ( is_wp_error( $result ) ) {
				$this->note(
					sprintf(
						/* translators: %s: the error message. */
						__( 'A deleted media file could not be removed from Zinn® storage: %s', 'zinn-offload' ),
						$result->get_error_message()
					)
				);
			}
		}

		delete_post_meta( $attachment_id, ZINN_OFFLOAD_KEY_META );
	}

	/**
	 * Every key an offloaded attachment may have at Zinn: its original and every size (the
	 * same set the upload sends, see files_for()).
	 *
	 * Derived from the metadata, NOT from the disk: with "delete local copies" on, the local
	 * files are already gone. Sizes are taken by basename beside the original, as on upload,
	 * so a crafted `file` value cannot name anything outside the attachment's own folder.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @param string               $original The original's key as stamped at upload.
	 * @return string[] Keys relative to this site's prefix.
	 */
	public static function keys_for( array $metadata, string $original ): array {
		$original = ltrim( $original, '/' );
		if ( '' === $original ) {
			return array();
		}
		$keys = array( $original );
		$dir  = ltrim( trailingslashit( dirname( $original ) ), './' );
		$more = array();
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			$more[] = isset( $size['file'] ) ? (string) $size['file'] : '';
		}
		foreach ( $more as $file ) {
			$name = basename( $file );
			if ( '' !== $name ) {
				$keys[] = $dir . $name;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * The full storage keys for keys relative to this site's folder.
	 *
	 * ⛔ Zinn refuses (and records) a deletion naming any key outside this site's own folder,
	 * so the plugin sends the full key, exactly as the upload ticket named it.
	 *
	 * @since 1.6.0
	 *
	 * @param string[] $keys   Keys relative to this site's prefix.
	 * @param string   $prefix This site's storage folder ('' when keys are already full).
	 * @return string[] Full keys.
	 */
	public static function full_keys( array $keys, string $prefix ): array {
		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			return array_values( $keys );
		}
		$full = array();
		foreach ( $keys as $key ) {
			$full[] = $prefix . '/' . ltrim( (string) $key, '/' );
		}
		return $full;
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
	 * Whether a path relative to the uploads folder is in a shop's protected downloads folder.
	 *
	 * @param string $relative Path relative to the uploads folder.
	 * @return bool
	 */
	public static function is_protected_download( string $relative ): bool {
		$parts = explode( '/', ltrim( str_replace( '\\', '/', $relative ), '/' ) );
		$first = strtolower( $parts[0] );
		return in_array( $first, self::PROTECTED_DIRS, true );
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
