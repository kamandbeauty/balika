<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس Dispatcher - نقطه مرکزی ارسال پیام به تمام پلتفرم‌های فعال (بله)
 * و تمام chat_id های تنظیم‌شده. جایگزین ارسال پراکنده در کلاس‌های مختلف.
 */
class Bale_Order_Notifier_Dispatcher {

    /**
     * ارسال یک پیام به همه مقاصد فعال
     *
     * @param string $message متن پیام (HTML)
     * @param array  $buttons دکمه‌های اینلاین (اختیاری)
     * @return array نگاشت "platform:chat_id" => message_id برای پیام‌های موفق
     */
    public static function broadcast( $message, $buttons = [], $order_id = 0, $message_type = 'admin_order' ) {
        $sent = [];
        foreach ( bon_get_enabled_platforms() as $platform ) {
            $chat_ids = (array) bon_get_option( $platform . '_chat_ids', [] );
            if ( empty( $chat_ids ) ) {
                continue;
            }
            $api = new Bale_Order_Notifier_API( $platform );
            foreach ( $chat_ids as $chat_id ) {
                $chat_id = trim( $chat_id );
                if ( $chat_id === '' ) continue;
                $result = $api->send_message( $chat_id, $message, $buttons, true, $order_id, $message_type );
                if ( $result && is_array( $result ) && ( isset( $result['message_id'] ) || ! empty( $result['queued'] ) ) ) {
                    $sent[ $platform . ':' . $chat_id ] = $result['message_id'] ?? 'queued';
                }
            }
        }
        return $sent;
    }
}
