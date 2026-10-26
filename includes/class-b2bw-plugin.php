<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class B2BW_Plugin {
    const PAGE_SLUG = 'b2bw-accounts';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_post_b2bw_save_account', array( __CLASS__, 'handle_save_account' ) );
        add_action( 'admin_post_b2bw_delete_account', array( __CLASS__, 'handle_delete_account' ) );
        if ( class_exists( 'B2BW_Pricing' ) ) {
            B2BW_Pricing::init();
        }
    }

    private static function accounts_table() {
        global $wpdb;
        return $wpdb->prefix . 'b2bw_accounts';
    }

    private static function domains_table() {
        global $wpdb;
        return $wpdb->prefix . 'b2bw_account_domains';
    }

    private static function emails_table() {
        global $wpdb;
        return $wpdb->prefix . 'b2bw_account_emails';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $accounts = self::accounts_table();
        $domains  = self::domains_table();
        $emails   = self::emails_table();

        dbDelta( "CREATE TABLE {$accounts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$domains} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            domain varchar(190) NOT NULL,
            PRIMARY KEY  (id),
            KEY account_id (account_id),
            KEY domain (domain)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$emails} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            email varchar(190) NOT NULL,
            PRIMARY KEY  (id),
            KEY account_id (account_id),
            KEY email (email)
        ) {$charset_collate};" );

        if ( class_exists( 'B2BW_Pricing' ) ) {
            B2BW_Pricing::activate();
        }
    }

    public static function register_admin_menu() {
        add_menu_page(
            __( 'Klienci B2B', 'b2b-woocommerce' ),
            __( 'Klienci B2B', 'b2b-woocommerce' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_admin_page' ),
            'dashicons-groups',
            56
        );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nie masz uprawnień do zarządzania klientami B2B.', 'b2b-woocommerce' ) );
        }
    }

    private static function normalize_domains( $raw ) {
        $items = preg_split( '/[\r\n,;]+/', (string) $raw );
        $domains = array();
        foreach ( $items as $item ) {
            $domain = strtolower( trim( $item ) );
            $domain = preg_replace( '#^https?://#', '', $domain );
            $domain = preg_replace( '#/.*$#', '', $domain );
            $domain = ltrim( $domain, '@' );
            if ( '' === $domain ) {
                continue;
            }
            // Dopuszczamy domenę bez protokołu i ścieżki, bez wildcardów.
            if ( ! preg_match( '/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain ) ) {
                return new WP_Error( 'invalid_domain', sprintf( 'Nieprawidłowa domena: %s', $item ) );
            }
            $domains[] = $domain;
        }
        return array_values( array_unique( $domains ) );
    }

    private static function normalize_emails( $raw ) {
        $items = preg_split( '/[\r\n,;]+/', (string) $raw );
        $emails = array();
        foreach ( $items as $item ) {
            $email = strtolower( trim( $item ) );
            if ( '' === $email ) {
                continue;
            }
            if ( ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', sprintf( 'Nieprawidłowy adres e-mail: %s', $item ) );
            }
            $emails[] = $email;
        }
        return array_values( array_unique( $emails ) );
    }

    public static function handle_save_account() {
        self::require_permission();
        check_admin_referer( 'b2bw_save_account' );
        global $wpdb;

        $id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
        $name = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';
        $raw_domains = isset( $_POST['domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['domains'] ) ) : '';
        $raw_emails = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';

        if ( '' === $name ) {
            self::redirect_with_notice( 'error', 'Podaj nazwę konta B2B.' );
        }

        $domains = self::normalize_domains( $raw_domains );
        if ( is_wp_error( $domains ) ) {
            self::redirect_with_notice( 'error', $domains->get_error_message(), $id );
        }
        $emails = self::normalize_emails( $raw_emails );
        if ( is_wp_error( $emails ) ) {
            self::redirect_with_notice( 'error', $emails->get_error_message(), $id );
        }

        $accounts_table = self::accounts_table();
        $now = current_time( 'mysql' );
        if ( $id > 0 ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$accounts_table} WHERE id = %d", $id ) );
            if ( ! $exists ) {
                self::redirect_with_notice( 'error', 'Nie znaleziono wskazanego konta B2B.' );
            }
            $wpdb->update( $accounts_table, array( 'name' => $name, 'updated_at' => $now ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $accounts_table, array( 'name' => $name, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s' ) );
            $id = (int) $wpdb->insert_id;
        }

        if ( ! $id ) {
            self::redirect_with_notice( 'error', 'Nie udało się zapisać konta B2B.' );
        }

        $domains_table = self::domains_table();
        $emails_table = self::emails_table();
        $wpdb->delete( $domains_table, array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $emails_table, array( 'account_id' => $id ), array( '%d' ) );

        foreach ( $domains as $domain ) {
            $wpdb->insert( $domains_table, array( 'account_id' => $id, 'domain' => $domain ), array( '%d', '%s' ) );
        }
        foreach ( $emails as $email ) {
            $wpdb->insert( $emails_table, array( 'account_id' => $id, 'email' => $email ), array( '%d', '%s' ) );
        }

        self::redirect_with_notice( 'success', 'Konto B2B zostało zapisane.' );
    }

    public static function handle_delete_account() {
        self::require_permission();
        $id = isset( $_GET['account_id'] ) ? absint( $_GET['account_id'] ) : 0;
        check_admin_referer( 'b2bw_delete_account_' . $id );
        global $wpdb;
        if ( $id > 0 ) {
            $wpdb->delete( self::domains_table(), array( 'account_id' => $id ), array( '%d' ) );
            $wpdb->delete( self::emails_table(), array( 'account_id' => $id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'b2bw_price_rules', array( 'account_id' => $id ), array( '%d' ) );
            $wpdb->delete( self::accounts_table(), array( 'id' => $id ), array( '%d' ) );
        }
        self::redirect_with_notice( 'success', 'Konto B2B zostało usunięte.' );
    }

    private static function redirect_with_notice( $type, $message, $edit_id = 0 ) {
        $args = array( 'page' => self::PAGE_SLUG, 'b2bw_notice' => $type, 'b2bw_message' => rawurlencode( $message ) );
        if ( $edit_id ) {
            $args['edit'] = absint( $edit_id );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function get_account( $id ) {
        global $wpdb;
        $account = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::accounts_table() . ' WHERE id = %d', $id ) );
        if ( ! $account ) {
            return null;
        }
        $account->domains = $wpdb->get_col( $wpdb->prepare( 'SELECT domain FROM ' . self::domains_table() . ' WHERE account_id = %d ORDER BY domain ASC', $id ) );
        $account->emails = $wpdb->get_col( $wpdb->prepare( 'SELECT email FROM ' . self::emails_table() . ' WHERE account_id = %d ORDER BY email ASC', $id ) );
        return $account;
    }

    public static function render_admin_page() {
        self::require_permission();
        global $wpdb;
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $account = $edit_id ? self::get_account( $edit_id ) : null;
        $accounts = $wpdb->get_results( 'SELECT * FROM ' . self::accounts_table() . ' ORDER BY name ASC' );
        $notice = isset( $_GET['b2bw_notice'] ) ? sanitize_key( $_GET['b2bw_notice'] ) : '';
        $message = (isset( $_GET['b2bw_message'] ) && is_string( $_GET['b2bw_message'] )) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['b2bw_message'] ) ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Klienci B2B', 'b2b-woocommerce' ); ?></h1>
            <?php if ( $message ) : ?>
                <div class="notice <?php echo 'error' === $notice ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>
            <p>Utwórz konto B2B i określ, które adresy e-mail mogą być do niego przypisane. Wpisy w polu domen nie wymagają znaku @ ani protokołu.</p>

            <h2><?php echo $account ? esc_html__( 'Edytuj konto', 'b2b-woocommerce' ) : esc_html__( 'Dodaj konto', 'b2b-woocommerce' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="b2bw_save_account">
                <input type="hidden" name="account_id" value="<?php echo $account ? esc_attr( $account->id ) : '0'; ?>">
                <?php wp_nonce_field( 'b2bw_save_account' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="b2bw-account-name">Nazwa konta</label></th>
                        <td><input id="b2bw-account-name" name="account_name" type="text" class="regular-text" maxlength="190" required value="<?php echo $account ? esc_attr( $account->name ) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="b2bw-domains">Domeny</label></th>
                        <td><textarea id="b2bw-domains" name="domains" class="large-text" rows="4" placeholder="firma.pl&#10;firma.com"><?php echo $account ? esc_textarea( implode( "\n", $account->domains ) ) : ''; ?></textarea><p class="description">Opcjonalnie. Jeden wpis w wierszu, można też rozdzielać przecinkami lub średnikami. Adres z domeny zostanie dopasowany na podstawie części po znaku @.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="b2bw-emails">Wybrane adresy e-mail</label></th>
                        <td><textarea id="b2bw-emails" name="emails" class="large-text" rows="4" placeholder="zakupy@firma.pl&#10;biuro@firma.pl"><?php echo $account ? esc_textarea( implode( "\n", $account->emails ) ) : ''; ?></textarea><p class="description">Opcjonalnie. Możesz wskazać konkretne adresy niezależnie od listy domen.</p></td>
                    </tr>
                </table>
                <?php submit_button( $account ? 'Zapisz zmiany' : 'Dodaj konto B2B' ); ?>
                <?php if ( $account ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Anuluj edycję</a><?php endif; ?>
            </form>

            <hr>
            <h2>Istniejące konta</h2>
            <table class="widefat striped">
                <thead><tr><th>Nazwa</th><th>Domeny</th><th>Adresy e-mail</th><th>Akcje</th></tr></thead>
                <tbody>
                <?php if ( empty( $accounts ) ) : ?>
                    <tr><td colspan="4">Nie dodano jeszcze żadnych kont B2B.</td></tr>
                <?php else : foreach ( $accounts as $row ) : $full = self::get_account( $row->id ); ?>
                    <tr>
                        <td><strong><?php echo esc_html( $full->name ); ?></strong></td>
                        <td><?php echo $full->domains ? esc_html( implode( ', ', $full->domains ) ) : '—'; ?></td>
                        <td><?php echo $full->emails ? esc_html( implode( ', ', $full->emails ) ) : '—'; ?></td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit' => $full->id ), admin_url( 'admin.php' ) ) ); ?>">Edytuj</a>
                            | <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'b2bw_delete_account', 'account_id' => $full->id ), admin_url( 'admin-post.php' ) ), 'b2bw_delete_account_' . $full->id ) ); ?>" onclick="return confirm('Czy na pewno usunąć to konto B2B?');">Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
