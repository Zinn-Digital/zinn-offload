<?php
/**
 * Uninstall handler: forget the stored connection state.
 *
 * ⛔⛔ AN `uninstall.php`, NOT A `register_uninstall_hook()` CALL. That function does not
 * store a callback — it `maybe_serialize()`s one into the `uninstall_plugins` option, so a
 * closure kills activation outright with `Serialization of 'Closure' is not allowed`, on
 * every WordPress version. It is how a sibling plugin shipped un-activatable (D13181, W26-I),
 * invisible to PHPCS, to `php -l` and to the stubbed PHPUnit harness alike, because the thing
 * that refuses it is core's serializer and there is no core in that harness.
 *
 * ⛔⛔ **THE OFFLOADED OBJECTS ARE DELIBERATELY NOT DELETED, and neither is the offload key
 * meta.** Two separate reasons, and both matter:
 *
 * * The objects are in a bucket on Zinn Digital's side. This file runs on a customer's server
 *   and holds a token that could mint upload URLs; a delete triggered from here would be an
 *   uninstall on one site destroying data the customer is paying to keep. Retention is decided
 *   in the Zinn dashboard, where the person deciding can see what they are removing.
 * * The `_zinn_offload_key` meta is what a re-install uses to know an attachment has already
 *   moved. Wiping it turns "uninstall and reinstall" — the first thing anyone does when
 *   something misbehaves — into a full re-upload of the entire media library, at the
 *   customer's bandwidth and against their storage allowance.
 *
 * ⭐ So exactly one thing is removed: the option holding the token. That is the credential,
 * and a credential left behind by an uninstalled plugin is the thing an uninstall must clear.
 *
 * @package ZinnOffload
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

const ZINN_OFFLOAD_UNINSTALL_OPTION = 'zinn_offload_settings';

if ( is_multisite() ) {
	$zinn_offload_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $zinn_offload_site_ids as $zinn_offload_site_id ) {
		switch_to_blog( (int) $zinn_offload_site_id );
		delete_option( ZINN_OFFLOAD_UNINSTALL_OPTION );
		wp_clear_scheduled_hook( 'zinn_offload_sweep' );
		restore_current_blog();
	}

	unset( $zinn_offload_site_ids, $zinn_offload_site_id );
} else {
	delete_option( ZINN_OFFLOAD_UNINSTALL_OPTION );
	wp_clear_scheduled_hook( 'zinn_offload_sweep' );
}
