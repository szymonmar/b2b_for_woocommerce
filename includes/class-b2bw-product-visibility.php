<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Ogranicza widoczność produktów oznaczonych jako dostępne wyłącznie dla klientów B2B. */
final class B2BW_Product_Visibility {
    const META_KEY = '_b2bw_only_for_b2b';
    const QUERY_VAR = 'b2bw_include_b2b_only';

    public static function init() {
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_data_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_meta' ) );
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'map_wc_query_arg' ), 10, 2 );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_product_query' ), 20 );
    }

    public static function add_product_data_tab( $tabs ) {
        $tabs['b2bw'] = array(
            'label'    => __( 'WooCommerce B2B', 'b2b-woocommerce' ),
            'target'   => 'b2bw_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable' ),
            'priority' => 75,
        );
        return $tabs;
    }

    public static function render_product_data_panel() {
        global $post;
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        ?>
        <div id="b2bw_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(
                    array(
                        'id'          => self::META_KEY,
                        'label'       => __( 'Tylko dla klientów B2B', 'b2b-woocommerce' ),
                        'description' => __( 'Produkt będzie widoczny w sklepie wyłącznie dla zalogowanych klientów przypisanych do konta B2B.', 'b2b-woocommerce' ),
                        'value'       => 'yes' === get_post_meta( $post->ID, self::META_KEY, true ) ? 'yes' : 'no',
                    )
                );
                ?>
            </div>
        </div>
        <?php
    }

    public static function save_product_meta( $product_id ) {
        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            return;
        }
        if ( isset( $_POST[ self::META_KEY ] ) && 'yes' === wc_clean( wp_unslash( $_POST[ self::META_KEY ] ) ) ) {
            update_post_meta( $product_id, self::META_KEY, 'yes' );
        } else {
            delete_post_meta( $product_id, self::META_KEY );
        }
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }
    }

    /** Jawne dołączenie produktów B2B-only: WP_Query/get_posts lub wc_get_products. */
    public static function map_wc_query_arg( $wp_query_args, $query_vars ) {
        if ( ! empty( $query_vars[ self::QUERY_VAR ] ) ) {
            $wp_query_args[ self::QUERY_VAR ] = true;
        }
        return $wp_query_args;
    }

    
    public static function filter_product_query( $query ) {
        if ( ! $query instanceof WP_Query ) {
            return;
        }

        // Nie ograniczaj zapytań w panelu administracyjnym.
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        // Jawne dołączenie produktów B2B-only.
        if ( $query->get( self::QUERY_VAR ) ) {
            return;
        }

        // Klienci B2B widzą wszystkie produkty.
        if (
            class_exists( 'B2BW_Pricing' ) &&
            B2BW_Pricing::get_current_account_id()
        ) {
            return;
        }

        // Administratorzy i Shop Managerzy widzą wszystkie produkty.
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        $post_types = is_array( $post_type )
            ? $post_type
            : array( $post_type );

        if (
            ! in_array( 'product', $post_types, true ) &&
            ! in_array( 'product_variation', $post_types, true ) &&
            ! in_array( 'any', $post_types, true )
        ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );

        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => self::META_KEY,
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => self::META_KEY,
                'value'   => 'yes',
                'compare' => '!=',
            ),
        );

        $query->set( 'meta_query', $meta_query );
    }
}
