<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس ذخیره‌سازی اتصال مشتری - نگهداری نگاشت «شماره موبایل → chat_id»
 * تا بتوان بعد از ثبت سفارش یا صدور کد رهگیری، مستقیماً به خود مشتری در بله پیام داد.
 */
class Bale_Order_Notifier_Customer_Store {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bon_customer_links';
    }

    public static function create_table() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(20) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            linked_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone_platform (phone, platform)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * نرمال‌سازی شماره موبایل ایران به فرمت بین‌المللی یکسان با پیش‌شماره +۹۸
     * مثال: 09121234567 ، 989121234567 ، +989121234567 → +989121234567
     */
    public static function normalize_phone( $phone ) {
        $phone = strtr( (string) $phone, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ) );
        $digits = preg_replace( '/\D/', '', $phone );
        if ( substr( $digits, 0, 4 ) === '0098' ) $digits = substr( $digits, 2 );
        // حذف کد کشور 98 از ابتدا در صورت وجود (تا بعداً یکسان دوباره اضافه شود)
        if ( strlen( $digits ) > 10 && substr( $digits, 0, 2 ) === '98' ) {
            $digits = substr( $digits, 2 );
        }
        // حذف صفر ابتدایی
        if ( strlen( $digits ) === 11 && substr( $digits, 0, 1 ) === '0' ) {
            $digits = substr( $digits, 1 );
        }
        if ( ! preg_match( '/^9[0-9]{9}$/', $digits ) ) {
            return '';
        }
        return '+98' . $digits; // فرمت نهایی همیشه +98912xxxxxxx
    }

    /**
     * آیا رشته ورودی شبیه شماره موبایل ایرانی است؟
     */
    public static function looks_like_phone( $text ) {
        $normalized = self::normalize_phone( $text );
        return (bool) preg_match( '/^\+989\d{9}$/', $normalized );
    }

    /**
     * ثبت یا به‌روزرسانی اتصال مشتری
     */
    public static function link( $phone, $platform, $chat_id ) {
        if ( $platform !== 'bale' ) return false;
        global $wpdb;
        $normalized = self::normalize_phone( $phone );
        if ( empty( $normalized ) ) {
            return false;
        }
        $table = self::table_name();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE phone = %s AND platform = %s",
            $normalized, $platform
        ) );

        if ( $existing ) {
            $result = $wpdb->update( $table, [ 'chat_id' => $chat_id, 'linked_at' => current_time( 'mysql' ) ], [ 'id' => $existing ] );
        } else {
            $result = $wpdb->insert( $table, [
                'phone'     => $normalized,
                'platform'  => $platform,
                'chat_id'   => $chat_id,
                'linked_at' => current_time( 'mysql' ),
            ] );
        }
        return $result !== false;
    }

    /**
     * دریافت لیست مقاصد (پلتفرم + chat_id) متصل به یک شماره تلفن
     * @return array [['platform' => 'bale', 'chat_id' => '123'], ...]
     */
    public static function get_links_for_phone( $phone ) {
        global $wpdb;
        $normalized = self::normalize_phone( $phone );
        if ( empty( $normalized ) ) {
            return [];
        }
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT platform, chat_id FROM {$table} WHERE phone = %s AND platform = 'bale'",
            $normalized
        ), ARRAY_A );

        return $rows ?: [];
    }

    public static function is_linked( $phone ) {
        return ! empty( self::get_links_for_phone( $phone ) );
    }

    /**
     * دریافت همه اتصال‌های ثبت‌شده (برای پیام انبوه)
     * @return array [['phone'=>..,'platform'=>..,'chat_id'=>..], ...]
     */
    public static function unlink_chat( $platform, $chat_id ) {
        global $wpdb;
        // Cancel already queued messages too, not just future notifications.
        $table = Bale_Order_Notifier_Queue::table_name();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM {$table} WHERE platform = %s AND status IN ('pending', 'dead-letter')", $platform ) );
        foreach ( $rows as $row ) {
            $payload = json_decode( $row->payload, true );
            if ( (string) ( $payload['chat_id'] ?? '' ) === (string) $chat_id ) {
                $wpdb->delete( $table, [ 'id' => $row->id ] );
            }
        }
        return $wpdb->delete( self::table_name(), [ 'platform' => $platform, 'chat_id' => (string) $chat_id ] );
    }

    public static function owns_phone( $phone, $platform, $chat_id ) {
        foreach ( self::get_links_for_phone( $phone ) as $link ) {
            if ( $link['platform'] === $platform && (string) $link['chat_id'] === (string) $chat_id ) return true;
        }
        return false;
    }

    public static function get_all_links() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( "SELECT phone, platform, chat_id FROM {$table} WHERE platform = 'bale'", ARRAY_A ) ?: [];
    }

    public static function count_all_links() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE platform = 'bale'" );
    }
}
