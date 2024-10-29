<?php
/*
Plugin Name: Api2Cart Bridge Connector
Description: Api2Cart Bridge Connector
Author: API2Cart
Author URI: https://api2cart.com/
Version: 3.0.2
*/

/*
Api2Cart Bridge Connector is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Api2Cart Bridge Connector is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Api2Cart Bridge Connector. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

defined( 'ABSPATH' ) || die( 'Cannot access pages directly.' );
define( 'A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME', 'A2C_woocommerce_bridge_connector_is_custom' );
define( 'A2CBC_BRIDGE_IS_INSTALLED', 'A2C_woocommerce_bridge_connector_is_installed' );
define( 'A2CBC_STORE_KEY', 'A2C_store_key' );

if ( ! defined( 'A2CBC_STORE_BASE_DIR' ) ) {
	define( 'A2CBC_STORE_BASE_DIR', ABSPATH );
}

if ( ! defined( 'A2CBC_MIN_WOO_VERSION' ) ) {
	define( 'A2CBC_MIN_WOO_VERSION', '2.8.1' );
}

if ( ! function_exists( 'a2cbc_is_required_plugins_active' ) ) {
	include_once 'includes/a2c-bridge-connector-functions.php';
}

if ( ! a2cbc_is_required_plugins_active() ) {
	add_action( 'admin_notices', 'A2C_woocommerce_version_error' );

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( __FILE__ ), false, false );
	}

	return;
}

require 'worker.php';
$worker   = new BridgeConnector();
$storeKey = $worker->getStoreKey();

require_once $worker->bridgePath . $worker->configFilePath;

$isCustom  = get_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME );
$bridgeUrl = $worker->getBridgeUrl();

add_action( 'wp_ajax_A2CBCbridge_action',
	function () use ( $worker, $storeKey ) {
		A2CBCbridge_action( $worker, $storeKey );
	} );

/**
 * A2CBCbridge_action
 *
 * @param BridgeConnector    $worker    Worker
 * @param string             $storeKey  Store Key
 *
 * @throws Exception
 */
function A2CBCbridge_action( BridgeConnector $worker, $storeKey ) {
	check_ajax_referer('A2C-connector-nonce', 'security');

	if ( isset( $_REQUEST['connector_action'] ) ) {
		$action = sanitize_text_field( $_REQUEST['connector_action'] );
		$warning = false;

		switch ( $action ) {
			case 'installBridge':
				$data = [];
				update_option( A2CBC_BRIDGE_IS_INSTALLED, true );
				$status = $worker->updateToken( $storeKey );

				if ( ! $status['success'] ) {
					break;
				}

				$status = $worker->installBridge();
				$data   = [
					'storeKey'  => $storeKey,
					'bridgeUrl' => $worker->getBridgeUrl(),
				];

				$warning = isset( $status['warning'] ) ? $status['warning'] : false;

				if ( $status['success'] || $warning ) {
					update_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME, isset( $status['custom'] ) ? $status['custom'] : false );
					update_option( A2CBC_BRIDGE_IS_INSTALLED, true );
				}
				break;
			case 'removeBridge':
				update_option( A2CBC_BRIDGE_IS_INSTALLED, false );
				$status = [
					'success' => true,
					'message' => 'Bridge deleted',
				];
				$data   = [];
				delete_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME );
				delete_option( A2CBC_BRIDGE_IS_INSTALLED );
				break;
			case 'updateToken':
				$storeKey = $worker->updateStoreKey();
				$status   = $worker->updateToken( $storeKey );
				$data     = [ 'storeKey' => $storeKey ];
		}//end switch

		echo wp_json_encode( [ 'status' => $status, 'data' => $data, 'warning' => $warning ] );
		wp_die();
	}
}

/**
 * A2C_connector_plugin_action_links
 *
 * @param array  $links Links
 * @param string $file  File
 *
 * @return array
 */
