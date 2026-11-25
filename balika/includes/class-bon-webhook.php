<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس وبهوک - دریافت آپدیت از بله
 *
 * دو نوع کاربر کاملاً متفاوت با این بات صحبت می‌کنند:
 * ۱) ادمین (chat_id هایی که در تنظیمات پلتفرم ثبت شده‌اند) → دسترسی به گزارش‌ها و دکمه‌های مدیریتی
 * ۲) مشتری (هرکس دیگری) → فقط امکان اتصال حساب و دریافت کد رهگیری سفارش خودش، هیچ گزارش یا داده‌ای از سایت نمی‌بیند
 */
class Bale_Order_Notifier_Webhook {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_handle_webhook' ] );
    }

    public function maybe_handle_webhook() {
        if ( ! isset( $_GET[ BON_WEBHOOK_QUERY_VAR ] ) ) {
            return;
        }

        if ( ! $this->is_valid_webhook_request() ) {
            Bale_Order_Notifier_Logger::log( 'error', 'درخواست وبهوک با توکن مخفی نامعتبر یا بدون توکن رد شد.' );
            status_header( 403 );
            wp_send_json_error( [ 'ok' => false, 'error' => 'invalid secret' ], 403 );
            exit;
        }

        if ( isset( $_GET['platform'] ) && $_GET['platform'] !== 'bale' ) {
            wp_send_json_error( [ 'error' => 'unsupported platform' ], 400 );
            return;
        }
        $platform = 'bale';

        $raw_input = file_get_contents( 'php://input' );
        $update    = json_decode( $raw_input, true );

        if ( empty( $update ) ) {
            wp_send_json_success( [ 'ok' => true ] );
            return;
        }

        $dedup_key = '';
        if ( isset( $update['update_id'] ) ) {
            global $wpdb;
            $dedup_key = 'bon_update_' . hash( 'sha256', $platform . ':' . $update['update_id'] );
            $lock = substr( $dedup_key, 0, 64 );
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
                wp_send_json_error( [ 'error' => 'busy' ], 503 );
                return;
            }
            register_shutdown_function( function () use ( $wpdb, $lock ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            } );
            if ( get_transient( $dedup_key ) ) {
                wp_send_json_success( [ 'ok' => true ] );
                return;
            }
        }
        if ( isset( $update['message']['chat']['id'] ) ) {
            $this->handle_incoming_message( $platform, $update['message'] );
        }

        if ( isset( $update['callback_query'] ) ) {
            $this->handle_callback_query( $platform, $update['callback_query'] );
        }

        if ( $dedup_key ) set_transient( $dedup_key, 1, DAY_IN_SECONDS );
        wp_send_json_success( [ 'ok' => true ] );
        exit;
    }

    /**
     * بررسی می‌کند آیا درخواست دریافتی حاوی توکن مخفی صحیح در پارامتر secret است یا نه
     */
    private function is_valid_webhook_request() {
        $expected_secret = bon_get_webhook_secret();
        $provided_secret  = isset( $_GET['secret'] ) && is_string( $_GET['secret'] ) ? sanitize_text_field( $_GET['secret'] ) : '';
        return ! empty( $expected_secret ) && hash_equals( $expected_secret, $provided_secret );
    }

    /**
     * آیا این chat_id متعلق به یکی از ادمین‌های تنظیم‌شده در پنل است؟
     * تمام دستورات گزارش‌گیری و مدیریتی فقط برای این لیست مجاز است.
     */
    private function is_admin_chat( $platform, $chat_id ) {
        $admin_ids   = array_merge( (array) bon_get_option( $platform . '_chat_ids', [] ), (array) bon_get_option( $platform . '_admin_user_ids', [] ) );
        $chat_id_str = (string) $chat_id;
        if ( ! ctype_digit( $chat_id_str ) || $chat_id_str === '0' ) return false;
        foreach ( $admin_ids as $id ) {
            if ( trim( (string) $id ) === $chat_id_str ) {
                return true;
            }
        }
        return false;
    }

    /**
     * پردازش پیام متنی دریافتی - مسیردهی جداگانه برای ادمین و مشتری
     */
    private function handle_incoming_message( $platform, $message ) {
        $chat_id = $message['chat']['id'];
        $name    = $message['chat']['first_name'] ?? ( $message['from']['first_name'] ?? '' );
        $text    = trim( $message['text'] ?? '' );

        if (preg_match('/^\/stop@\S+$/', $text)) { $text='/stop'; $message['text']=$text; }

        if (class_exists('Bale_Order_Notifier_Admin_Link') && Bale_Order_Notifier_Admin_Link::incoming($message)) return;
        $is_admin = $this->is_admin_chat( $platform, $message['from']['id'] ?? '' );
        Bale_Order_Notifier_Logger::log( 'info', "پیام دریافتی ({$platform}) از chat_id: {$chat_id} ({$name}) - " . ( $is_admin ? 'ادمین' : 'مشتری' ) );

        if (class_exists('Bale_Order_Notifier_Support') && Bale_Order_Notifier_Support::incoming($message)) return;

        // Account binding and phone lookups must never run in a group chat.
        if ( ( strpos( $text, 'connect_' ) !== false || Bale_Order_Notifier_Customer_Store::looks_like_phone( $text ) || in_array($text, ['/stop', '/orders'], true) ) && (string) ( $message['from']['id'] ?? '' ) !== (string) $chat_id ) {
            ( new Bale_Order_Notifier_API( $platform ) )->send_message( $chat_id, 'برای اتصال یا پیگیری سفارش، در گفت‌وگوی خصوصی بات پیام دهید.',[],false );
            return;
        }
        if ( $text === '/stop' ) {
            $unlinked=Bale_Order_Notifier_Customer_Store::unlink_chat( $platform, $chat_id );
            ( new Bale_Order_Notifier_API( $platform ) )->send_reply( $chat_id, $unlinked===false?'قطع اتصال کامل نشد یا ارسال در حال پردازش است؛ دوباره تلاش کنید.':'اتصال مشتری قطع شد؛ پیام قبلاً ارسال‌شده قابل پس‌گرفتن نیست.' );
            return;
        }
        if ( preg_match( '/^\/start(?:@\S+)?\s+connect_([A-Za-z0-9]{40})$/', $text, $m ) ) {
            $key = 'bon_connect_' . hash( 'sha256', $m[1] );
            $grant = get_transient( $key );
            if (is_array($grant) && !empty($grant['order_id']) && is_string($grant['owner_hash']??null) && preg_match('/^[a-f0-9]{64}$/D',$grant['owner_hash'])) {
                global $wpdb;
                // Atomic consumption: only one request may use this bearer token.
                $used = $wpdb->delete( $wpdb->options, [ 'option_name' => '_transient_' . $key ] );
                if ( wp_using_ext_object_cache() ) {
                    $used = wp_cache_add( $key . '_used', 1, 'bon', DAY_IN_SECONDS );
                }
                delete_transient( $key );
                if ( $used ) {
                    $this->handle_customer_start_link( $platform, $chat_id, (int) $grant['order_id'], $grant['owner_hash'] );
                    return;
                }
            }
            ( new Bale_Order_Notifier_API( $platform ) )->send_reply( $chat_id, 'لینک منقضی یا استفاده شده است؛ صفحه سفارش را دوباره باز کنید.' );
            return;
        }
        if ( preg_match( '/^\/start.*order[0-9]+$/', $text ) ) {
            ( new Bale_Order_Notifier_API( $platform ) )->send_reply( $chat_id, 'برای امنیت شما لینک قدیمی غیرفعال شده است. از صفحه سفارش لینک جدید بگیرید.' );
            return;
        }

        // --- ارسال شماره تلفن توسط کاربر: جست‌وجوی سفارش و پاسخ با کد رهگیری/وضعیت ---
        if ( class_exists( 'Bale_Order_Notifier_Customer_Store' ) && Bale_Order_Notifier_Customer_Store::looks_like_phone( $text ) ) {
            if ( class_exists( 'Bale_Order_Notifier_Customer_Hooks' ) ) {
                Bale_Order_Notifier_Customer_Hooks::instance()->handle_phone_message( $platform, $chat_id, $text );
            }
            return;
        }

        if (class_exists('Bale_Order_Notifier_Commerce')) {
            $commerce_message = $message;
            if (!$is_admin && $text === '/start') $commerce_message['text'] = '/shop';
            if (Bale_Order_Notifier_Commerce::bot_message($commerce_message)) return;
        }

        if ( $text === '/orders' ) {
            $this->customer_orders($chat_id);
            return;
        }
        if ( $text === '/support' ) {
            $this->customer_help($chat_id);
            return;
        }

        // --- دستورات مدیریتی: فقط برای ادمین ---
        if ( $is_admin && in_array( $text, [ '/reports', '/گزارش', '/گزارشات', '/start', '/help', '/راهنما' ], true ) ) {
            $this->send_report_menu( $platform, $chat_id );
            return;
        }

        // Private-chat fallback: never pretend free text reaches a human operator.
        if ( ! $is_admin && (string) ($message['from']['id'] ?? '') === (string) $chat_id ) {
            $this->customer_help($chat_id);
        }
    }

    private function customer_help($chat_id) {
        $key = 'bon_help_' . md5((string) $chat_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 30);
        $url = esc_url_raw(bon_get_option('support_url'), ['https']);
        $buttons = $url ? [[['text' => 'تماس با پشتیبانی', 'url' => $url]]] : [];
        (new Bale_Order_Notifier_API('bale'))->send_reply($chat_id,
            "برای اتصال از لینک صفحه سفارش، حساب کاربری یا ایمیل سفارش استفاده کنید.\n/shop — فروشگاه (در صورت فعال بودن)\n/orders — سفارش‌های من و رهگیری\n/support — پشتیبانی\n/stop — قطع اتصال\nبرای گفت‌وگو با اپراتور /support را بفرستید؛ انتقال پیام فقط در گفت‌وگوی پشتیبانی فعال انجام می‌شود." . ($url ? '' : "\nبرای پشتیبانی به صفحه تماس فروشگاه مراجعه کنید."), $buttons);
    }

    private function customer_orders($chat_id) {
        $key = 'bon_orders_' . md5((string) $chat_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 60);
        $orders=Bale_Order_Notifier_Customer_Store::orders_for_chat($chat_id);
        $grants=[];
        $text = "آخرین سفارش‌های حساب متصل شما:\n";
        foreach (array_slice($orders, 0, 5) as $order) {
            $grants[$order->get_id()]=Bale_Order_Notifier_Customer_Store::owner_hash($order);
            $text .= '#' . $order->get_order_number() . ' — ' . wc_get_order_status_name($order->get_status()) . "\n";
            if ($order->get_meta('_bon_tracking_code')) $text .= 'کد رهگیری: ' . $order->get_meta('_bon_tracking_code') . "\n";
        }
        if (!$orders) $text = 'سفارشی برای حساب متصل پیدا نشد. ابتدا از لینک امن سفارش یا حساب کاربری سایت متصل شوید.';
        (new Bale_Order_Notifier_API('bale'))->send_reply($chat_id,$text,[], $grants?['scope'=>'orders','orders'=>$grants]:['scope'=>'public']);
    }

    /**
     * پردازش دیپ‌لینک اتصال مشتری: /start order{id}
     * شماره تلفن سفارش را استخراج و به chat_id متصل می‌کند، سپس بلافاصله جزئیات همان سفارش را می‌فرستد.
     */
    private function handle_customer_start_link( $platform, $chat_id, $order_id, $owner_hash ) {
        $api   = new Bale_Order_Notifier_API( $platform );
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            $api->send_reply( $chat_id, 'سفارش یافت نشد. لطفاً شماره موبایل خود را ارسال کنید تا حساب شما متصل شود.' );
            return;
        }

        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) {
            $api->send_reply( $chat_id, 'شماره تماس این سفارش ثبت نشده. لطفاً شماره موبایل خود را همینجا ارسال کنید.' );
            return;
        }

        if (!Bale_Order_Notifier_Customer_Store::link_order($order_id,$chat_id,$owner_hash)) {
            $api->send_reply($chat_id, 'ذخیره اتصال ناموفق بود؛ از صفحه سفارش لینک تازه بگیرید و دوباره تلاش کنید.');
            return;
        }
        $api->send_reply( $chat_id, '✅ فقط همین سفارش متصل شد. برای سفارش‌های دیگر از لینک همان سفارش استفاده کنید.' );

        // ارسال فوری جزئیات همین سفارش که از طریق آن اتصال برقرار شد
        if ( class_exists( 'Bale_Order_Notifier_Customer_Hooks' ) ) {
            Bale_Order_Notifier_Customer_Hooks::instance()->send_order_confirmation_to_customer( $order );
        }

        Bale_Order_Notifier_Logger::log( 'success', "مشتری سفارش #{$order_id} حساب {$platform} خود را متصل کرد." );
    }

    /**
     * ارسال منوی دکمه‌های شیشه‌ای گزارش‌گیری زنده (فقط برای ادمین فراخوانی می‌شود)
     */
    private function send_report_menu( $platform, $chat_id ) {
        $api  = new Bale_Order_Notifier_API( $platform );
        $rows = [];
        $row  = [];
        $i    = 0;
        foreach ( Bale_Order_Notifier_Reports::get_report_types() as $key => $label ) {
            $row[] = [ 'text' => $label, 'callback_data' => 'bon_report:' . $key ];
            $i++;
            if ( $i % 2 === 0 ) {
                $rows[] = $row;
                $row    = [];
            }
        }
        if ( ! empty( $row ) ) {
            $rows[] = $row;
        }

        $rows[]=[['text'=>'پنل مدیریت فروشگاه','url'=>admin_url('admin.php?page=balika')]];
        foreach (['daily_report_enabled'=>'فروش روزانه','weekly_report_enabled'=>'گزارش هفتگی و پرفروش‌ها','auto_stock_reports'=>'موجودی همراه روزانه'] as $key=>$label) {
            $on=(bool)bon_get_option($key,false);
            $rows[]=[['text'=>($on?'✅ روشن: ':'⛔ خاموش: ').$label.' — '.($on?'خاموش شود':'روشن شود'),'callback_data'=>'bon_toggle:'.$key.':'.($on?'0':'1')]];
        }
        $menu_text="📋 کدام گزارش را می‌خواهید؟\nروی یکی از دکمه‌های زیر بزنید:";
        if (preg_match('/^[1-9][0-9]*$/D',(string)$chat_id)) $api->send_reply($chat_id,$menu_text,$rows,['scope'=>'admin']);
        else $api->send_message($chat_id,$menu_text,$rows,false);
    }

    /**
     * پردازش کلیک روی دکمه‌های اینلاین - همه این دکمه‌ها (تغییر وضعیت سفارش، گزارش‌گیری)
     * مدیریتی هستند و فقط برای ادمین مجازند، حتی اگر داده callback_data به‌نحوی به غیر ادمین برسد.
     */
    private function handle_callback_query( $platform, $callback_query ) {
        $data       = $callback_query['data'] ?? '';
        $chat_id    = $callback_query['message']['chat']['id'] ?? null;
        $message_id = $callback_query['message']['message_id'] ?? null;
        $cb_id      = $callback_query['id'] ?? null;

        $api = new Bale_Order_Notifier_API( $platform );

        if ( ! $this->is_admin_chat( $platform, $callback_query['from']['id'] ?? '' ) ) {
            $api->answer_callback_query( $cb_id, '⛔ این بخش فقط برای مدیر فروشگاه در دسترس است.' );
            Bale_Order_Notifier_Logger::log( 'error', "تلاش دسترسی غیرمجاز به دکمه مدیریتی از chat_id: {$chat_id}" );
            return;
        }

        if (strpos($data,'bon_toggle:')===0) {
            if ((string)$chat_id!==(string)($callback_query['from']['id']??'') || !in_array('bale',bon_get_enabled_platforms(),true)) { $api->answer_callback_query($cb_id,'تنظیم گزارش فقط در گفت‌وگوی خصوصی و با بله فعال مجاز است.');return; }
            $parts=explode(':',$data);
            if (count($parts)!==3 || !in_array($parts[1],['daily_report_enabled','weekly_report_enabled','auto_stock_reports'],true) || !in_array($parts[2],['0','1'],true)) return;
            $options=get_option('bon_settings',[]);$options[$parts[1]]=$parts[2]==='1';
            update_option('bon_settings',$options);
            if (get_option('bon_settings',[])!==$options) { $api->answer_callback_query($cb_id,'ذخیره ناموفق بود.');return; }
            $api->answer_callback_query($cb_id,'تنظیم ارسال خودکار ذخیره شد؛ پیام‌های قبلاً ارسال‌شده یا در صف پس گرفته نمی‌شوند.');
            $this->send_report_menu($platform,$chat_id);return;
        }

        if (!bon_get_option('bale_enabled',true)) { $api->answer_callback_query($cb_id,'بله در تنظیمات غیرفعال است.');return; }

        // --- کلیک روی دکمه تغییر وضعیت سفارش: bon_status:{new_status}:{order_id} ---
        if ( strpos( $data, 'bon_status:' ) === 0 ) {
            $parts = explode( ':', $data );
            if ( count( $parts ) !== 3 ) {
                return;
            }
            list( , $new_status, $order_id ) = $parts;

            $order = wc_get_order( (int) $order_id );
            if ( ! $order ) {
                $api->answer_callback_query( $cb_id, 'سفارش یافت نشد.' );
                return;
            }

            if ( ! array_key_exists( 'wc-' . $new_status, wc_get_order_statuses() ) ) return;

            if (!$order->update_status( $new_status, 'وضعیت از طریق دکمه پیام‌رسان تغییر کرد. ' )) {
                $api->answer_callback_query($cb_id, 'ذخیره وضعیت ناموفق بود؛ وضعیت سفارش را در ووکامرس بررسی کنید.');
                Bale_Order_Notifier_Logger::log('error', 'تغییر وضعیت سفارش #' . (int) $order_id . ' از بله ذخیره نشد.');
                return;
            }

            $status_label = wc_get_order_status_name( $new_status );
            $api->answer_callback_query( $cb_id, "وضعیت به «{$status_label}» تغییر کرد ✅" );

            if ( $chat_id && $message_id ) {
                $new_text = "✅ سفارش #{$order_id}\nوضعیت جدید: {$status_label}";
                $api->edit_message( $chat_id, $message_id, $new_text );
            }

            Bale_Order_Notifier_Logger::log( 'success', "وضعیت سفارش #{$order_id} از طریق {$platform} به {$new_status} تغییر کرد." );
            return;
        }

        // --- کلیک روی دکمه گزارش‌گیری زنده: bon_report:{type} ---
        if ( strpos( $data, 'bon_report:' ) === 0 ) {
            $type = substr( $data, strlen( 'bon_report:' ) );
            $api->answer_callback_query( $cb_id, 'در حال آماده‌سازی گزارش... ⏳' );

            $message = Bale_Order_Notifier_Reports::generate( $type );
            if ( $chat_id ) {
                if ((string)$chat_id===(string)($callback_query['from']['id']??'')) $api->send_reply($chat_id,$message,[],['scope'=>'admin']);
                else $api->send_message($chat_id,$message,[],false);
            }
            Bale_Order_Notifier_Logger::log( 'success', "گزارش '{$type}' با کلیک دکمه در {$platform} ارسال شد." );
            return;
        }
    }
}
