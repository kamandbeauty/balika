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
        $message=(string)$message;
        $settings=get_option('bon_settings',[]);
        foreach (['bale_bot_token','webhook_secret'] as $key) {
            $secret=$settings[$key]??'';
            if (is_string($secret) && $secret!=='') $message=str_replace(array_unique([$secret,rawurlencode($secret),urlencode($secret)]),'[مقدار محرمانه حذف شد]',$message);
        }
        $message=preg_replace('~(https?://[^\s/]+/bot)[^/\s?]+~i','$1[توکن مخفی شد]',$message);
        $message=preg_replace('~([?&](?:secret|token)=)[^&\s]+~i','$1[مقدار محرمانه حذف شد]',$message);
        return preg_replace('/[0-9]{5,}:[A-Za-z0-9_-]{20,}/','[توکن مخفی شد]',$message);
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
        $rows=$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", $limit ) );
        foreach ((array)$rows as $row) $row->message=self::redact($row->message);
        return $rows;
    }

    public static function clear_logs() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }
}
