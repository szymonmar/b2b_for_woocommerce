<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controls the visibility of products restricted to B2B customers.
 *
 * Products marked as B2B-only are hidden from guests, regular customers,
 * cron jobs, WP-CLI and any other context without a recognized B2B account.
 *
 * Protection layers:
 * 1. WP_Query (catalog, search, taxonomies, shortcodes, REST collections),
 *    including variations of restricted parent products.
 * 2. Purchasability and add-to-cart validation (classic, AJAX, Store API).
 * 3. Cart and checkout validation for items already in the cart.
 * 4. Direct Store API product routes (by ID and by slug).
 */
final class B2BW_Product_Visibility {

    /**
     * Product meta key used to identify B2B-only products.
     *
     * A value of 'yes' indicates that the product is restricted
     * to recognized B2B customers.
     *
     * @var string
     */
    const META_KEY = '_b2bw_only_for_b2b';

    /**
     * Custom query argument used to explicitly include B2B-only products.
     *
     * Intended for trusted server-side code only. Never expose it
     * to user input or feed generators.
     *
     * @var string
     */
    const QUERY_VAR = 'b2bw_include_b2b_only';

    /**
     * Object cache key and group for the list of restricted product IDs.
     */
    const CACHE_KEY   = 'restricted_ids';
    const CACHE_GROUP = 'b2bw';

    /**
     * Request-level memo of restricted parent product IDs.
     *
     * @var int[]|null
     */
    private static $restricted_ids = null;

    /**
     * Request-level memo of access checks, keyed by user ID.
     *
     * @var array<int,bool>
     */
    private static $access_cache = array();

    /**
     * Registers product visibility hooks.
     *
     * @return void
     */
    public static function init() {
        // Register the custom tab in the WooCommerce product editor.
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_data_tab' ) );

        // Render the settings panel associated with the custom product tab.
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_data_panel' ) );

        // Save the B2B-only setting when a product is updated.
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_meta' ) );

        // Map the custom query argument to WP_Query when using wc_get_products().
        add_filter(
            'woocommerce_product_data_store_cpt_get_products_query',
            array( __CLASS__, 'map_wc_query_arg' ),
            10,
            2
        );

        // Apply visibility restrictions to product queries.
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_product_query' ), 20 );

        // Block restricted products from being added to the cart (classic, AJAX and Store API).
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 4 );

