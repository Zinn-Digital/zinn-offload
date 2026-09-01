<?php
/**
 * Serving offloaded media from the CDN instead of from this server.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites attachment URLs to the bucket's public base.
 *
 * ⛔⛔ **THREE FILTERS, NOT ONE, AND THE OTHER TWO ARE WHY RESPONSIVE IMAGES WORK.** The
 * obvious implementation hooks `wp_get_attachment_url` and stops. That covers a bare
 * `<img src>` and misses everything a modern theme actually emits: `srcset` is built by
 * `wp_calculate_image_srcset` from the *sizes* metadata and never calls
 * `wp_get_attachment_url` at all, and `image_downsize` short-circuits it for any sized
 * request. A one-filter implementation therefore serves the full-size image from the CDN and
 * every responsive candidate from local disk — **which looks completely correct on a desktop
 * browser**, because the desktop picks the largest candidate. It breaks on a phone, and it
 * defeats the whole feature quietly.
 *
 * ⭐ That is why this class exists rather than three `add_filter` lines: the three have to
 * agree about how a size's URL is derived, and the moment they disagree the site serves a mix.
 */
class Zinn_Offload_Rewriter {

	/**
	 * The public base for this site's bucket, with no trailing slash.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * This site's key prefix inside the bucket, with a trailing slash.
	 *
	 * @var string
	 */
	private string $prefix = '';

	/**
	 * Attach the hooks, unless rewriting is switched off.
	 *
	 * @return void
	 */
	public function register(): void {
		$settings = zinn_offload_settings();
		if ( empty( $settings['rewrite_urls'] ) || '' === (string) $settings['public_base_url'] ) {
			// ⛔ An empty public base means the bucket is private, so there is no URL a
			// browser could use. Rewriting to one anyway would replace working local images
			// with 403s — and the plugin would have written those URLs into the posts.
			return;
		}
		$this->base   = untrailingslashit( (string) $settings['public_base_url'] );
		$this->prefix = '' === (string) $settings['prefix'] ? '' : trailingslashit( (string) $settings['prefix'] );

		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 10, 5 );
		add_filter( 'image_downsize', array( $this, 'downsize' ), 10, 3 );
	}

	/**
	 * Point an attachment's URL at the bucket.
	 *
	 * @param string $url           The local URL WordPress built.
	 * @param int    $attachment_id The attachment post ID.
	 * @return string The CDN URL, or the local one when this attachment was never offloaded.
	 */
	public function attachment_url( $url, $attachment_id ) {
		$relative = $this->offloaded_path( (int) $attachment_id );
		return '' === $relative ? $url : $this->public_url( $relative );
	}

	/**
	 * Point every responsive candidate at the bucket.
	 *
	 * @param array<int, array<string, mixed>> $sources       The srcset candidates.
	 * @param array<int, int>                  $size_array    Requested width and height.
	 * @param string                           $image_src     The image src.
	 * @param array<string, mixed>             $image_meta    The attachment metadata.
	 * @param int                              $attachment_id The attachment post ID.
	 * @return array<int, array<string, mixed>> The candidates, rewritten where possible.
	 */
	public function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$relative = $this->offloaded_path( (int) $attachment_id );
		if ( '' === $relative || ! is_array( $sources ) ) {
			return $sources;
		}
		$dir = $this->directory_of( $relative );
		foreach ( $sources as $width => $source ) {
			$name = isset( $source['url'] ) ? basename( (string) $source['url'] ) : '';
			if ( '' !== $name ) {
				$sources[ $width ]['url'] = $this->public_url( $dir . $name );
			}
		}
		unset( $size_array, $image_src, $image_meta );
		return $sources;
	}

	/**
	 * Answer a sized-image request from the bucket.
	 *
	 * ⛔ Returning `false` means "I have nothing to say" and lets WordPress do its normal
	 * thing — which is the correct answer for an attachment we never offloaded, and for a
	 * requested size that does not exist in the metadata. Guessing a URL for a size that was
	 * never generated would produce a 404 in place of WordPress's own fallback to the full
	 * image.
	 *
	 * @param array<int, mixed>|false $downsize      Whatever an earlier filter decided.
	 * @param int                     $attachment_id The attachment post ID.
	 * @param string|array<int, int>  $size          The requested size.
	 * @return array<int, mixed>|false The url/width/height/is_intermediate tuple, or false.
	 */
	public function downsize( $downsize, $attachment_id, $size ) {
		if ( false !== $downsize ) {
			return $downsize;
		}
		$relative = $this->offloaded_path( (int) $attachment_id );
		if ( '' === $relative || ! is_string( $size ) ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( (int) $attachment_id );
		if ( ! is_array( $meta ) || ! isset( $meta['sizes'][ $size ]['file'] ) ) {
			return false;
		}
		$entry = $meta['sizes'][ $size ];
		return array(
			$this->public_url( $this->directory_of( $relative ) . basename( (string) $entry['file'] ) ),
			(int) ( $entry['width'] ?? 0 ),
			(int) ( $entry['height'] ?? 0 ),
			true,
		);
	}

	/**
	 * The uploads-relative path stored when this attachment was offloaded.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return string The relative path, or an empty string when it was never offloaded.
	 */
	private function offloaded_path( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$stored = get_post_meta( $attachment_id, ZINN_OFFLOAD_KEY_META, true );
		return is_string( $stored ) ? ltrim( $stored, '/' ) : '';
	}

	/**
	 * The directory part of a relative path, with a trailing slash (or empty).
	 *
	 * @param string $relative An uploads-relative path.
	 * @return string The directory, or an empty string when there is none.
	 */
	private function directory_of( string $relative ): string {
		$dir = dirname( $relative );
		return ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
	}

	/**
	 * Build the public URL for an uploads-relative path.
	 *
	 * @param string $relative An uploads-relative path.
	 * @return string An absolute URL.
	 */
	private function public_url( string $relative ): string {
		return $this->base . '/' . $this->prefix . ltrim( $relative, '/' );
	}
}
