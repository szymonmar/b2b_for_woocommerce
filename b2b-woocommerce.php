<?php
/**
 * Plugin Name: B2B dla WooCommerce
 * Description: Podstawowe zarządzanie kontami B2B i regułami przypisywania adresów e-mail.
 * Version: 0.2.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Własna wtyczka
 * Text Domain: b2b-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'B2BW_VERSION', '0.2.1' );
define( 'B2BW_FILE', __FILE__ );
define( 'B2BW_PATH', plugin_dir_path( __FILE__ ) );

require_once B2BW_PATH . 'includes/class-b2bw-plugin.php';
require_once B2BW_PATH . 'includes/class-b2bw-pricing.php';

register_activation_hook( __FILE__, array( 'B2BW_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'B2BW_Plugin', 'init' ) );
add_action( 'plugins_loaded', array( 'B2BW_Pricing', 'maybe_upgrade' ), 5 );