        // Safety net: restricted products are never purchasable for non-B2B contexts.
        add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_is_purchasable' ), 10, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_is_purchasable' ), 10, 2 );

        // Remove restricted items that are already in the cart (cart, checkout, Store API checkout).
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_items' ) );

        // Guard direct Store API product routes, which bypass WP_Query.
        add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_store_api_product_route' ), 10, 3 );

        // Keep the restricted ID list in sync with every meta change (editor, import, REST, quick edit).
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_flush_cache' ), 10, 3 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_flush_cache' ), 10, 3 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_flush_cache' ), 10, 3 );

        // Exclude B2B-only products from Yoast SEO sitemap
        add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'exclude_from_yoast_sitemap' ), 10, 3 );

        // Exculde B2B-only products from core Wordpress sitemap
        add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_from_core_sitemap' ), 10, 2 );

        // Remove WooCommerce schema data for B2B-only products
        add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'filter_product_structured_data' ), 10, 2 );

        // Remove YoastSEO schema data for B2B-only products
        add_filter( 'wpseo_schema_graph', array( __CLASS__, 'filter_yoast_schema_graph' ), 99 );

        // Add noindex tag for B2B-only products
        add_filter( 'wp_robots', array( __CLASS__, 'filter_product_robots' ) );

        // Add noindex tag for B2B-only products (YoastSEO integration)
        add_filter( 'wpseo_robots_array', array( __CLASS__, 'filter_yoast_robots' ), 99 );
    }

    /**
     * Checks whether a product is restricted to B2B customers.
     *
     * @param int|WC_Product $product Product ID or product object.
     * @return bool
     */
    public static function is_b2b_only( $product ) {
        $product_id = $product instanceof WC_Product
            ? $product->get_id()
            : absint( $product );

        if ( ! $product_id ) {
            return false;
        }

        return 'yes' === get_post_meta(
            $product_id,
            self::META_KEY,
            true
        );
    }

    /**
     * Adds the WooCommerce B2B tab to the product data settings.
     *
     * @param array $tabs Registered product data tabs.
     * @return array Modified product data tabs.
     */
    public static function add_product_data_tab( $tabs ) {
        $tabs['b2bw'] = array(
            'label'    => __( 'WooCommerce B2B', 'b2b-woocommerce' ),
            'target'   => 'b2bw_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable' ),
            'priority' => 75,
        );

        return $tabs;
    }

    /**
     * Renders the B2B visibility settings panel in the product editor.
     *
     * Displays a checkbox allowing administrators to restrict the product
     * to recognized B2B customers.
     *
     * @return void
     */
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

    /**
     * Saves the B2B-only visibility setting for a product.
     *
     * Stores 'yes' when the checkbox is enabled. Removes the meta field
     * when the checkbox is disabled. The nonce is verified by WooCommerce
     * before woocommerce_process_product_meta fires.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public static function save_product_meta( $product_id ) {
        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            return;
        }

        if (
            isset( $_POST[ self::META_KEY ] ) &&
            'yes' === wc_clean( wp_unslash( $_POST[ self::META_KEY ] ) )
        ) {
            update_post_meta( $product_id, self::META_KEY, 'yes' );
        } else {
            delete_post_meta( $product_id, self::META_KEY );
        }

        // Clear cached product data after updating its visibility settings.
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }
    }

    /**
     * Maps the custom visibility argument to WP_Query arguments.
     *
     * Allows the b2bw_include_b2b_only argument to be used with
     * wc_get_products() and WC_Product_Query.
     *
     * @param array $wp_query_args WordPress query arguments.
     * @param array $query_vars    WooCommerce product query variables.
     * @return array Modified WordPress query arguments.
     */
    public static function map_wc_query_arg( $wp_query_args, $query_vars ) {
        if ( ! empty( $query_vars[ self::QUERY_VAR ] ) ) {
            $wp_query_args[ self::QUERY_VAR ] = true;
        }
        return $wp_query_args;
    }

    /**
     * Excludes B2B-only products and their variations from queries
     * for contexts without B2B access.
     *
     * Restricted products are excluded by ID (post__not_in) instead of a
     * meta_query. This cannot be weakened by a meta_query with relation=OR
     * added by other code, and avoids a LEFT JOIN on postmeta.
     * Variations are excluded through their restricted parent (post_parent__not_in).
     *
     * The restriction applies to front-end queries, AJAX, REST, cron and WP-CLI.
     * It does not apply to regular wp-admin screens.
     *
     * @param WP_Query $query The current WordPress query instance.
     * @return void
     */
    public static function filter_product_query( $query ) {
        if ( ! $query instanceof WP_Query ) {
            return;
        }

        // Do not restrict product queries in the administration area.
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        // Allow explicitly requested B2B-only products (trusted server-side code only).
        if ( $query->get( self::QUERY_VAR ) ) {
            return;
        }

        // Skip queries that explicitly target other post types (menus, pages, etc.).
        // An empty post_type is NOT skipped: taxonomy archives, search and ?p=ID
        // queries often have no post_type at this stage.
        $post_types = array_filter( (array) $query->get( 'post_type' ) );

        if (
            ! empty( $post_types ) &&
            ! array_intersect( $post_types, array( 'product', 'product_variation', 'any' ) )
        ) {
            return;
        }

        // Cheap cached lookup first; nothing to do when no product is restricted.
        $restricted_ids = self::get_restricted_ids();

        if ( empty( $restricted_ids ) ) {
            return;
        }

        // Most expensive check last.
        if ( self::current_context_has_access() ) {
            return;
        }

        // Exclude restricted products themselves.
        $post_not_in = array_filter( array_map( 'absint', (array) $query->get( 'post__not_in' ) ) );

        $query->set(
            'post__not_in',
            array_values( array_unique( array_merge( $post_not_in, $restricted_ids ) ) )
        );

        // Exclude variations of restricted products.
        // WooCommerce itself reads variation children with an explicit post_parent
        // and caches the result in transients. Excluding there would poison those
        // caches with an empty list, so queries scoped to one parent are left alone.
        // The parent product is protected by the checks above and below.
        if ( 0 === absint( $query->get( 'post_parent' ) ) ) {
            $parent_not_in = array_filter( array_map( 'absint', (array) $query->get( 'post_parent__not_in' ) ) );

            $query->set(
                'post_parent__not_in',
                array_values( array_unique( array_merge( $parent_not_in, $restricted_ids ) ) )
            );
        }
    }

    /**
     * Blocks adding restricted products to the cart.
     *
     * Runs for classic add-to-cart, AJAX add-to-cart, "order again"
     * and the Store API cart endpoints.
     *
     * @param bool $passed       Current validation result.
     * @param int  $product_id   Product ID (parent product for variations).
     * @param int  $quantity     Requested quantity.
     * @param int  $variation_id Variation ID, if any.
     * @return bool
     */
    public static function validate_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
        if ( ! $passed ) {
            return $passed;
        }

        if ( self::current_context_has_access() ) {
            return $passed;
        }

        if (
            self::is_restricted_product_id( $product_id ) ||
            self::is_restricted_product_id( $variation_id )
        ) {
            wc_add_notice( __( 'Ten produkt jest niedostępny.', 'b2b-woocommerce' ), 'error' );
            return false;
        }

        return $passed;
    }

    /**
     * Makes restricted products and variations non-purchasable without B2B access.
     *
     * Acts as a safety net for any code path that skips add-to-cart validation.
     * WooCommerce also uses it to purge non-purchasable items from existing carts.
     *
     * @param bool       $purchasable Current purchasable state.
     * @param WC_Product $product     Product or variation instance.
     * @return bool
     */
    public static function filter_is_purchasable( $purchasable, $product ) {
        if ( ! $purchasable || ! $product instanceof WC_Product ) {
            return $purchasable;
        }

        if ( self::current_context_has_access() ) {
            return $purchasable;
        }

        return self::is_restricted_product_id( $product->get_id() ) ? false : $purchasable;
    }

    /**
     * Removes restricted items that are already in the cart.
     *
     * Covers sessions created while the customer still had B2B access,
     * and carts restored from a persistent cart. Adding an error notice
     * also blocks the checkout until the customer reviews the cart.
     *
     * @return void
     */
    public static function validate_cart_items() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        if ( self::current_context_has_access() ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

            if (
                ! self::is_restricted_product_id( $product_id ) &&
                ! self::is_restricted_product_id( $variation_id )
            ) {
                continue;
            }

            $name = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
                ? $cart_item['data']->get_name()
                : __( 'Produkt', 'b2b-woocommerce' );

            WC()->cart->remove_cart_item( $cart_item_key );

            wc_add_notice(
                sprintf(
                    /* translators: %s: product name. */
                    __( 'Produkt „%s” został usunięty z koszyka, ponieważ jest niedostępny.', 'b2b-woocommerce' ),
                    $name
                ),
                'error'
            );
        }
    }

    /**
     * Blocks direct Store API product routes for restricted products.
     *
     * Routes such as /wc/store/v1/products/<id> and /wc/store/v1/products/<slug>
     * load the product directly and do not go through WP_Query. A 404 is returned
     * so that the existence of a restricted product is not revealed.
     * Batch requests are covered because each sub-request is dispatched separately.
     *
     * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Current response.
     * @param array                                            $handler  Matched route handler.
     * @param WP_REST_Request                                  $request  Request instance.
     * @return mixed
     */
    public static function guard_store_api_product_route( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        if ( ! preg_match( '#^/wc/store(?:/v\d+)?/products/([^/]+)/?$#', $request->get_route(), $matches ) ) {
            return $response;
        }

        // Collection-style sub-routes share the same URL shape as a product slug.
        $reserved = array( 'attributes', 'brands', 'categories', 'collection-data', 'reviews', 'tags' );

        if ( in_array( $matches[1], $reserved, true ) ) {
            return $response;
        }

        if ( empty( self::get_restricted_ids() ) || self::current_context_has_access() ) {
            return $response;
        }

        $candidate_ids = ctype_digit( $matches[1] )
            ? array( absint( $matches[1] ) )
            : self::get_product_ids_by_slug( rawurldecode( $matches[1] ) );

        foreach ( $candidate_ids as $candidate_id ) {
            if ( self::is_restricted_product_id( $candidate_id ) ) {
                return new WP_Error(
                    'woocommerce_rest_product_invalid_id',
                    __( 'Nieprawidłowy identyfikator produktu.', 'b2b-woocommerce' ),
                    array( 'status' => 404 )
                );
            }
        }

        return $response;
    }

    /**
     * Flushes the restricted ID cache when the B2B meta key changes.
     *
     * @param int|int[] $meta_id    Meta ID(s).
     * @param int       $object_id  Post ID.
     * @param string    $meta_key   Meta key.
     * @return void
     */
    public static function maybe_flush_cache( $meta_id, $object_id, $meta_key ) {
        if ( self::META_KEY === $meta_key ) {
            self::flush_cache();
        }
    }

    /**
     * Returns IDs of all products marked as B2B-only.
     *
     * Uses a direct query on purpose: running WP_Query here would trigger
     * pre_get_posts again. The result is cached per request and in the
     * object cache.
     *
     * @return int[]
     */
    public static function get_restricted_ids() {
        if ( null !== self::$restricted_ids ) {
            return self::$restricted_ids;
        }

        $ids = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

        if ( ! is_array( $ids ) ) {
            global $wpdb;

            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT pm.post_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND pm.meta_value = %s
                       AND p.post_type = %s",
                    self::META_KEY,
                    'yes',
                    'product'
                )
            );

            $ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );

            // Short expiry as a safety net for changes made outside WordPress APIs (raw SQL imports).
            wp_cache_set( self::CACHE_KEY, $ids, self::CACHE_GROUP, HOUR_IN_SECONDS );
        }

        self::$restricted_ids = $ids;

        return self::$restricted_ids;
    }

    /**
     * Clears the restricted ID caches.
     *
     * @return void
     */
    private static function flush_cache() {
        self::$restricted_ids = null;
        wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
    }

    /**
     * Checks whether a product or variation is restricted.
     *
     * A variation is restricted when its parent product is restricted.
     *
     * @param int $product_id Product or variation ID.
     * @return bool
     */
    private static function is_restricted_product_id( $product_id ) {
        $product_id = absint( $product_id );

        if ( ! $product_id ) {
            return false;
        }

        $restricted_ids = self::get_restricted_ids();

        if ( empty( $restricted_ids ) ) {
            return false;
        }

        if ( in_array( $product_id, $restricted_ids, true ) ) {
            return true;
        }

        $parent_id = (int) wp_get_post_parent_id( $product_id );

        return $parent_id > 0 && in_array( $parent_id, $restricted_ids, true );
    }

    /**
     * Finds product and variation IDs by slug.
     *
     * @param string $slug Post name.
     * @return int[]
     */
    private static function get_product_ids_by_slug( $slug ) {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_name = %s
                   AND post_type IN ( 'product', 'product_variation' )",
                sanitize_title( $slug )
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    /**
     * Determines whether the current context may access B2B-only products.
     *
     * Cron and WP-CLI never have access, even when a privileged user
     * is set (for example wp --user=admin). Feed generators that run in
     * wp-admin can be forced into restricted mode with the
     * b2bw_force_restrict_b2b_products filter.
     *
     * @return bool
     */
    private static function current_context_has_access() {
        /**
         * Forces the restricted mode regardless of the current user.
         *
         * @param bool $force Whether to force the restricted mode.
         */
        if ( apply_filters( 'b2bw_force_restrict_b2b_products', false ) ) {
            return false;
        }

        if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return false;
        }

        $user_id = get_current_user_id();

        if ( isset( self::$access_cache[ $user_id ] ) ) {
            return self::$access_cache[ $user_id ];
        }

        $has_access = false;

        if ( current_user_can( 'manage_woocommerce' ) ) {
            // Administrators and Shop Managers are not subject to visibility restrictions.
            $has_access = true;
        } elseif (
            is_callable( array( 'B2BW_Pricing', 'get_current_account_id' ) ) &&
            B2BW_Pricing::get_current_account_id()
        ) {
            // Recognized B2B customers can access all products.
            $has_access = true;
        }

        self::$access_cache[ $user_id ] = $has_access;

        return $has_access;
    }

    /**
     * Excludes B2B-only products from the Yoast XML sitemap.
     *
     * @param array  $url  Sitemap entry.
     * @param string $type Sitemap entry type.
     * @param object $post WordPress post object.
     * @return array|string
     */
    public static function exclude_from_yoast_sitemap( $url, $type, $post ) {
        if (
            'product' === $post->post_type &&
            self::is_b2b_only( $post->ID )
        ) {
            return '';
        }

        return $url;
    }

    /**
     * Excludes B2B-only products from the WordPress core sitemap query.
     *
     * @param array   $args      Sitemap query arguments.
     * @param WP_Post_Type $post_type Post type object.
     * @return array
     */
    public static function exclude_from_core_sitemap( $args, $post_type ) {
        if ( 'product' !== $post_type->name ) {
            return $args;
        }

        $meta_query = isset( $args['meta_query'] )
            ? (array) $args['meta_query']
            : array();

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

        $args['meta_query'] = $meta_query;

        return $args;
    }

    /**
     * Removes WooCommerce Product structured data for B2B-only products.
     *
     * @param array      $markup  Product structured data.
     * @param WC_Product $product Product object.
     * @return array
     */
    public static function filter_product_structured_data( $markup, $product ) {
        if ( self::is_b2b_only( $product ) ) {
            return array();
        }

        return $markup;
    }


    /**
     * Removes Product schema nodes for B2B-only products from the Yoast graph.
     *
     * @param array $graph Schema graph.
     * @return array
     */
    public static function filter_yoast_schema_graph( $graph ) {
        if ( ! is_product() ) {
            return $graph;
        }

        $product_id = get_queried_object_id();

        if ( ! self::is_b2b_only( $product_id ) ) {
            return $graph;
        }

        foreach ( $graph as $key => $piece ) {
            if (
                isset( $piece['@type'] ) &&
                in_array( 'Product', (array) $piece['@type'], true )
            ) {
                unset( $graph[ $key ] );
            }
        }

        return array_values( $graph );
    }

    /**
     * Adds noindex to B2B-only product pages.
     *
     * @param array $robots Robots directives.
     * @return array
     */
    public static function filter_product_robots( $robots ) {
        if (
            is_product() &&
            self::is_b2b_only( get_queried_object_id() )
        ) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'nofollow';
        }

        return $robots;
    }

    /**
     * Adds noindex and nofollow directives to B2B-only product pages in Yoast SEO.
     *
     * @param array $robots Robots directives.
     * @return array
     */
    public static function filter_yoast_robots( $robots ) {
        if (
            is_product() &&
            self::is_b2b_only( get_queried_object_id() )
        ) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'nofollow';
        }

        return $robots;
    }
}