function A2C_connector_plugin_action_links( array $links, $file ) {
	plugin_basename( dirname( __FILE__ ) . '/connectorMain.php' ) == $file;

	if ( $file ) {
		$links[] = '<a href="' . admin_url( 'admin.php?page=A2C_connector-config' ) . '">' . __( 'Settings' ) . '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'A2C_connector_plugin_action_links', 10, 2 );

/**
 * Register routes.
 *
 * @since 1.5.0
 */
function A2C_rest_api_register_routes() {
	if ( isset( $GLOBALS['woocommerce'] ) || isset( $GLOBALS['wpec'] ) ) {
		include_once 'includes/class-a2c-bridge-connector-rest-api-controller.php';

		// v1
		$restApiController = new A2C_Bridge_Connector_V1_REST_API_Controller();
		$restApiController->register_routes();
	}
}

add_action( 'rest_api_init', 'A2C_rest_api_register_routes' );

/**
 * A2C_connector_config
 *
 * @return bool
 * @throws Exception
 */
function A2C_connector_config() {
	WP_Filesystem();
	global $wp_filesystem;
	global $worker;
	include_once $worker->bridgePath . $worker->configFilePath;
	$storeKey  = $worker->getStoreKey();
	$isCustom  = get_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME );
	$bridgeUrl = $worker->getBridgeUrl();
	preg_match( "/define\(\\s?'(\w+)',\s*'([^']*)'\\s?\);/", $wp_filesystem->get_contents( $worker->bridgePath . '/bridge.php' ), $matches );
	$bridgeVersion = $matches[2];
	$theme_version = wp_get_theme()->get( 'Version' );

	preg_match( "/define\(\\s?'(\w+)',\s*'([^']*)'\\s?\);/", $wp_filesystem->get_contents( $worker->bridgePath . $worker->configFilePath ), $matches );

	if ( empty( $matches[2] ) ) {
		$worker->updateToken( $storeKey );
	}

	wp_enqueue_style( 'connector-css', plugins_url( 'css/style.css', __FILE__ ) , [], $theme_version );
	wp_enqueue_script( 'connector-js', plugins_url( 'js/scripts.js', __FILE__ ), [ 'jquery' ], $theme_version );
	wp_enqueue_script( 'connector-js', plugins_url( 'js/scripts.js', __FILE__ ), [], $theme_version );
	wp_localize_script(
		'connector-js',
		'A2CAjax',
		array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce('A2C-connector-nonce'))
	);

	$showButton = 'install';
	if ( get_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME ) ) {
		$showButton = 'uninstall';
	}

	$cartName       = 'WooCommerce';
	$sourceCartName = 'WooCommerce';
	$sourceCartName = strtolower( str_replace( ' ', '-', trim( $sourceCartName ) ) );
	$referertext    = 'Connector: ' . $sourceCartName . ' to ' . $cartName . ' module';

	include 'settings.phtml';

	return true;
}

/**
 * A2C_connector_uninstall
 */
function A2C_connector_uninstall() {
	delete_option( A2CBC_BRIDGE_IS_CUSTOM_OPTION_NAME );
	delete_option( A2CBC_BRIDGE_IS_INSTALLED );
	function_exists( 'delete_site_meta' ) ? delete_site_meta( 1, A2CBC_STORE_KEY ) : delete_option( A2CBC_STORE_KEY );
}

/**
 * A2C_connector_activate
 */
function A2C_connector_activate() {
	update_option( A2CBC_BRIDGE_IS_INSTALLED, true );
}

/**
 * A2C_connector_deactivate
 */
function A2C_connector_deactivate() {
	update_option( A2CBC_BRIDGE_IS_INSTALLED, false );
}

/**
 * A2C_connector_load_menu
 */
function A2C_connector_load_menu() {
	add_submenu_page( 'plugins.php',
		__( 'Api2Cart Bridge Connector' ),
		__( 'Api2Cart Bridge Connector' ),
		'manage_options',
		'A2C_connector-config',
		'A2C_connector_config' );
}

register_activation_hook( __FILE__, 'A2C_connector_activate' );
register_uninstall_hook( __FILE__, 'A2C_connector_uninstall' );
register_deactivation_hook( __FILE__, 'A2C_connector_deactivate' );

add_action( 'admin_menu', 'A2C_connector_load_menu' );
