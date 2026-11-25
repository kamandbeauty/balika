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
        $scoped=self::order_table();
        dbDelta("CREATE TABLE {$scoped} (
            order_id BIGINT UNSIGNED NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            linked_at DATETIME NOT NULL,
            owner_hash VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (order_id),
            KEY chat_id (chat_id)
        ) {$charset_collate};");
    }

    public static function order_table() { global $wpdb; return $wpdb->prefix.'bon_order_links'; }
    public static function ensure_owner_schema() {
        if (get_option('bon_order_owner_schema',0)==='1') return;
        self::create_table();
        global $wpdb;
        if ($wpdb->get_var("SHOW COLUMNS FROM ".self::order_table()." LIKE 'owner_hash'")==='owner_hash') update_option('bon_order_owner_schema','1',false);
    }
    /** No raw customer identity or order secret is stored in the grant. */
    public static function owner_hash($order) {
        if (!$order || !method_exists($order,'get_customer_id') || !method_exists($order,'get_billing_email') || !method_exists($order,'get_order_key') || !method_exists($order,'get_meta')) return '';
        $identity=json_encode([(int)$order->get_customer_id(),strtolower(trim((string)$order->get_billing_email())),(string)$order->get_order_key(),(string)$order->get_meta('_bon_owner_epoch')]);
        return $identity===false?'':hash('sha256',$identity);
    }
    /** Persisted with the order: reverting an owner later must not revive an old grant/token. */
    public static function owner_will_save($order) {
        if (!$order || !method_exists($order,'get_changes') || !method_exists($order,'get_type') || $order->get_type()!=='shop_order') return;
        $changes=$order->get_changes();
        if (array_key_exists('customer_id',$changes) || array_key_exists('order_key',$changes) || (isset($changes['billing']) && array_key_exists('email',$changes['billing']))) {
            $order->update_meta_data('_bon_owner_epoch',wp_generate_password(32,false,false));
        }
    }
    public static function link_order($order_id,$chat_id,$expected_owner='') {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$chat_id)) return false;
        global $wpdb;$lock='bon_queue_'.md5(Bale_Order_Notifier_Queue::table_name());
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return false;
        try {
            $owner=self::owner_hash(wc_get_order($order_id));
            if ($owner==='' || ($expected_owner!=='' && (!is_string($expected_owner) || !hash_equals($owner,$expected_owner)))) return false;
            $result=$wpdb->replace(self::order_table(),['order_id'=>(int)$order_id,'chat_id'=>(string)$chat_id,'linked_at'=>current_time('mysql'),'owner_hash'=>$owner]);
            return $result!==false && self::owns_order($order_id,'bale',$chat_id);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function get_links_for_order($order_id) {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT chat_id, owner_hash FROM '.self::order_table().' WHERE order_id=%d',(int)$order_id));
        $owner=self::owner_hash(wc_get_order($order_id));
        if (!$row || !is_string($row->owner_hash??null) || !preg_match('/^[a-f0-9]{64}$/D',$row->owner_hash) || $owner==='' || !hash_equals($owner,$row->owner_hash)) return [];
        return $row->chat_id ? [['platform'=>'bale','chat_id'=>(string)$row->chat_id]] : [];
    }
    public static function owns_order($order_id,$platform,$chat_id) {
        foreach (self::get_links_for_order($order_id) as $link) if ($platform==='bale' && $link['chat_id']===(string)$chat_id) return true;
        return false;
    }
    public static function orders_for_chat($chat_id) {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare('SELECT order_id FROM '.self::order_table().' WHERE chat_id=%s ORDER BY order_id DESC LIMIT 5',(string)$chat_id));
        $orders=[];foreach ((array)$ids as $id) { $order=wc_get_order($id);if($order && self::owns_order($id,'bale',$chat_id))$orders[]=$order; }return $orders;
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
        // Legacy phone mappings are NOT evidence of order ownership. Reconnect each order.
        return [];
    }

    public static function is_linked( $phone ) {
        return ! empty( self::get_links_for_phone( $phone ) );
    }

    /**
     * دریافت همه اتصال‌های ثبت‌شده (برای پیام انبوه)
     * @return array [['phone'=>..,'platform'=>..,'chat_id'=>..], ...]
     */
    public static function unlink_chat( $platform, $chat_id ) {
        if ($platform!=='bale') return false;
        global $wpdb;
        $table=Bale_Order_Notifier_Queue::table_name();$lock='bon_queue_'.md5($table);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return false;
        try {
            // A worker cannot retain a pre-unlink snapshot while this operation succeeds.
            $deleted=$wpdb->delete(self::table_name(),['platform'=>$platform,'chat_id'=>(string)$chat_id]);
            if ($deleted===false) return false;
            $scoped=$wpdb->delete(self::order_table(),['chat_id'=>(string)$chat_id]);
            if ($scoped===false) return false;
            $deleted += $scoped;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT id, payload, message_type FROM {$table} WHERE platform=%s AND status IN ('pending','dead-letter','paused')",$platform));
            if (!empty($wpdb->last_error)) return false;
            foreach ((array)$rows as $row) {
                $type=$row->message_type??'';
                // Do not erase a manager's reports, channel or support history with customer /stop.
                if ($type!=='' && !in_array($type,['customer_order','customer_note','customer_status','tracking','payment_reminder'],true) && strpos($type,'broadcast_')!==0) continue;
                $payload=json_decode($row->payload,true);
                if ((string)($payload['chat_id']??'')===(string)$chat_id && $wpdb->update($table,['status'=>'cancelled','last_error'=>'اتصال مشتری قطع شده است.'],['id'=>$row->id])===false) return false;
            }
            return $deleted;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    public static function owns_phone( $phone, $platform, $chat_id ) {
        foreach ( self::get_links_for_phone( $phone ) as $link ) {
            if ( $link['platform'] === $platform && (string) $link['chat_id'] === (string) $chat_id ) return true;
        }
        return false;
    }

    public static function get_all_links() {
        // Order access does not imply consent to phone-wide broadcasts.
        return [];
    }

    public static function count_all_links() {
        return 0; // Legacy marketing audience is quarantined until explicit consent migration.
    }
}
