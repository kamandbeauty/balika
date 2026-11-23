<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس صف ارسال - در صورت شکست ارسال پیام (قطعی شبکه، خطای موقت سرور و...)،
 * پیام در جدول صف ذخیره شده و توسط کرون هر ۵ دقیقه با backoff نمایی دوباره تلاش می‌شود.
 * پس از ۵ تلاش ناموفق، پیام به‌عنوان dead-letter علامت‌گذاری و دیگر تلاش نمی‌شود.
 */
class Bale_Order_Notifier_Queue {

    const MAX_ATTEMPTS = 5;

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'bon_process_queue_event', [ $this, 'process_queue' ] );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bon_queue';
    }

    public static function create_table() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            platform VARCHAR(20) NOT NULL,
            method VARCHAR(50) NOT NULL,
            payload LONGTEXT NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message_type VARCHAR(64) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status_next (status, next_attempt),
            KEY order_history (order_id, id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * افزودن پیام ناموفق به صف
     */
    public static function enqueue( $platform, $method, $params, $order_id = 0, $message_type = '' ) {
        if ( $platform !== 'bale' ) return false;
        global $wpdb;
        return false !== $wpdb->insert( self::table_name(), [
            'order_id'     => (int) $order_id,
            'message_type' => substr( (string) $message_type, 0, 64 ),
            'platform'     => $platform,
            'method'       => $method,
            'payload'      => wp_json_encode( $params ),
            'attempts'     => 0,
            'next_attempt' => current_time( 'mysql' ),
            'status'       => 'pending',
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * پردازش صف - اجرا می‌شود توسط کرون هر ۵ دقیقه
     */
    public function process_queue() {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );
        // MySQL advisory lock is connection-scoped and released even after a fatal error.
        $lock = 'bon_queue_' . md5( $table );
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return;
        try {
        update_option('bon_queue_last_started',time(),false);
        $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('sent','dead-letter','cancelled') AND created_at < %s", $cutoff ) );

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE platform = 'bale' AND status = %s AND next_attempt <= %s ORDER BY id ASC LIMIT 20",
            'pending', $now
        ) );

        if ( empty( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if (strpos($item->message_type ?? '', 'support_admin_') === 0 && (!class_exists('Bale_Order_Notifier_Support') || !Bale_Order_Notifier_Support::can_deliver_admin($item->message_type,json_decode($item->payload,true)))) {
                $wpdb->update($table, ['status'=>'paused','last_error'=>'مقصد خصوصی مدیر دیگر مجاز نیست یا پشتیبانی/گفت‌وگو فعال نیست.'], ['id'=>$item->id]);
                continue;
            }
            if (strpos($item->message_type ?? '', 'support_admin_') !== 0 && strpos($item->message_type ?? '', 'support_') === 0 && (!class_exists('Bale_Order_Notifier_Support') || !Bale_Order_Notifier_Support::can_deliver((int)substr($item->message_type,8)))) {
                $wpdb->update($table, ['status'=>'paused','last_error'=>'پشتیبانی/بله غیرفعال یا گفت‌وگو بسته/نامعتبر است.'], ['id'=>$item->id]);
                continue;
            }
            if (strpos($item->message_type ?? '', 'channel_') === 0 && class_exists('Bale_Order_Notifier_Channels') && !Bale_Order_Notifier_Channels::can_deliver((int) substr($item->message_type, 8))) {
                $wpdb->update($table, ['status'=>'paused', 'last_error'=>'ماژول، مقصد یا محتوای کانال مجاز نیست.'], ['id'=>$item->id]);
                continue;
            }
            $params = json_decode( $item->payload, true );
            $api    = new Bale_Order_Notifier_API( $item->platform );

            // فراخوانی مستقیم متد خصوصی request از طریق متد عمومی موجود بر اساس method
            $chunks = self::text_chunks(is_array($params) ? (string) ($params['text'] ?? '') : '');
            $part = is_array($params) ? max(0, (int) ($params['_bon_chunk'] ?? 0)) : 0;
            $send_params = $params;
            if (is_array($send_params) && $item->method === 'sendMessage') {
                $send_params['text'] = $chunks[$part] ?? '';
                if ($part < count($chunks) - 1) unset($send_params['reply_markup']);
            }
            $result = $this->call_method( $api, $item->method, $send_params );
            if ($result && $part < count($chunks) - 1) {
                $params['_bon_chunk'] = $part + 1;
                $wpdb->update($table, ['payload' => wp_json_encode($params), 'attempts' => 0, 'last_error' => '', 'next_attempt' => current_time('mysql')], ['id' => $item->id]);
                continue;
            }

            if ( $result ) {
                $wpdb->update( $table, [ 'status' => 'sent', 'attempts' => (int) $item->attempts + 1, 'sent_at' => current_time('mysql'), 'last_error' => '' ], [ 'id' => $item->id ] );
                Bale_Order_Notifier_Logger::log( 'success', "پیام صف (#{$item->id}) با موفقیت مجدداً ارسال شد." );
            } else {
                $attempts = (int) $item->attempts + 1;
                $wpdb->update( $table, [ 'last_error' => method_exists($api, 'get_last_error') ? $api->get_last_error() : 'ارسال ناموفق' ], [ 'id' => $item->id ] );
                if ( $attempts >= self::MAX_ATTEMPTS ) {
                    $wpdb->update( $table, [ 'attempts' => $attempts, 'status' => 'dead-letter' ], [ 'id' => $item->id ] );
                    Bale_Order_Notifier_Logger::log( 'error', "پیام صف (#{$item->id}) پس از {$attempts} تلاش ناموفق، بایگانی شد." );
                } else {
                    // تاخیر نمایی: ۵، ۱۰، ۲۰، ۴۰ دقیقه
                    $delay_minutes = 5 * pow( 2, $attempts - 1 );
                    $next          = date( 'Y-m-d H:i:s', strtotime( $now ) + ( $delay_minutes * MINUTE_IN_SECONDS ) );
                    $wpdb->update( $table, [ 'attempts' => $attempts, 'next_attempt' => $next ], [ 'id' => $item->id ] );
                }
            }
        }
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function text_chunks($text) {
        if ($text === '') return [''];
        if (!preg_match_all('/.{1,3000}/us', $text, $matches)) return [$text];
        return $matches[0];
    }

    private function call_method( $api, $method, $params ) {
        if ( ! is_array( $params ) ) return false;
        switch ( $method ) {
            case 'sendMessage':
                $buttons = [];
                if ( ! empty( $params['reply_markup'] ) ) {
                    $decoded = json_decode( $params['reply_markup'], true );
                    $buttons = $decoded['inline_keyboard'] ?? [];
                }
                return $api->send_message( $params['chat_id'], $params['text'], $buttons, false );
            default:
                return false;
        }
    }

    public static function retry_failed() {
        global $wpdb;
        return $wpdb->update( self::table_name(), [ 'status' => 'pending', 'attempts' => 0, 'next_attempt' => current_time('mysql') ], [ 'status' => 'dead-letter', 'platform' => 'bale' ] );
    }

    public static function get_queue_stats() {
        global $wpdb;
        $table = self::table_name();
        return [
            'pending'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE platform = 'bale' AND status = 'pending'" ),
            'dead_letter' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE platform = 'bale' AND status = 'dead-letter'" ),
            'sent'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE platform = 'bale' AND status = 'sent'" ),
        ];
    }
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
