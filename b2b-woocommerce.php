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

add_action(
    'wp_ajax_b2bw_json_search_products_only',
    'b2bw_json_search_products_only'
);

add_action(
    'wp_ajax_b2bw_json_search_variations_only',
    'b2bw_json_search_variations_only'
);

function b2bw_json_search_products_only() {
    b2bw_json_search_objects('products');
}

function b2bw_json_search_variations_only() {
    b2bw_json_search_objects('variations');
}

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
