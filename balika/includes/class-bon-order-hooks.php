<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس هوک‌های سفارش - گوش دادن به رویدادهای ووکامرس و ارسال اعلان به بله
 */
class Bale_Order_Notifier_Order_Hooks {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_new_order' ], 20, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_new_order' ], 20, 1 );
        add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ], 20, 1 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 20, 4 );
        add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_changed' ], 20, 1 );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_changed' ], 20, 1 );
    }

    /**
     * سفارش جدید ثبت شد
     */
    public function on_new_order( $order_id ) {
        if ( ! bon_get_option( 'notify_new_order', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $order_id = $order->get_id();
        if ( $order->get_meta( '_bon_new_order_notified' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // جلوگیری از ارسال زودهنگام برای سفارش‌های هنوز پیش‌نویس (مثلاً چک‌اوت بلوکی که سفارش را قبل از تکمیل پرداخت می‌سازد)
        if ( in_array( $order->get_status(), [ 'draft', 'auto-draft', 'checkout-draft' ], true ) ) {
            return;
        }

        // بررسی حداقل مبلغ برای ارسال اعلان
        $min_amount = (float) bon_get_option( 'min_order_amount', 0 );
        if ( $min_amount > 0 && (float) $order->get_total() < $min_amount ) {
            Bale_Order_Notifier_Logger::log( 'info', "سفارش #{$order_id} به دلیل کمتر بودن از حداقل مبلغ اعلان نشد." );
            return;
        }

        $message = $this->build_message( 'template_new_order', $order );
        $buttons = bon_get_option( 'inline_buttons_on', true ) ? $this->build_status_buttons( $order_id ) : [];

        $sent = Bale_Order_Notifier_Dispatcher::broadcast( $message, $buttons, $order->get_id() );
        if ( ! empty( $sent ) ) {
            $order->update_meta_data( '_bon_sent_messages', $sent );
            $order->save_meta_data();
        }
        if ( ! empty( $sent ) ) {
            $order->update_meta_data( '_bon_new_order_notified', 1 );
            $order->save_meta_data();
        }
    }

    /**
     * وضعیت سفارش تغییر کرد
     */
    public function on_status_changed( $order_id, $old_status, $new_status, $order ) {
        $enabled_statuses = bon_get_option( 'enabled_statuses', [ 'processing', 'completed', 'cancelled', 'refunded', 'failed' ] );
        if ( ! in_array( $new_status, (array) $enabled_statuses, true ) ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $min_amount = (float) bon_get_option( 'min_order_amount', 0 );
        if ( $min_amount > 0 && (float) $order->get_total() < $min_amount ) {
            return;
        }

        $message = $this->build_message( 'template_status_change', $order );
        $buttons = bon_get_option( 'inline_buttons_on', true ) ? $this->build_status_buttons( $order_id ) : [];

        $sent = Bale_Order_Notifier_Dispatcher::broadcast( $message, $buttons, $order->get_id() );
        if ( ! empty( $sent ) ) {
            $order->update_meta_data( '_bon_sent_messages', $sent );
            $order->save_meta_data();
        }
    }

    /**
     * هشدار موجودی کم محصول
     */
    public function on_stock_changed( $product ) {
        if ( ! bon_get_option( 'notify_low_stock', false ) ) {
            return;
        }
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $qty = $product->get_stock_quantity();
        if ( $qty === null ) {
            return;
        }

        $threshold = (int) bon_get_option( 'low_stock_threshold', 3 );
        if ( $qty > $threshold ) {
            return;
        }

        // جلوگیری از اسپم: فقط یک بار در هر ۶ ساعت به ازای هر محصول
        $transient_key = 'bon_low_stock_' . $product->get_id();
        if ( get_transient( $transient_key ) ) {
            return;
        }
        set_transient( $transient_key, 1, 6 * HOUR_IN_SECONDS );

        $template = bon_get_option( 'template_low_stock', "⚠️ هشدار موجودی کم\n\nمحصول: {product_name}\nموجودی فعلی: {stock_quantity}" );
        $message  = str_replace(
            [ '{product_name}', '{stock_quantity}' ],
            [ $product->get_name(), $qty ],
            $template
        );

        Bale_Order_Notifier_Dispatcher::broadcast( $message );
    }

    /**
     * ساخت پیام از قالب و اطلاعات سفارش
     */
    private function build_message( $template_key, $order ) {
        $default_templates = [
            'template_new_order'     => "🛍 سفارش جدید #{order_id}\n\n👤 مشتری: {customer_name}\n📞 تماس: {customer_phone}\n💰 مبلغ: {order_total}\n📦 محصولات:\n{order_items}\n\n📍 آدرس: {shipping_address}",
            'template_status_change' => "🔄 وضعیت سفارش #{order_id} تغییر کرد\n\nوضعیت جدید: {order_status}\n👤 مشتری: {customer_name}\n💰 مبلغ: {order_total}",
        ];

        $template = bon_get_option( $template_key, $default_templates[ $template_key ] ?? '' );

        $items_text = '';
        foreach ( $order->get_items() as $item ) {
            $items_text .= '• ' . $item->get_name() . ' × ' . $item->get_quantity() . "\n";
        }


        $replacements = [
            '{order_id}'         => $order->get_order_number(),
            '{customer_name}'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: 'مهمان',
            '{customer_phone}'   => $order->get_billing_phone() ?: '-',
            '{order_total}'      => bon_money( $order->get_total(), $order->get_currency() ),
            '{order_items}'      => trim( $items_text ) ?: '-',
            '{shipping_address}' => trim( $order->get_formatted_shipping_address() ? wp_strip_all_tags( $order->get_formatted_shipping_address() ) : $order->get_billing_address_1() ) ?: '-',
            '{order_status}'     => wc_get_order_status_name( $order->get_status() ),
            '{payment_method}'   => $order->get_payment_method_title() ?: '-',
            '{order_date}'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '-',
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * ساخت دکمه‌های اینلاین برای تغییر سریع وضعیت سفارش
     */
    private function build_status_buttons( $order_id ) {
        return [
            [
                [ 'text' => '✅ تایید سفارش', 'callback_data' => 'bon_status:processing:' . $order_id ],
                [ 'text' => '📦 ارسال شد', 'callback_data' => 'bon_status:completed:' . $order_id ],
            ],
            [
                [ 'text' => '❌ لغو سفارش', 'callback_data' => 'bon_status:cancelled:' . $order_id ],
                [ 'text' => '🔗 مشاهده در ووکامرس', 'url' => admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ],
            ],
        ];
    }

}
