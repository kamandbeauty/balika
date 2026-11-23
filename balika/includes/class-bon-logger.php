<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس لاگ‌گیری - ثبت تاریخچه پیام‌های ارسالی و خطاها در جدول اختصاصی
 */
class Bale_Order_Notifier_Logger {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bon_logs';
    }

    public static function create_table() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            log_time DATETIME NOT NULL,
            type VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * پنهان‌سازی الگوی توکن بات (مثل 123456:AaBbCc...) در متن پیام‌های لاگ
     */
    private static function redact( $message ) {
        return preg_replace( '/\b\d{6,10}:[A-Za-z0-9_-]{20,}\b/', '[توکن مخفی شد]', (string) $message );
    }

    public static function log( $type, $message ) {
        global $wpdb;
        $message = self::redact( $message );
        $wpdb->insert(
            self::table_name(),
            [
                'log_time' => current_time( 'mysql' ),
                'type'     => sanitize_text_field( $type ),
                'message'  => sanitize_textarea_field( $message ),
            ]
        );
        // نگه‌داشتن فقط ۵۰۰ رکورد آخر برای جلوگیری از سنگین شدن دیتابیس
        $wpdb->query( "DELETE FROM " . self::table_name() . " WHERE id NOT IN (SELECT id FROM (SELECT id FROM " . self::table_name() . " ORDER BY id DESC LIMIT 500) tmp)" );
    }

    public static function get_logs( $limit = 100 ) {
        global $wpdb;
        $table_name = self::table_name();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", $limit ) );
    }

    public static function clear_logs() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }
}
