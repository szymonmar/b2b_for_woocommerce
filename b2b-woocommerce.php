<?php
/**
 * Plugin Name: B2B dla WooCommerce
 * Description: Narzędzie do zarządzania sprzedazą B2B w WooCommerce - ceny hurtowe dla klientów B2B
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Szymon Marszałek
 * Text Domain: b2b-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'B2BW_VERSION', '1.0.1' );
define( 'B2BW_FILE', __FILE__ );
define( 'B2BW_PATH', plugin_dir_path( __FILE__ ) );

require_once B2BW_PATH . 'includes/class-b2bw-plugin.php';
require_once B2BW_PATH . 'includes/class-b2bw-pricing.php';

register_activation_hook( __FILE__, array( 'B2BW_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'B2BW_Plugin', 'init' ) );
add_action( 'plugins_loaded', array( 'B2BW_Pricing', 'maybe_upgrade' ), 5 );

add_action( 'wp_ajax_b2bw_json_search_products_only', 'b2bw_json_search_products_only' );

add_action( 'wp_ajax_b2bw_json_search_variations_only', 'b2bw_json_search_variations_only' );

add_action( 'admin_enqueue_scripts', 'b2bw_enqueue_admin_assets' );

add_action( 'wp_enqueue_scripts', 'b2bw_enqueue_frontend_assets' );

/**
 * Runs AJAX search with permission checking for WooCommerce products only. Used in admin control panel of the plugin
 */
function b2bw_json_search_products_only() {
    b2bw_json_search_objects('products');
}

/**
 * Runs AJAX search with permission checking for WooCommerce variations only. Used in admin control panel of the plugin
 */
function b2bw_json_search_variations_only() {
    b2bw_json_search_objects('variations');
}


/**
 * Handles AJAX searches for WooCommerce products or variations.
 *
 * Checks the user's permissions and verifies the AJAX nonce before processing
 * the search request. Searches for products matching the provided term and
 * filters the results based on the requested mode:
 * - 'products': simple products and variable parent products.
 * - 'variations': product variations only.
 *
 * Returns an empty JSON array if the search term is empty. Otherwise, returns
 * a JSON object mapping product or variation IDs to their formatted names.
 *
 * @param string $mode Search mode: 'products' or 'variations'.
 *
 * @return void Sends a JSON response and terminates execution.
 */
function b2bw_json_search_objects($mode) {
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Brak uprawnień.', 403);
    }

    check_ajax_referer('search-products', 'security');

    $term = isset($_GET['term'])
        ? sanitize_text_field(wp_unslash($_GET['term']))
        : '';

    if ($term === '') {
        wp_send_json([]);
    }

    $args = [
        'status' => ['publish', 'private', 'draft'],
        'limit'  => 30,
        's'      => $term,
        'return' => 'objects',
    ];

    if ($mode === 'products') {
        // Tylko produkty proste i nadrzędne produkty wariantowe.
        $args['type'] = ['simple', 'variable'];
    } else {
        // Tylko warianty.
        $args['type'] = ['variation'];
    }

    $products = wc_get_products($args);
    $results  = [];

    foreach ($products as $product) {
        if (!$product instanceof WC_Product) {
            continue;
        }

        // Dodatkowa kontrola typu zwracanego obiektu.
        if ($mode === 'products' && $product->is_type('variation')) {
            continue;
        }

        if ($mode === 'variations' && !$product->is_type('variation')) {
            continue;
        }

        $results[$product->get_id()] = wp_strip_all_tags(
            html_entity_decode(
                $product->get_formatted_name(),
                ENT_QUOTES,
                get_bloginfo('charset')
            )
        );
    }

    wp_send_json($results);
}


/**
 * Enqueue plugin assets used in admin panel
 */
function b2bw_enqueue_admin_assets($hook) {

    if (
        !isset($_GET['page']) ||
        $_GET['page'] !== 'b2bw-pricing'
    ) {
        return;
    }

    wp_enqueue_style(
        'b2bw-admin-style',
        plugin_dir_url(__FILE__) . 'assets/css/b2bw-admin-style.css',
        [], 
        B2BW_VERSION
    );
}


/**
 * Enqueue plugin assets used in the front-end
 */
function b2bw_enqueue_frontend_assets() {
    if(is_product()) {
        wp_enqueue_script(
            'b2bw-script',
            plugin_dir_url( __FILE__ ) . 'assets/js/b2bw-script.js',
            [ 'jquery', 'wc-add-to-cart-variation' ],
            B2BW_VERSION,
            true
        );

        wp_enqueue_style(
            'b2bw-product-style',
            plugin_dir_url(__FILE__) . 'assets/css/b2bw-product-style.css',
            [], 
            B2BW_VERSION
        );
    }
}