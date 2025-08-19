<?php
/**
 * Data for the settings page file.
 *
 * @package WC_ShipStation
 */

$statuses = wc_get_order_statuses();

// When integration loaded custom statuses is not loaded yet, so we need to
// merge it manually.
if ( function_exists( 'wc_order_status_manager' ) ) {
	$query = new WP_Query(
		array(
			'post_type'        => 'wc_order_status',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'suppress_filters' => 1,
			'orderby'          => 'menu_order',
			'order'            => 'ASC',
		)
	);

	$filtered_statuses = array();
	foreach ( $query->posts as $post_status ) {
		$filtered_statuses[ 'wc-' . $post_status->post_name ] = $post_status->post_title;
	}
	$statuses = array_merge( $statuses, $filtered_statuses );

	wp_reset_postdata();
}

foreach ( $statuses as $key => $value ) {
	$statuses[ $key ] = str_replace( 'wc-', '', $key );
}

$fields = array(
	'auth_key'        => array(
		'title'             => __( 'Authentication Key', 'woocommerce-shipstation-integration' ),
		'description'       => __( 'Copy and paste this key into ShipStation during setup.', 'woocommerce-shipstation-integration' ),
		'default'           => '',
		'type'              => 'text',
		'desc_tip'          => __( 'This is the <code>Auth Key</code> you set in ShipStation and allows ShipStation to communicate with your store.', 'woocommerce-shipstation-integration' ),
		'custom_attributes' => array(
			'readonly' => 'readonly',
		),
		'value'             => WC_ShipStation_Integration::$auth_key,
	),
	'export_statuses' => array(
		'title'             => __( 'Export Order Statuses&hellip;', 'woocommerce-shipstation-integration' ),
		'type'              => 'multiselect',
		'options'           => $statuses,
		'class'             => 'chosen_select',
		'css'               => 'width: 450px;',
		'description'       => __( 'Define the order statuses you wish to export to ShipStation.', 'woocommerce-shipstation-integration' ),
		'desc_tip'          => true,
		'custom_attributes' => array(
			'data-placeholder' => __( 'Select Order Statuses', 'woocommerce-shipstation-integration' ),
		),
	),
	'shipped_status'  => array(
		'title'       => __( 'Shipped Order Status&hellip;', 'woocommerce-shipstation-integration' ),
		'type'        => 'select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to update to once an order has been shipping via ShipStation. By default this is "Completed".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => 'wc-completed',
	),
	'logging_enabled' => array(
		'title'       => __( 'Logging', 'woocommerce-shipstation-integration' ),
		'label'       => __( 'Enable Logging', 'woocommerce-shipstation-integration' ),
		'type'        => 'checkbox',
		'description' => __( 'Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => 'Log all API interactions.',
		'default'     => 'yes',
	),
);

return $fields;
