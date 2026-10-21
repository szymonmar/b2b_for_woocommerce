<?php
/** Usuwa dane wtyczki podczas jej odinstalowania. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'b2bw_price_rules',
    $wpdb->prefix . 'b2bw_account_emails',
    $wpdb->prefix . 'b2bw_account_domains',
    $wpdb->prefix . 'b2bw_accounts',
);

delete_option( 'b2bw_db_version' );

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
