<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس رویدادهای عمومی سایت که به Order/Customer/Cart/Form مرتبط نیستند:
 * ثبت‌نام کاربر جدید، دیدگاه (نظر) جدید روی محصول، تایید دیدگاه، و یادداشت سفارش برای مشتری
 */
class Bale_Order_Notifier_Site_Hooks {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'user_register', [ $this, 'on_new_user_registered' ], 10, 1 );
        add_action( 'comment_post', [ $this, 'on_new_product_review' ], 10, 3 );
        add_action( 'transition_comment_status', [ $this, 'on_review_status_changed' ], 10, 3 );
        add_action( 'woocommerce_new_customer_note', [ $this, 'on_customer_note_added' ], 10, 1 );
    }

    /**
     * اعلان ثبت‌نام کاربر جدید به ادمین
     */
    public function on_new_user_registered( $user_id ) {
        if ( ! bon_get_option( 'notify_new_user_registration', false ) ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $message = "👤 کاربر جدید ثبت‌نام کرد\n\nنام کاربری: {$user->user_login}\nایمیل: {$user->user_email}";
        Bale_Order_Notifier_Dispatcher::broadcast( $message );
        Bale_Order_Notifier_Logger::log( 'success', "اعلان ثبت‌نام کاربر #{$user_id} ارسال شد." );
    }

    /**
     * اعلان دیدگاه (نظر) جدید روی محصول به ادمین
     */
    public function on_new_product_review( $comment_id, $approved, $commentdata ) {
        if ( ! bon_get_option( 'notify_new_review', false ) ) {
            return;
        }
        $comment = get_comment( $comment_id );
        if ( ! $comment || empty( $comment->comment_post_ID ) ) {
            return;
        }
        $post = get_post( $comment->comment_post_ID );
        if ( ! $post || $post->post_type !== 'product' ) {
            return; // فقط دیدگاه محصولات، نه نوشته‌های عادی
        }

        $rating_meta = get_comment_meta( $comment_id, 'rating', true );
        $rating_text = $rating_meta ? " ({$rating_meta}/۵ ⭐)" : '';

        $message  = "💬 دیدگاه جدید روی محصول{$rating_text}\n\n";
        $message .= "🛍 محصول: {$post->post_title}\n";
        $message .= "👤 نویسنده: {$comment->comment_author}\n";
        $message .= '📝 متن: ' . wp_trim_words( $comment->comment_content, 30 );

        Bale_Order_Notifier_Dispatcher::broadcast( $message );
        Bale_Order_Notifier_Logger::log( 'success', "اعلان دیدگاه جدید (#{$comment_id}) برای ادمین ارسال شد." );
    }

    /**
     * اعلان تایید دیدگاه به خود کاربر (در صورت اتصال حساب او به بله)
     */
    public function on_review_status_changed( $new_status, $old_status, $comment ) {
        if ( ! bon_get_option( 'notify_review_approved', false ) ) {
            return;
        }
        if ( $new_status !== 'approved' || $old_status === 'approved' ) {
            return;
        }
        $post = get_post( $comment->comment_post_ID );
        if ( ! $post || $post->post_type !== 'product' ) {
            return;
        }
        if ( empty( $comment->comment_author_email ) || ! class_exists( 'Bale_Order_Notifier_Customer_Store' ) ) {
            return;
        }

        // تلاش برای پیدا کردن شماره تلفن مرتبط با این کامنت از طریق کاربر لاگین‌شده (در صورت وجود)
        $phone = '';
        if ( ! empty( $comment->user_id ) ) {
            $phone = get_user_meta( $comment->user_id, 'billing_phone', true );
        }
        if ( empty( $phone ) ) {
            return; // بدون شماره تلفن، امکان پیدا کردن حساب متصل نیست
        }

        $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $phone );
        if ( empty( $links ) ) {
            return;
        }

        $message = "✅ دیدگاه شما روی محصول «{$post->post_title}» تایید و منتشر شد. ممنون از نظرتون 🌷";
        foreach ( $links as $link ) {
            $api = new Bale_Order_Notifier_API( $link['platform'] );
            $api->send_message( $link['chat_id'], $message );
        }
        Bale_Order_Notifier_Logger::log( 'success', 'اعلان تایید دیدگاه برای مشتری ارسال شد.' );
    }

    /**
     * ارسال یادداشت سفارش (که ادمین برای مشتری نوشته) به خود مشتری در بله
     */
    public function on_customer_note_added( $args ) {
        if ( ! bon_get_option( 'notify_customer_order_note', false ) ) {
            return;
        }
        $order_id = $args['order_id'] ?? null;
        $note     = $args['customer_note'] ?? '';
        if ( empty( $order_id ) || empty( $note ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $order->get_billing_phone() );
        if ( empty( $links ) ) {
            return;
        }

        $message = "💬 یادداشت جدید درباره سفارش #{$order->get_order_number()}:\n\n{$note}";
        foreach ( $links as $link ) {
            $api = new Bale_Order_Notifier_API( $link['platform'] );
            $api->send_message( $link['chat_id'], $message, [], true, $order->get_id(), 'customer_note' );
        }
        Bale_Order_Notifier_Logger::log( 'success', "یادداشت سفارش #{$order_id} برای مشتری ارسال شد." );
    }
}
