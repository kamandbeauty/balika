<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Shared service for bulk and individual tracking updates. Uses WooCommerce CRUD (HPOS-safe). */
class Bale_Order_Notifier_Tracking {
    private static $active = [];
    public static function fingerprint( $order ) {
        return hash( 'sha256', wp_json_encode( [ $order->get_status(),
            $order->get_meta('_bon_tracking_code'), $order->get_meta('_bon_courier_name'),
            $order->get_meta('_bon_tracking_url') ] ) );
    }

    public static function normalize_code( $code ) {
        if ( ! is_scalar( $code ) ) return '';
        return trim( strtr( (string) $code, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ) ) );
    }

    /** Never changes an order for an empty/invalid code or stale browser snapshot. */
    public static function save( $order_id, $fields, $target = '', $sources = [], $expected = '', $replace = false ) {
        if ( ! current_user_can('manage_woocommerce') && ! current_user_can('edit_shop_orders') ) {
            return new WP_Error('forbidden', 'دسترسی کافی ندارید.');
        }
        if ( ! empty(self::$active[$order_id]) ) return new WP_Error('busy', 'ثبت رهگیری در حال انجام است.');
        $code = self::normalize_code( $fields['code'] ?? '' );
        if ( ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{2,99}$/D', $code) ) {
            return new WP_Error('invalid_code', 'کد رهگیری باید ۳ تا ۱۰۰ نویسه، شامل عدد، حروف انگلیسی یا خط تیره باشد؛ هیچ تغییری انجام نشد.');
        }
        $statuses = wc_get_order_statuses();
        if ( $target !== '' && ! isset($statuses['wc-' . $target]) ) {
            return new WP_Error('invalid_status', 'وضعیت مقصد نامعتبر است.');
        }
        $courier = sanitize_text_field( $fields['courier'] ?? '' );
        $raw_url = trim( (string) ( $fields['url'] ?? '' ) );
        $url = esc_url_raw( $raw_url, ['http', 'https'] );
        if ( $raw_url !== '' && ( ! $url || ! wp_http_validate_url($url) ) ) {
            return new WP_Error('invalid_url', 'آدرس رهگیری باید یک نشانی عمومی معتبر http یا https باشد.');
        }
        global $wpdb;
        $lock = 'bon_tracking_' . md5( $wpdb->prefix . ':' . (int) $order_id );
        if ( ! $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) ) {
            return new WP_Error('busy', 'این سفارش در حال پردازش است؛ دوباره تلاش کنید.');
        }
        self::$active[$order_id] = true;
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_type() !== 'shop_order' ) return new WP_Error('missing', 'سفارش یافت نشد.');
            if ( ! current_user_can('edit_shop_order', $order->get_id()) ) return new WP_Error('forbidden', 'مجوز ویرایش این سفارش را ندارید.');
            if ( $sources && ! in_array($order->get_status(), $sources, true) ) return new WP_Error('status_changed', 'وضعیت سفارش از فهرست انتخاب‌شده خارج شده است؛ صفحه را تازه کنید.');
            if ( $expected !== '' && ! hash_equals(self::fingerprint($order), $expected) ) return new WP_Error('stale', 'سفارش پس از بازشدن صفحه تغییر کرده است؛ صفحه را تازه کنید.');
            $old = (string) $order->get_meta('_bon_tracking_code');
            $changed = $old !== $code || (string) $order->get_meta('_bon_courier_name') !== $courier || (string) $order->get_meta('_bon_tracking_url') !== $url;
            if ( $old !== '' && $changed && ! $replace ) return new WP_Error('exists', 'کد قبلی موجود است؛ برای جایگزینی تأیید صریح لازم است.');
            if ( ! $changed && ( $target === '' || $order->get_status() === $target ) ) {
                return self::notify($order);
            }
            $order->update_meta_data('_bon_tracking_code', $code);
            $order->update_meta_data('_bon_courier_name', $courier);
            $order->update_meta_data('_bon_tracking_url', $url);
            // Save tracking before the status transition so hooked notifications see the new code.
            $order->save();
            $status_ok = true;
            if ( $target !== '' && $target !== $order->get_status() ) {
                $status_ok = $order->update_status($target, 'بالیکا: تغییر وضعیت پس از ثبت کد رهگیری.');
            }
            $order->add_order_note('بالیکا: اطلاعات رهگیری توسط کاربر #' . get_current_user_id() . ' ثبت شد.');
            $result = self::notify($order);
            if ( ! $status_ok ) $result['message'] .= ' هشدار: کد ذخیره شد ولی تغییر وضعیت ناموفق بود.';
            return $result;
        } catch ( Throwable $e ) {
            Bale_Order_Notifier_Logger::log('error', 'خطای ذخیره رهگیری سفارش #' . (int) $order_id);
            return new WP_Error('save_failed', 'ثبت کامل نشد؛ پیش از تکرار، وضعیت و کد فعلی سفارش را بررسی کنید.');
        } finally {
            unset(self::$active[$order_id]);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function notify( $order ) {
        if ( ! bon_get_option('customer_notifications_enabled', true) || ! in_array('bale', bon_get_enabled_platforms(), true) ) {
            return ['state' => 'disabled', 'message' => 'کد ذخیره شد؛ ارسال به مشتری یا اتصال بله غیرفعال است.'];
        }
        $links = Bale_Order_Notifier_Customer_Store::get_links_for_order($order->get_id());
        if ( ! $links ) return ['state' => 'unlinked', 'message' => 'کد ذخیره شد؛ مشتری به بله متصل نیست و پیامی ارسال نخواهد شد.'];
        $text = Bale_Order_Notifier_Customer_Hooks::instance()->build_customer_message('template_customer_tracking', $order);
        $ok = true;
        $added = 0;
        $signature = hash('sha256', wp_json_encode([$order->get_meta('_bon_tracking_code'), $order->get_meta('_bon_courier_name'), $order->get_meta('_bon_tracking_url')]));
        $stored = $order->get_meta('_bon_tracking_enqueued');
        $enqueued = is_array($stored) ? $stored : [];
        foreach ( $links as $link ) {
            $recipient = 'bale:' . $link['chat_id'];
            if (isset($enqueued[$recipient]) && hash_equals($enqueued[$recipient], $signature)) continue;
            if (Bale_Order_Notifier_Queue::enqueue('bale', 'sendMessage', ['chat_id' => $link['chat_id'], 'text' => $text], $order->get_id(), 'tracking')) {
                $enqueued[$recipient] = $signature;
                $order->update_meta_data('_bon_tracking_enqueued', $enqueued);
                $order->save_meta_data();
                $added++;
            } else {
                $ok = false;
            }
        }
        if ($ok && !$added) return ['state' => 'unchanged', 'message' => 'اطلاعات ذخیره است و این پیام قبلاً در صف ثبت شده؛ پیام تکراری ساخته نشد.'];
        return $ok ? ['state' => 'queued', 'message' => 'کد ذخیره و پیام رهگیری در صف بله ثبت شد؛ تحویل هنوز تأیید نشده است.']
            : ['state' => 'queue_failed', 'message' => 'کد ذخیره شد ولی ثبت پیام در صف کامل نشد؛ از تاریخچه سفارش پیگیری کنید.'];
    }
}
