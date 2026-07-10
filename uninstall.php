<?php
/**
 * Uninstall handler. Data is only removed when the admin opted in via
 * WooCommerce → Settings → B2B → "Remove data on uninstall".
 *
 * @package WooB2B
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wb2b_options = array(
	'wb2b_hide_prices',
	'wb2b_price_placeholder',
	'wb2b_force_login',
	'wb2b_organization_billing',
	'wb2b_require_organization',
	'wb2b_remove_data_on_uninstall',
);

if ( 'yes' !== get_option( 'wb2b_remove_data_on_uninstall', 'no' ) ) {
	return;
}

// Delete organizations and their meta.
$wb2b_organization_ids = get_posts(
	array(
		'post_type'      => 'wb2b_organization',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $wb2b_organization_ids as $wb2b_organization_id ) {
	wp_delete_post( $wb2b_organization_id, true );
}

// Delete member assignments.
delete_metadata( 'user', 0, '_wb2b_organization_id', '', true );

// Delete settings.
foreach ( $wb2b_options as $wb2b_option ) {
	delete_option( $wb2b_option );
}
