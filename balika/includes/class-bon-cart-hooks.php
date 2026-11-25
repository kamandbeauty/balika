<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس سبد خرید رها شده:
 * هر تغییر در سبد خرید (افزودن/حذف محصول) یک عکس‌فوری ذخیره می‌کند.
 * یک کرون دوره‌ای، سبدهایی که مدتی بدون تغییر مانده‌اند و به سفارش تبدیل نشده‌اند را
 * «رها شده» علامت می‌زند و به ادمین (و اختیاری، به خود مشتری در صورت اتصال حساب) اطلاع می‌دهد.
 */
class Bale_Order_Notifier_Cart_Hooks {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_cart_updated', [ $this, 'snapshot_cart' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'mark_recovered' ], 5, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'mark_recovered' ], 5, 1 );
        add_action( 'bon_check_abandoned_carts_event', [ $this, 'check_abandoned_carts' ] );
        add_action( 'bon_cleanup_cart_snapshots_event', [ $this, 'cleanup_old_snapshots' ] );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bon_cart_snapshots';
    }

    public static function create_table() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(190) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            phone VARCHAR(20) NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY identifier (identifier)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * شناسه یکتای سبد خرید فعلی: شناسه کاربر (در صورت لاگین) یا شناسه نشست ووکامرس (مهمان)
     */
    private function get_current_identifier() {
        if ( is_user_logged_in() ) {
            return 'user_' . get_current_user_id();
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_id = WC()->session->get_customer_id();
            if ( $session_id ) {
                return 'guest_' . $session_id;
            }
        }
        return null;
    }

    /**
     * ذخیره عکس‌فوری سبد خرید فعلی هنگام هر تغییر (افزودن/کم‌کردن/حذف محصول)
     */
    public function snapshot_cart() {
        if ( ! bon_get_option( 'abandoned_cart_enabled', false ) ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $identifier = $this->get_current_identifier();
        if ( empty( $identifier ) ) {
            return;
        }

        global $wpdb;
        $table = self::table_name();

        // سبد خالی شده: رکورد قبلی (در صورت وجود) حذف شود تا اعلان اشتباه ارسال نشود
        if ( WC()->cart->is_empty() ) {
            $wpdb->delete( $table, [ 'identifier' => $identifier ] );
            return;
        }

        $items = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            $items[] = [
                'name' => $product ? $product->get_name() : ( $cart_item['product_id'] ?? 'محصول' ),
                'qty'  => $cart_item['quantity'] ?? 1,
            ];
        }

        $phone   = null;
        $user_id = null;
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $phone   = get_user_meta( $user_id, 'billing_phone', true ) ?: null;
        }

        $data = [
            'identifier'    => $identifier,
            'user_id'       => $user_id,
            'phone'         => $phone,
            'cart_contents' => wp_json_encode( $items ),
            'cart_total'    => (float) WC()->cart->get_total( 'edit' ),
            'status'        => 'active',
            'updated_at'    => current_time( 'mysql' ),
        ];

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE identifier = %s", $identifier ) );
        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * وقتی سبد به سفارش تبدیل شد، عکس‌فوری مربوطه به «بازیابی‌شده» تغییر کند تا اعلان رهاشدگی ارسال نشود
     */
    public function mark_recovered( $order_id ) {
        $identifier = $this->get_current_identifier();
        if ( empty( $identifier ) ) {
            return;
        }
        global $wpdb;
        $wpdb->update( self::table_name(), [ 'status' => 'recovered' ], [ 'identifier' => $identifier ] );
    }

    /**
     * بررسی دوره‌ای (کرون) سبدهای رهاشده و ارسال اعلان
     */
    public function check_abandoned_carts() {
        if ( ! bon_get_option( 'abandoned_cart_enabled', false ) ) {
            return;
        }

        global $wpdb;
        $table            = self::table_name();
        $threshold_minutes = (int) bon_get_option( 'abandoned_cart_threshold', 30 );
        $cutoff           = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $threshold_minutes * MINUTE_IN_SECONDS ) );

        $carts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND updated_at <= %s LIMIT 20",
            'active', $cutoff
        ) );

        if ( empty( $carts ) ) {
            return;
        }


        foreach ( $carts as $cart ) {
            $items = json_decode( $cart->cart_contents, true ) ?: [];
            $items_text = '';
            foreach ( $items as $item ) {
                $items_text .= '• ' . $item['name'] . ' × ' . $item['qty'] . "\n";
            }

            $customer_label = $cart->phone ? $cart->phone : ( $cart->user_id ? 'کاربر عضو #' . $cart->user_id : 'مهمان' );

            $message  = "🛒 سبد خرید رها شده\n\n";
            $message .= "👤 مشتری: {$customer_label}\n";
            $message .= '💰 ارزش سبد: ' . bon_money( $cart->cart_total ) . "\n";
            $message .= "📦 اقلام:\n" . trim( $items_text );

            $accepted = ! empty( Bale_Order_Notifier_Dispatcher::broadcast( $message ) );

            // در صورت فعال بودن یادآوری مستقیم به مشتری و اتصال حساب او، پیام یادآوری هم ارسال شود
            if ( bon_get_option( 'abandoned_cart_remind_customer', false ) && $cart->phone ) {
                $links = class_exists( 'Bale_Order_Notifier_Customer_Store' ) ? Bale_Order_Notifier_Customer_Store::get_links_for_phone( $cart->phone ) : [];
                if ( ! empty( $links ) ) {
                    $reminder = bon_get_option( 'template_cart_reminder', 'سلام 🌸 سبد خریدتون رو کامل نکردید! اگه هنوز مایلید، همین الان می‌تونید ادامه بدید.' );
                    foreach ( $links as $link ) {
                        $api = new Bale_Order_Notifier_API( $link['platform'] );
                        $accepted = (bool) $api->send_message( $link['chat_id'], $reminder ) || $accepted;
                    }
                }
            }

            if ( ! $accepted ) continue;
            $wpdb->update( $table, [ 'status' => 'notified' ], [ 'id' => $cart->id ] );
            Bale_Order_Notifier_Logger::log( 'success', "اعلان سبد خرید رها شده (#{$cart->id}) ارسال شد." );
        }
    }

    /**
     * پاک‌سازی عکس‌فوری‌های قدیمی (بازیابی‌شده یا اطلاع‌رسانی‌شده) برای جلوگیری از سنگین شدن جدول
     */
    public function cleanup_old_snapshots() {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at <= %s", $cutoff ) );
    }
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
