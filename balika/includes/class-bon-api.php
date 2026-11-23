<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس ارتباط با API پیام‌رسان‌ها (بله)
 * فقط بله پشتیبانی می‌شود؛ مقاصد قدیمی دیگر رد می‌شوند.
 */
class Bale_Order_Notifier_API {

    const BASE_URLS = [
        'bale'     => 'https://tapi.bale.ai/bot',
    ];

    private $token;
    private $platform;
    private $last_error = '';

    public function get_last_error() { return $this->last_error; }

    public function __construct( $platform = 'bale', $token = null ) {
        $this->platform = $platform;
        $this->token    = $token ?: bon_get_option( $this->platform . '_bot_token' );
    }

    public function get_platform() {
        return $this->platform;
    }

    /**
     * ارسال درخواست به API - همراه با ثبت در صف تلاش مجدد در صورت شکست
     */
    private function request( $method, $params = [], $queue_on_fail = false ) {
        if ( $this->platform !== 'bale' ) return false;
        $this->last_error = '';
        if ( empty( $this->token ) ) {
            $this->last_error = 'توکن بات تنظیم نشده است.';
            Bale_Order_Notifier_Logger::log( 'error', $this->platform_label() . ': توکن بات تنظیم نشده است.' );
            return false;
        }

        $url = self::BASE_URLS[ $this->platform ] . $this->token . '/' . $method;

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'body'    => $params,
        ] );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            $this->last_error = 'خطای اتصال به بله؛ لاگ‌ها را بررسی کنید.';
            Bale_Order_Notifier_Logger::log( 'error', $this->platform_label() . ' - خطای اتصال: ' . $err );
            if ( $queue_on_fail ) {
                return Bale_Order_Notifier_Queue::enqueue( $this->platform, $method, $params ) ? [ 'queued' => true ] : false;
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['ok'] ) ) {
            $desc = $body['description'] ?? 'پاسخ نامعتبر از سرور';
            $this->last_error = 'خطای API بله؛ HTTP ' . (int) $code;
            Bale_Order_Notifier_Logger::log( 'error', $this->platform_label() . " - خطای API ({$method}): {$desc}" );
            if ( $queue_on_fail ) {
                return Bale_Order_Notifier_Queue::enqueue( $this->platform, $method, $params ) ? [ 'queued' => true ] : false;
            }
            return false;
        }

        return $body['result'] ?? true;
    }

    private function platform_label() {
        return 'بله';
    }

    /**
     * ارسال پیام متنی، با امکان افزودن دکمه‌های اینلاین
     */
    public function send_message( $chat_id, $text, $inline_buttons = [], $queue_on_fail = true, $order_id = 0, $message_type = '' ) {
        if ( $this->platform !== 'bale' ) return false;
        $params = [
            'chat_id' => $chat_id,
            'text'    => $text,
        ];

        if ( ! empty( $inline_buttons ) ) {
            $params['reply_markup'] = wp_json_encode( [ 'inline_keyboard' => $inline_buttons ] );
        }

        if ( $queue_on_fail && $order_id > 0 ) {
            return Bale_Order_Notifier_Queue::enqueue( $this->platform, 'sendMessage', $params, $order_id, $message_type ) ? [ 'queued' => true ] : false;
        }
        // Long texts are sent progressively by the queue, never silently truncated.
        if ($queue_on_fail && preg_match('/^.{3001}/us', $text)) {
            return Bale_Order_Notifier_Queue::enqueue($this->platform, 'sendMessage', $params, $order_id, $message_type) ? ['queued' => true] : false;
        }
        // Store transactional sends before contacting external services.
        if ( $queue_on_fail && ( doing_action( 'woocommerce_checkout_order_processed' ) || doing_action( 'woocommerce_store_api_checkout_order_processed' ) || doing_action( 'woocommerce_new_order' ) || doing_action( 'woocommerce_order_status_changed' ) ) ) {
            return Bale_Order_Notifier_Queue::enqueue( $this->platform, 'sendMessage', $params ) ? [ 'queued' => true ] : false;
        }
        $result = $this->request( 'sendMessage', $params, $queue_on_fail );

        if ( $result && empty( $result['queued'] ) ) {
            Bale_Order_Notifier_Logger::log( 'success', $this->platform_label() . " - پیام به {$chat_id} ارسال شد." );
        }

        return $result;
    }

    public function edit_message( $chat_id, $message_id, $text, $inline_buttons = [] ) {
        $params = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
        ];
        if ( ! empty( $inline_buttons ) ) {
            $params['reply_markup'] = wp_json_encode( [ 'inline_keyboard' => $inline_buttons ] );
        }
        return $this->request( 'editMessageText', $params );
    }

    public function answer_callback_query( $callback_query_id, $text = '' ) {
        return $this->request( 'answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text'              => $text,
        ] );
    }

    public function set_webhook( $url ) {
        return $this->request( 'setWebhook', [ 'url' => $url ] );
    }

    /**
     * حذف وبهوک - طبق مستندات بله، برای غیرفعال‌سازی وبهوک باید یک رشته خالی به setWebhook ارسال شود
     * (بله برخلاف تلگرام متد جداگانه‌ای برای این کار ندارد)
     */
    public function delete_webhook() {
        return $this->set_webhook( '' );
    }

    public function get_me() {
        return $this->request( 'getMe' );
    }
}

/**
 * تابع کمکی برای خواندن تنظیمات پلاگین
 */
function bon_get_option( $key, $default = '' ) {
    $options = get_option( 'bon_settings', [] );
    return $options[ $key ] ?? $default;
}

/**
 * لیست پلتفرم‌های فعال (بله)
 */
function bon_get_enabled_platforms() {
    $platforms = [];
    if ( bon_get_option( 'bale_enabled', true ) && bon_get_option( 'bale_bot_token' ) ) {
        $platforms[] = 'bale';
    }

    return $platforms;
}

/**
 * دریافت یا تولید توکن مخفی وبهوک - برای جلوگیری از جعل درخواست به آدرس وبهوک
 * (بدون این توکن، هرکسی که آدرس وبهوک را حدس بزند می‌تواند خودش را جای ادمین جا بزند)
 */
function bon_get_webhook_secret() {
    $secret = bon_get_option( 'webhook_secret' );
    if ( empty( $secret ) ) {
        $secret  = wp_generate_password( 32, false, false );
        $options = get_option( 'bon_settings', [] );
        $options['webhook_secret'] = $secret;
        update_option( 'bon_settings', $options );
    }
    return $secret;
}

/**
 * تولید متن پررنگ به فرمت خاص بله: فاصله + ستاره + متن + ستاره + فاصله
 * توجه: بله برخلاف تلگرام از parse_mode پشتیبانی نمی‌کند و فقط از این نحو خاص استفاده می‌کند.
 */
function bon_bale_bold( $text ) {
    return ' *' . trim( (string) $text ) . '* ';
}
function bon_mask_token( $token ) {
    if ( empty( $token ) ) return '';
    $len = strlen( $token );
    if ( $len <= 8 ) return str_repeat( '*', $len );
    return substr( $token, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $token, -4 );
}

/** Use the actual WooCommerce currency; a label must never imply a conversion. */
function bon_money( $amount, $currency = '' ) {
    $currency = $currency ?: get_woocommerce_currency();
    return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'currency' => $currency ] ) ), ENT_QUOTES, 'UTF-8' ) );
}
