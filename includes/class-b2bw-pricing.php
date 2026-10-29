<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** 
 * Individual B2B product prices in WooCommerce - pricing rules control panel & front-end rendering
 */
final class B2BW_Pricing {
    const PAGE_SLUG = 'b2bw-pricing';

    private static $account_cache = array();
    private static $rules_cache = array();

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_b2bw_save_price_rule', array( __CLASS__, 'handle_save_rule' ) );
        add_action( 'admin_post_b2bw_delete_price_rule', array( __CLASS__, 'handle_delete_rule' ) );
        add_action( 'b2bw_before_product_price',    array( __CLASS__, 'render_price_information' ), 10, 1 );

        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), 99, 2 );
        add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_price' ), 99, 3 );
        add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'filter_variation_regular_price' ), 99, 3 );
        add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_prices_hash' ), 99, 3 );

    }


    /**
     * Returns the fully prefixed database table name used to store B2B pricing rules.
     *
     * @return string The prefixed pricing rules table name.
     */
    private static function rules_table() {
        global $wpdb;
        return $wpdb->prefix . 'b2bw_price_rules';
    }

    
    /**
     * Renders the "Individual price" label when the current customer has a preferential price.
     *
     * For variable products, collects the IDs of variations with preferential prices
     * and outputs a single label containing those IDs as a JSON-encoded data attribute.
     * For other product types, displays the label if a preferential price is available.
     *
     * @param WC_Product $product The product to check.
     * @return void
     */
    public static function render_price_information( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        // Produkt wariantowy.
        if ( $product->is_type( 'variable' ) ) {
            $preferential_variation_ids = [];

            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );

                if ( ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                if ( null !== self::get_preferential_price( $variation ) ) {
                    $preferential_variation_ids[] = absint( $variation_id );
                }
            }

            if ( ! empty( $preferential_variation_ids ) ) {
                printf(
                    '<div class="b2b-price-info-wrapper"><span class="b2b-price-info" data-variation-ids="%s" style="display: none;">Cena indywidualna</span></div>',
                    esc_attr( wp_json_encode( $preferential_variation_ids ) )
                );
            }
            return;
        } else {
            $preferential = self::get_preferential_price( $product );

            if ( null !== $preferential ) {
                echo '<div class="b2b-price-info-wrapper"><span class="b2b-price-info">Cena indywidualna</span></div>';
            }

            return;
        }
    }


    /**
     * Creates or updates the database table used to store B2B pricing rules.
     *
     * Defines the table structure, indexes, and unique constraints, then updates
     * the stored database schema version.
     *
     * @return void
     */
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::rules_table();
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            rule_type varchar(20) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            pricing_type varchar(20) NOT NULL,
            pricing_value decimal(18,6) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY account_object (account_id,rule_type,object_id),
            KEY account_id (account_id),
            KEY object_lookup (rule_type,object_id)
        ) {$charset_collate};" );
        update_option( 'b2bw_db_version', '0.2.0' );
    }


    /**
     * Checks whether the database schema needs to be upgraded.
     *
     * Compares the stored schema version with the required version and runs
     * the activation routine if an upgrade is necessary.
     *
     * @return void
     */
    public static function maybe_upgrade() {
        if ( version_compare( (string) get_option( 'b2bw_db_version', '0.1.0' ), '0.2.0', '<' ) ) {
            self::activate();
        }
    }


    /**
     * Registers the B2B pricing submenu in the WordPress administration menu.
     *
     * Restricts access to users with the manage_woocommerce capability.
     *
     * @return void
     */
    public static function register_admin_menu() {
        add_submenu_page(
            'b2bw-accounts',
            __( 'Ceny dla Klientów B2B', 'b2b-woocommerce' ),
            __( 'Ceny dla Klientów B2B', 'b2b-woocommerce' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }


    /**
     * Loads the scripts and styles required by the B2B pricing administration page.
     *
     * Enqueues WooCommerce enhanced product selectors and registers inline JavaScript
     * for toggling rule fields and adjusting available pricing options according
     * to the selected rule type and pricing method.
     *
     * @param string $hook The current administration page hook suffix.
     * @return void
     */
    public static function enqueue_admin_assets( $hook ) {
        // Hook suffix może zależeć od sluga menu nadrzędnego; parametr page jest jednoznaczny.
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
        wp_add_inline_script( 'wc-enhanced-select', "jQuery(function($) {
            function b2bwToggleTarget() {
                var type = $('#b2bw-rule-type').val();

                // Kategorie / obiekty
                $('.b2bw-category-target').toggle(type === 'category');
                $('.b2bw-object-target').toggle(type === 'product');
                $('.b2bw-variant-target').toggle(type === 'variation');

                if(type === 'category') {
                    $('.b2bw-object-target').val('');
                    $('.b2bw-variant-target').val('');
                }
                if(type === 'product') {
                    $('.b2bw-variant-target').val('');
                    $('.b2bw-category-target').val('');
                }
                if(type === 'variation') {
                    $('.b2bw-object-target').val('');
                    $('.b2bw-category-target').val('');
                }

                // Rodzaj ceny
                $('#b2bw-pricing-type option[value=percent]').prop('disabled', false);

                if (type === 'category') {
                    $('#b2bw-pricing-type option[value=fixed]').prop('disabled', true);

                    if ($('#b2bw-pricing-type').val() === 'fixed') {
                        $('#b2bw-pricing-type').val('percent');
                    }
                } else {
                    $('#b2bw-pricing-type option[value=fixed]').prop('disabled', false);
                }

                $('#b2bw-pricing-value').attr(
                    'max',
                    $('#b2bw-pricing-type').val() === 'percent' ? '100' : ''
                );
            }

            // Zmiana zakresu reguły
            $('#b2bw-rule-type').on('change', b2bwToggleTarget);

            // Zmiana rodzaju ceny
            $('#b2bw-pricing-type').on('change', b2bwToggleTarget);

            b2bwToggleTarget();
        });" );
    }


    /**
     * Retrieves B2B account IDs associated with an email address.
     *
     * Checks for an exact email match first. If no exact match is found, searches
     * for accounts associated with the email's domain. Returns unique account IDs.
     *
     * @param string $email The email address to look up.
     * @return int[] An array of matching account IDs.
     */
    private static function get_account_ids_for_email( $email ) {
        global $wpdb;
        $email = strtolower( trim( (string) $email ) );
        if ( ! is_email( $email ) ) {
            return array();
        }
        $emails_table = $wpdb->prefix . 'b2bw_account_emails';
        $domains_table = $wpdb->prefix . 'b2bw_account_domains';
        $exact = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT account_id FROM {$emails_table} WHERE email = %s", $email ) );
        if ( ! empty( $exact ) ) {
            return array_values( array_unique( array_map( 'absint', $exact ) ) );
        }
        $parts = explode( '@', $email );
        $domain = end( $parts );
        $matches = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT account_id FROM {$domains_table} WHERE domain = %s", $domain ) );
        return array_values( array_unique( array_map( 'absint', $matches ) ) );
    }


    /**
     * Determines the B2B account associated with the currently logged-in user.
     *
     * Uses the user's email address to find matching accounts and caches the result
     * for the current request. Returns an account ID only when exactly one account
     * matches; otherwise, returns zero.
     *
     * @return int The matching B2B account ID, or zero if no unique match exists.
     */
    public static function get_current_account_id() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }
        if ( array_key_exists( $user_id, self::$account_cache ) ) {
            return self::$account_cache[ $user_id ];
        }
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            self::$account_cache[ $user_id ] = 0;
            return 0;
        }
        $matches = self::get_account_ids_for_email( $user->user_email );
        // Nie przypisujemy cen, jeśli konfiguracja wskazuje na kilka kont.
        self::$account_cache[ $user_id ] = 1 === count( $matches ) ? (int) $matches[0] : 0;
        return self::$account_cache[ $user_id ];
    }


    /**
     * Retrieves and caches all pricing rules assigned to a B2B account.
     *
     * @param int $account_id The B2B account ID.
     * @return array The pricing rules associated with the account.
     */
    private static function get_account_rules( $account_id ) {
        global $wpdb;
        if ( ! isset( self::$rules_cache[ $account_id ] ) ) {
            self::$rules_cache[ $account_id ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rules_table() . ' WHERE account_id = %d', $account_id ) );
        }
        return self::$rules_cache[ $account_id ];
    }


    /**
     * Finds a pricing rule matching a specific rule type and object ID.
     *
     * @param array  $rules     The pricing rules to search.
     * @param string $type      The rule type to match.
     * @param int    $object_id The product, variation, or category ID.
     * @return object|null The matching rule, or null if no rule is found.
     */
    private static function find_rule( $rules, $type, $object_id ) {
        foreach ( $rules as $rule ) {
            if ( $type === $rule->rule_type && (int) $object_id === (int) $rule->object_id ) {
                return $rule;
            }
        }
        return null;
    }


    /**
     * Calculates a preferential price using a pricing rule.
     *
     * Supports percentage discounts applied to the regular price and fixed prices.
     * Returns null if the rule is missing, the regular price is invalid, or the
     * pricing type is unsupported. The resulting price is clamped to zero or above
     * and formatted using the store's configured price precision.
     *
     * @param string|float $regular_price The product's regular price.
     * @param object|null  $rule          The pricing rule to apply.
     * @return float|null The calculated preferential price, or null if unavailable.
     */
    private static function apply_rule( $regular_price, $rule ) {
        if ( null === $rule || '' === $regular_price || ! is_numeric( $regular_price ) ) {
            return null;
        }
        $base = (float) $regular_price;
        $value = (float) $rule->pricing_value;
        if ( 'percent' === $rule->pricing_type ) {
            $price = $base * ( 1 - ( $value / 100 ) );
        } elseif ( 'fixed' === $rule->pricing_type ) {
            $price = $value;
        } else {
            return null;
        }
        $price = max( 0, $price );
        return (float) wc_format_decimal( $price, wc_get_price_decimals() );
    }


    /**
     * Determines the preferential price for a product or variation.
     *
     * Resolves the current customer's B2B account and applies the matching pricing
     * rules in priority order: variation, parent product, then product categories.
     * When multiple category rules match, returns the lowest calculated price.
     *
     * @param WC_Product $product The product or variation to evaluate.
     * @return float|null The preferential price, or null if no applicable rule exists.
     */
    public static function get_preferential_price( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return null;
        }
        $account_id = self::get_current_account_id();
        if ( ! $account_id ) {
            return null;
        }
        $rules = self::get_account_rules( $account_id );
        if ( empty( $rules ) ) {
            return null;
        }

        $is_variation = $product->is_type( 'variation' );
        $product_id = $is_variation ? $product->get_parent_id() : $product->get_id();
        $regular_price = $product->get_regular_price( 'edit' );

        if ( $is_variation ) {
            $rule = self::find_rule( $rules, 'variation', $product->get_id() );
            if ( $rule ) {
                return self::apply_rule( $regular_price, $rule );
            }
        }

        $rule = self::find_rule( $rules, 'product', $product_id );
        if ( $rule ) {
            return self::apply_rule( $regular_price, $rule );
        }

        $category_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
        $best_price = null;
        foreach ( $category_ids as $category_id ) {
            $category_rule = self::find_rule( $rules, 'category', $category_id );
            if ( ! $category_rule ) {
                continue;
            }
            $candidate = self::apply_rule( $regular_price, $category_rule );
            if ( null !== $candidate && ( null === $best_price || $candidate < $best_price ) ) {
                $best_price = $candidate;
            }
        }
        return $best_price;
    }


    /**
     * Filters the WooCommerce product price to apply the customer's preferential price.
     *
     * @param string     $price   The original product price.
     * @param WC_Product $product The product being priced.
     * @return string The preferential price if available, otherwise the original price.
     */
    public static function filter_price( $price, $product ) {
        $preferential = self::get_preferential_price( $product );
        return null !== $preferential ? (string) $preferential : $price;
    }


    /**
     * Filters the WooCommerce regular price to reflect the customer's preferential price.
     *
     * @param string     $price   The original regular price.
     * @param WC_Product $product The product being priced.
     * @return string The preferential price if available, otherwise the original price.
     */
    public static function filter_regular_price( $price, $product ) {
        $preferential = self::get_preferential_price( $product );
        return null !== $preferential ? (string) $preferential : $price;
    }


    /**
     * Prevents a product from being marked as on sale when a preferential B2B price applies.
     *
     * @param bool       $on_sale Whether WooCommerce considers the product to be on sale.
     * @param WC_Product $product The product being evaluated.
     * @return bool False if a preferential price applies; otherwise, the original sale status.
     */
    public static function filter_is_on_sale( $on_sale, $product ) {
        return null !== self::get_preferential_price( $product ) ? false : $on_sale;
    }


    /**
     * Filters a variation's price to apply the customer's preferential price.
     *
     * @param float            $price     The original variation price.
     * @param WC_Product       $variation The variation being priced.
     * @param WC_Product|false $parent    The parent variable product.
     * @return float The preferential price if available, otherwise the original price.
     */
    public static function filter_variation_price( $price, $variation, $parent ) {
        $preferential = self::get_preferential_price( $variation );
        return null !== $preferential ? $preferential : $price;
    }


    /**
     * Filters a variation's regular price to reflect the customer's preferential price.
     *
     * @param float            $price     The original regular variation price.
     * @param WC_Product       $variation The variation being priced.
     * @param WC_Product|false $parent    The parent variable product.
     * @return float The preferential price if available, otherwise the original price.
     */
    public static function filter_variation_regular_price( $price, $variation, $parent ) {
        $preferential = self::get_preferential_price( $variation );
        return null !== $preferential ? $preferential : $price;
    }


    /**
     * Adds the current B2B account ID to WooCommerce's variation price cache hash.
     *
     * Ensures that variation price data is cached separately for different B2B accounts.
     *
     * @param array      $hash         The existing variation price cache hash.
     * @param WC_Product $product      The variable product being processed.
     * @param bool       $for_display  Whether prices are being prepared for display.
     * @return array The updated cache hash.
     */
    public static function variation_prices_hash( $hash, $product, $for_display ) {
        $hash['b2bw_account_id'] = self::get_current_account_id();
        return $hash;
    }


    /**
     * Retrieves all B2B accounts, ordered alphabetically by account name.
     *
     * @return array The list of B2B accounts.
     */
    private static function get_accounts() {
        global $wpdb;
        return $wpdb->get_results( 'SELECT id, name FROM ' . $wpdb->prefix . 'b2bw_accounts ORDER BY name ASC' );
    }


    /**
     * Retrieves all pricing rules assigned to a specific B2B account.
     *
     * Orders the rules by rule type and object ID for consistent administration display.
     *
     * @param int $account_id The B2B account ID.
     * @return array The pricing rules assigned to the account.
     */
    private static function get_rules_for_account( $account_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rules_table() . ' WHERE account_id = %d ORDER BY rule_type, object_id', $account_id ) );
    }


    /**
     * Generates a human-readable label for a pricing rule's target object.
     *
     * Returns the category name or product name together with its ID. If the
     * referenced category or product no longer exists, returns a descriptive
     * label indicating the missing object.
     *
     * @param object $rule The pricing rule whose target object should be labelled.
     * @return string The target object's label.
     */
    private static function object_label( $rule ) {
        if ( 'category' === $rule->rule_type ) {
            $term = get_term( (int) $rule->object_id, 'product_cat' );
            return $term && ! is_wp_error( $term ) ? $term->name . ' (ID ' . (int) $term->term_id . ')' : 'Nieistniejąca kategoria (ID ' . (int) $rule->object_id . ')';
        }
        $product = wc_get_product( (int) $rule->object_id );
        return $product ? $product->get_name() . ' (ID ' . (int) $rule->object_id . ')' : 'Nieistniejący produkt (ID ' . (int) $rule->object_id . ')';
    }


    /**
     * Validates and saves a B2B pricing rule submitted from the administration page.
     *
     * Checks permissions and the request nonce, validates the account, rule type,
     * target object, and pricing values, then inserts a new rule or updates an
     * existing rule for the same account, rule type, and object. Clears the rules
     * cache and redirects with a status notice.
     *
     * @return void
     */
    public static function handle_save_rule() {
        self::require_permission();
        check_admin_referer( 'b2bw_save_price_rule' );
        if ( ! class_exists( 'WooCommerce' ) ) {
            self::redirect_notice( 0, 'WooCommerce nie jest aktywne.', 'error' );
        }
        global $wpdb;
        
        $account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
        $type = isset( $_POST['rule_type'] ) ? sanitize_key( wp_unslash( $_POST['rule_type'] ) ) : '';
        $pricing_type = isset( $_POST['pricing_type'] ) ? sanitize_key( wp_unslash( $_POST['pricing_type'] ) ) : '';
        // Dla kategorii ID pochodzi z osobnego selecta; dla produktu z wyszukiwarki WooCommerce.
        if ( 'category' === $type ) {
            $object_id = isset( $_POST['category_object_id'] ) ? absint( $_POST['category_object_id'] ) : 0;
        } else if ('product' === $type) {
            $object_id = isset($_POST['product_object_id']) ? absint($_POST['product_object_id']) : 0;
        } else if ('variation' === $type) {
            $object_id = isset($_POST['variant_object_id']) ? absint($_POST['variant_object_id']) : 0;
        } else {
            $object_id = null;
        }
        $value_raw = isset( $_POST['pricing_value'] ) ? wc_format_decimal( wp_unslash( $_POST['pricing_value'] ) ) : '';
        $accounts_table = $wpdb->prefix . 'b2bw_accounts';
        $account_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$accounts_table} WHERE id = %d", $account_id ) );
        if ( ! $account_exists || ! in_array( $type, array( 'product', 'category', 'variation' ), true ) || ! in_array( $pricing_type, array( 'percent', 'fixed' ), true ) || ! $object_id || '' === $value_raw || ! is_numeric( $value_raw ) ) {
            self::redirect_notice( $account_id, 'Uzupełnij poprawnie wszystkie pola reguły cenowej.', 'error' );
        }
        $value = (float) $value_raw;
        if ( $value < 0 || ( 'percent' === $pricing_type && $value > 100 ) || ( 'fixed' === $pricing_type && 'category' === $type ) ) {
            self::redirect_notice( $account_id, 'Nieprawidłowa wartość. Rabat musi mieścić się w zakresie 0–100%, a cena stała nie jest dostępna dla kategorii.', 'error' );
        }
        if ( 'category' === $type ) {
            $term = get_term( $object_id, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                self::redirect_notice( $account_id, 'Nie znaleziono wskazanej kategorii produktów.', 'error' );
            }
        } else {
            $product = wc_get_product( $object_id );
            if ( ! $product ) {
                self::redirect_notice( $account_id, 'Nie znaleziono wskazanego produktu lub wariantu.', 'error' );
            }
            if ( 'variation' === $type && ! $product->is_type( 'variation' ) ) {
                self::redirect_notice( $account_id, 'Dla reguły wariantu wybierz konkretny wariant produktu.', 'error' );
            }
            if ( 'product' === $type && $product->is_type( 'variation' ) ) {
                self::redirect_notice( $account_id, 'Reguła produktu musi wskazywać produkt nadrzędny, a nie wariant.', 'error' );
            }
            if ( 'product' === $type && $product->is_type( 'variable' ) && 'fixed' === $pricing_type ) {
                self::redirect_notice( $account_id, 'Cena stała dla produktu wariantowego jest niejednoznaczna. Ustaw rabat procentowy dla całego produktu albo cenę stałą dla konkretnego wariantu.', 'error' );
            }
        }
        $table = self::rules_table();
        $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE account_id = %d AND rule_type = %s AND object_id = %d", $account_id, $type, $object_id ) );
        $now = current_time( 'mysql' );
        $data = array( 'account_id' => $account_id, 'rule_type' => $type, 'object_id' => $object_id, 'pricing_type' => $pricing_type, 'pricing_value' => $value, 'updated_at' => $now );
        if ( $existing_id ) {
            $wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), array( '%d', '%s', '%d', '%s', '%f', '%s' ), array( '%d' ) );
        } else {
            $data['created_at'] = $now;
            $wpdb->insert( $table, $data, array( '%d', '%s', '%d', '%s', '%f', '%s', '%s' ) );
        }
        self::$rules_cache = array();
        self::redirect_notice( $account_id, 'Reguła cenowa została zapisana.', 'success' );
    }


    /**
     * Deletes a B2B pricing rule after verifying permissions and the request nonce.
     *
     * Restricts deletion to the specified rule and account, clears the rules cache,
     * and redirects to the administration page with a status notice.
     *
     * @return void
     */
    public static function handle_delete_rule() {
        self::require_permission();
        $rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
        $account_id = isset( $_GET['account_id'] ) ? absint( $_GET['account_id'] ) : 0;
        check_admin_referer( 'b2bw_delete_price_rule_' . $rule_id );
        global $wpdb;
        if ( $rule_id ) {
            $wpdb->delete( self::rules_table(), array( 'id' => $rule_id, 'account_id' => $account_id ), array( '%d', '%d' ) );
        }
        self::$rules_cache = array();
        self::redirect_notice( $account_id, 'Reguła cenowa została usunięta.', 'success' );
    }


    /**
     * Verifies that the current user can manage B2B pricing rules.
     *
     * Terminates execution with an error message if the user lacks the
     * manage_woocommerce capability.
     *
     * @return void
     */
    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nie masz uprawnień do zarządzania cenami B2B.', 'b2b-woocommerce' ) );
        }
    }


    /**
     * Redirects to the B2B pricing administration page with a status notice.
     *
     * Adds the selected account ID, notice type, and message to the redirect URL,
     * then terminates execution.
     *
     * @param int    $account_id The B2B account ID to select after redirection.
     * @param string $message    The notice message to display.
     * @param string $type       The notice type, such as success or error.
     * @return void
     */
    private static function redirect_notice( $account_id, $message, $type ) {
        $args = array( 'page' => self::PAGE_SLUG, 'b2bw_account' => absint( $account_id ), 'b2bw_notice' => $type, 'b2bw_message' => rawurlencode( $message ) );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    
    /**
     * Renders the B2B pricing administration page.
     *
     * Loads the available accounts and pricing rules, validates the selected account,
     * displays status notices, and renders the forms for creating or updating rules
     * and the table of existing rules. Requires the appropriate WooCommerce
     * administration capability.
     *
     * @return void
     */
    public static function render_admin_page() {
        self::require_permission();
        $accounts = self::get_accounts();
        $selected_account = isset( $_GET['b2bw_account'] ) ? absint( $_GET['b2bw_account'] ) : ( ! empty( $accounts ) ? (int) $accounts[0]->id : 0 );
        $selected_exists = false;
        foreach ( $accounts as $account ) {
            if ( (int) $account->id === $selected_account ) { $selected_exists = true; break; }
        }
        if ( ! $selected_exists ) { $selected_account = ! empty( $accounts ) ? (int) $accounts[0]->id : 0; }
        $notice = isset( $_GET['b2bw_notice'] ) ? sanitize_key( $_GET['b2bw_notice'] ) : '';
        $message = (isset( $_GET['b2bw_message'] ) && is_string( $_GET['b2bw_message'] )) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['b2bw_message'] ) ) ) : '';
        ?>
        <div class="wrap">
            <h1>Ceny dla Klientów B2B</h1>
            <?php if ( $message ) : ?><div class="notice <?php echo 'error' === $notice ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
            <p>Reguły cenowe są przypisane do konta B2B. Rabaty procentowe liczone są od ceny regularnej. Cena B2B zastępuje cenę promocyjną.</p>
            <?php if ( empty( $accounts ) ) : ?>
                <p>Najpierw utwórz konto w sekcji Klienci B2B.</p>
            <?php else : ?>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                    <table class="form-table b2bw-form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="b2bw-account-select"><strong>Kontrahent:</strong></label>
                                </th>
                                <td>
                                    <select id="b2bw-account-select" name="b2bw_account" onchange="this.form.submit()">
                                        <?php foreach ( $accounts as $account ) : ?><option value="<?php echo esc_attr( $account->id ); ?>" <?php selected( $selected_account, $account->id ); ?>><?php echo esc_html( $account->name ); ?></option><?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </form>
                <h2>Dodaj lub zaktualizuj regułę</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="b2bw_save_price_rule">
                    <input type="hidden" name="account_id" value="<?php echo esc_attr( $selected_account ); ?>">
                    <?php wp_nonce_field( 'b2bw_save_price_rule' ); ?>
                    <table class="form-table b2bw-form-table" role="presentation">
                        <tr><th scope="row"><label for="b2bw-rule-type">Zakres reguły</label></th><td><select id="b2bw-rule-type" name="rule_type"><option value="product">Produkt (także wariantowy)</option><option value="variation">Konkretny wariant</option><option value="category">Kategoria produktów</option></select></td></tr>
                        <tr class="b2bw-object-target">
                            <th scope="row">
                                <label for="b2bw-object-product">Produkt</label>
                            </th>
                            <td>
                                <select
                                    id="b2bw-object-product"
                                    class="wc-product-search"
                                    name="product_object_id"
                                    data-placeholder="Wyszukaj produkt..."
                                    data-action="b2bw_json_search_products_only"
                                    data-allow_clear="true"
                                    style="width: 400px;"
                                >
                                    <option value=""></option>
                                </select>
                            </td>
                        </tr>

                        <tr class="b2bw-variant-target">
                            <th scope="row">
                                <label for="b2bw-object-variant">Wariant</label>
                            </th>
                            <td>
                                <select
                                    id="b2bw-object-variant"
                                    class="wc-product-search"
                                    name="variant_object_id"
                                    data-placeholder="Wyszukaj wariant..."
                                    data-action="b2bw_json_search_variations_only"
                                    data-allow_clear="true"
                                    style="width: 400px;"
                                >
                                    <option value=""></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="b2bw-category-target"><th scope="row"><label for="b2bw-object-category">Kategoria</label></th><td><?php wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'name' => 'category_object_id', 'id' => 'b2bw-object-category', 'hide_empty' => false, 'show_option_none' => 'Wybierz kategorię', 'option_none_value' => '0', 'hierarchical' => true ) ); ?></td></tr>
                        <tr><th scope="row"><label for="b2bw-pricing-type">Rodzaj ceny</label></th><td><select id="b2bw-pricing-type" name="pricing_type"><option value="percent">Rabat procentowy</option><option value="fixed">Cena stała</option></select></td></tr>
                        <tr><th scope="row"><label for="b2bw-pricing-value">Wartość</label></th><td><input id="b2bw-pricing-value" name="pricing_value" type="number" min="0" max="100" step="0.01" required><p class="description">Rabat: 0–100%. Cena stała: kwota w walucie sklepu. Cena stała jest dostępna dla produktu prostego i konkretnego wariantu. Dla produktu wariantowego użyj rabatu procentowego lub ustaw ceny osobno dla wariantów.</p></td></tr>
                    </table>
                    <?php submit_button( 'Zapisz regułę cenową' ); ?>
                </form>
                <hr><h2>Obowiązujące reguły</h2>
                <?php $rules = self::get_rules_for_account( $selected_account ); ?>
                <table class="widefat striped"><thead><tr><th>Zakres</th><th>Obiekt</th><th>Typ ceny</th><th>Wartość</th><th>Akcje</th></tr></thead><tbody>
                <?php if ( empty( $rules ) ) : ?><tr><td colspan="5">Brak reguł cenowych dla tego kontrahenta.</td></tr><?php else : foreach ( $rules as $rule ) : ?>
                    <tr><td><?php echo esc_html( array( 'product' => 'Produkt', 'variation' => 'Wariant', 'category' => 'Kategoria' )[ $rule->rule_type ] ?? $rule->rule_type ); ?></td><td><?php echo esc_html( self::object_label( $rule ) ); ?></td><td><?php echo 'percent' === $rule->pricing_type ? 'Rabat procentowy' : 'Cena stała'; ?></td><td><?php echo 'percent' === $rule->pricing_type ? esc_html( wc_format_decimal( $rule->pricing_value, 2 ) . '%' ) : wp_kses_post( wc_price( (float) $rule->pricing_value ) ); ?></td><td><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'b2bw_delete_price_rule', 'rule_id' => $rule->id, 'account_id' => $selected_account ), admin_url( 'admin-post.php' ) ), 'b2bw_delete_price_rule_' . $rule->id ) ); ?>" onclick="return confirm('Czy na pewno usunąć tę regułę?');">Usuń</a></td></tr>
                <?php endforeach; endif; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }
}
