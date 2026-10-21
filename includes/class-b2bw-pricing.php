<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Preferencyjne ceny B2B i ich obsługa w WooCommerce. */
final class B2BW_Pricing {
    const PAGE_SLUG = 'b2bw-pricing';

    private static $account_cache = array();
    private static $rules_cache = array();

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_b2bw_save_price_rule', array( __CLASS__, 'handle_save_rule' ) );
        add_action( 'admin_post_b2bw_delete_price_rule', array( __CLASS__, 'handle_delete_rule' ) );

        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), 99, 2 );
        add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_price' ), 99, 3 );
        add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'filter_variation_regular_price' ), 99, 3 );
        add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_prices_hash' ), 99, 3 );
    }

    private static function rules_table() {
        global $wpdb;
        return $wpdb->prefix . 'b2bw_price_rules';
    }

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

    public static function maybe_upgrade() {
        if ( version_compare( (string) get_option( 'b2bw_db_version', '0.1.0' ), '0.2.0', '<' ) ) {
            self::activate();
        }
    }

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

    private static function get_account_rules( $account_id ) {
        global $wpdb;
        if ( ! isset( self::$rules_cache[ $account_id ] ) ) {
            self::$rules_cache[ $account_id ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rules_table() . ' WHERE account_id = %d', $account_id ) );
        }
        return self::$rules_cache[ $account_id ];
    }

    private static function find_rule( $rules, $type, $object_id ) {
        foreach ( $rules as $rule ) {
            if ( $type === $rule->rule_type && (int) $object_id === (int) $rule->object_id ) {
                return $rule;
            }
        }
        return null;
    }

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

    /** Zwraca cenę B2B albo null, jeśli nie ma pasującej reguły. */
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

    public static function filter_price( $price, $product ) {
        $preferential = self::get_preferential_price( $product );
        return null !== $preferential ? (string) $preferential : $price;
    }

    public static function filter_regular_price( $price, $product ) {
        $preferential = self::get_preferential_price( $product );
        return null !== $preferential ? (string) $preferential : $price;
    }

    public static function filter_is_on_sale( $on_sale, $product ) {
        return null !== self::get_preferential_price( $product ) ? false : $on_sale;
    }

    public static function filter_variation_price( $price, $variation, $parent ) {
        $preferential = self::get_preferential_price( $variation );
        return null !== $preferential ? $preferential : $price;
    }

    public static function filter_variation_regular_price( $price, $variation, $parent ) {
        $preferential = self::get_preferential_price( $variation );
        return null !== $preferential ? $preferential : $price;
    }

    public static function variation_prices_hash( $hash, $product, $for_display ) {
        $hash['b2bw_account_id'] = self::get_current_account_id();
        return $hash;
    }

    private static function get_accounts() {
        global $wpdb;
        return $wpdb->get_results( 'SELECT id, name FROM ' . $wpdb->prefix . 'b2bw_accounts ORDER BY name ASC' );
    }

    private static function get_rules_for_account( $account_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rules_table() . ' WHERE account_id = %d ORDER BY rule_type, object_id', $account_id ) );
    }

    private static function object_label( $rule ) {
        if ( 'category' === $rule->rule_type ) {
            $term = get_term( (int) $rule->object_id, 'product_cat' );
            return $term && ! is_wp_error( $term ) ? $term->name . ' (ID ' . (int) $term->term_id . ')' : 'Nieistniejąca kategoria (ID ' . (int) $rule->object_id . ')';
        }
        $product = wc_get_product( (int) $rule->object_id );
        return $product ? $product->get_name() . ' (ID ' . (int) $rule->object_id . ')' : 'Nieistniejący produkt (ID ' . (int) $rule->object_id . ')';
    }

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

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nie masz uprawnień do zarządzania cenami B2B.', 'b2b-woocommerce' ) );
        }
    }

    private static function redirect_notice( $account_id, $message, $type ) {
        $args = array( 'page' => self::PAGE_SLUG, 'b2bw_account' => absint( $account_id ), 'b2bw_notice' => $type, 'b2bw_message' => rawurlencode( $message ) );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

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
        $message = isset( $_GET['b2bw_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['b2bw_message'] ) ) ) : '';
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
