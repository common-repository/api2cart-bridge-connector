<?php
/**
 * Functions used by plugins
 */
if ( ! class_exists( 'A2CBC_Dependencies' ) ) {
	include_once 'class-a2c-bridge-connector-dependencies.php';
}

/*
 * WC Detection
 */
if ( ! function_exists( 'a2cbc_is_required_plugins_active' ) ) {

	function a2cbc_is_required_plugins_active() {
		return A2CBC_Dependencies::required_plugins_active_check();
	}
}

/**
 * A2C_woocommerce_version_error
 */
function A2C_woocommerce_version_error() {
	?>
	<div class="error notice">
				<p><?php printf( esc_html( 'Requires WooCommerce version %s or later or WP-E-Commerce.' ), esc_html( A2CBC_MIN_WOO_VERSION ) ); ?></p>
		</div>
	<?php
}
