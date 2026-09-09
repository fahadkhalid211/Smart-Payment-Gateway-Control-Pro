<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Deletes all stored rules and options created by Gateway Conditioner for WooCommerce.
 *
 * @package GatewayConditionerForWooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gcw_rules' );
