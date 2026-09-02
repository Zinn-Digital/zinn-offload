<?php
/**
 * Plugin Name:       Zinn® Media Offload
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-offload
 * Description:       Moves this site's media library to Zinn® object storage and serves it from a CDN. Configured from your Zinn® dashboard — no access key is ever typed into WordPress.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-offload
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package ZinnOffload
 *
 * ⚖️ **Owner, 2026-08-28:** *"even something to connect wp to things like object storage and a
 * plugin to make it easy and controlled from our app"*. The words that shaped every decision
 * below are **controlled from our app**.
 *
 * ⛔⛔ **THE ONE PROPERTY THAT MAKES THIS PLUGIN DIFFERENT FROM EVERY OTHER OFFLOAD PLUGIN:
 * NO S3 ACCESS KEY IS EVER STORED IN WordPress.** The rest of the category asks the site
 * owner to paste an access key and a secret into `wp-admin`, where they land in `wp_options`
 * — readable by every other plugin on the site, exported with every migration, and present in
 * the backup they email to a freelancer. A leaked pair of those is the whole bucket.
 *
 * What this plugin holds instead is a **Zinn plugin token scoped to this one site**. Before
 * each upload it asks Zinn Digital for a **presigned PUT URL**, and the bytes then go straight
 * from this web server to the bucket. Zinn's own API never sees a file, and this site never
 * sees a credential that reaches anything but its own prefix. A stolen token mints upload URLs
 * under one prefix until an administrator revokes it from the dashboard — which is a thing you
 * cannot do to a leaked secret key.
 *
 * ⛔ It exposes NO endpoint of its own. Every call is outbound.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZINN_OFFLOAD_VERSION', '1.0.0' );
define( 'ZINN_OFFLOAD_FILE', __FILE__ );

/**
 * The option holding the pairing token and the settings Zinn sent with it.
 *
 * ⛔ One option, not several. A half-written configuration spread over four rows is a site
 * that reads as connected and cannot upload — and the failure surfaces days later as missing
 * images rather than as a setup error.
 */
define( 'ZINN_OFFLOAD_OPTION', 'zinn_offload_settings' );

/**
 * Post meta naming the object key an attachment was written to.
 *
 * ⛔⛔ **This is what makes the bulk sweep RESUMABLE rather than restarting.** The sweep asks
 * for attachments that do NOT have this meta, so a run cut short by a timeout, a deploy or a
 * throttled response continues from where it stopped instead of re-doing its prefix for ever.
 * A sweep that re-does its prefix never reaches the tail of a large library at any cadence.
 */
define( 'ZINN_OFFLOAD_KEY_META', '_zinn_offload_key' );

/**
 * Where the API lives.
 *
 * ⛔ Filterable so a staging site can point at `api.dev.zinndigital.com` without editing a
 * plugin file — but it defaults to production and is never read from the database, so a
 * compromised option cannot redirect this site's uploads to somebody else's server.
 *
 * @return string Absolute URL with no trailing slash.
 */
function zinn_offload_api_base(): string {
	/**
	 * Filters the Zinn Digital API base URL.
	 *
	 * @param string $base Absolute URL with no trailing slash.
	 */
	return (string) apply_filters( 'zinn_offload_api_base', 'https://api.zinndigital.com' );
}

/**
 * This site's stored offload settings.
 *
 * @return array<string, mixed> The stored settings, with every key present.
 */
function zinn_offload_settings(): array {
	$defaults = array(
		'token'           => '',
		'site_id'         => '',
		'prefix'          => '',
		'public_base_url' => '',
		'rewrite_urls'    => false,
		'delete_local'    => false,
		'objects'         => 0,
		'bytes'           => 0,
		'last_error'      => '',
		'last_checked'    => 0,
	);
	$stored   = get_option( ZINN_OFFLOAD_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return array_merge( $defaults, $stored );
}

/**
 * Whether this site is paired and allowed to upload.
 *
 * @return bool True when a token is present.
 */
function zinn_offload_is_connected(): bool {
	$settings = zinn_offload_settings();
	return '' !== (string) $settings['token'];
}

require_once __DIR__ . '/includes/class-zinn-offload-client.php';
require_once __DIR__ . '/includes/class-zinn-offload-uploader.php';
require_once __DIR__ . '/includes/class-zinn-offload-rewriter.php';
require_once __DIR__ . '/includes/class-zinn-offload-sweeper.php';
require_once __DIR__ . '/includes/class-zinn-offload-settings.php';

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'zinn-offload', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		$client = new Zinn_Offload_Client();
		( new Zinn_Offload_Settings( $client ) )->register();

		// ⛔ The three runtime pieces only attach when the site is actually paired. An
		// unpaired install must be inert: a filter on `wp_get_attachment_url` that runs on
		// every image on every page load, to decide it has nothing to do, is a cost the
		// customer pays for a feature they have not switched on.
		if ( zinn_offload_is_connected() ) {
			( new Zinn_Offload_Uploader( $client ) )->register();
			( new Zinn_Offload_Rewriter() )->register();
			( new Zinn_Offload_Sweeper( $client ) )->register();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'zinn_offload_sweep' );
	}
);

/**
 * Uninstall is handled by `uninstall.php`, not by a hook.
 *
 * ⛔⛔ Do NOT add `register_uninstall_hook()` here with a closure. That function serialises
 * the callback into the `uninstall_plugins` option, so a closure kills activation outright
 * with `Serialization of 'Closure' is not allowed` — D13181, which made a sibling plugin
 * impossible to activate on every WordPress version. `uninstall.php` already takes precedence
 * over such a hook, so a second mechanism would be dead code that looks load-bearing.
 */

// ── The Zinn® panel ──────────────────────────────────────────────────────────────────────
//
// ⚖️ Owner, 2026-09-01: *"each plugin should promote our hosting and marketplace as well as
// Zinn Hub global marketplace inside people's site in the admin dashboard"*, and *"user
// guides for them … linked to in the plugins dashboard"*.
//
// ⛔ `require_once` rather than the autoloader, and a STRING callable rather than
// `array( Zinn_Offload_Promo::class, … )`. The class is deliberately global — it is shipped
// identically into seven plugins with different namespacing conventions, and three of them
// bootstrap inside a namespace where `Zinn_Offload_Promo::class` would resolve to a class that does
// not exist. A string callable is resolved in the global namespace at call time, which is
// correct from every one of the seven. `php -l` cannot see that mistake; only running it can.
require_once __DIR__ . '/includes/class-zinn-offload-promo.php';
add_action( 'plugins_loaded', array( 'Zinn_Offload_Promo', 'register' ) );
