<?php
/**
 * اجرا می‌شود فقط زمانی که پلاگین از طریق پنل وردپرس به‌طور کامل حذف شود (نه صرفاً غیرفعال شود)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$options = get_option( 'bon_settings', [] );

// پیش‌فرض امن: اگر ادمین صراحتاً این گزینه را فعال نکرده باشد، هیچ داده‌ای پاک نمی‌شود
if ( empty( $options['delete_data_on_uninstall'] ) ) {
    return;
}

global $wpdb;

// حذف جدول‌های اختصاصی پلاگین
$tables = [
    $wpdb->prefix . 'bon_logs',
    $wpdb->prefix . 'bon_queue',
    $wpdb->prefix . 'bon_customer_links',
    $wpdb->prefix . 'bon_cart_snapshots',
];
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// حذف تنظیمات
delete_option( 'bon_settings' );
delete_option( 'bon_db_version' );
delete_option( 'bon_reminder_page' );
delete_option( 'bon_tracking_settings' );
delete_option( 'bon_commerce_settings' );
delete_option( 'bon_channels' );
delete_option( 'bon_support_enabled' );
delete_option( 'bon_support_contact' );
delete_option( 'bon_support_migrated' );

// Explicit delete-data opt-in also removes private channel publication records.
$job_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('bon_channel_job','bon_support_thread','bon_support_message')");
foreach ($job_ids as $job_id) wp_delete_post((int) $job_id, true);

// حذف رویدادهای زمان‌بندی‌شده کرون
wp_clear_scheduled_hook( 'bon_daily_report_event' );
wp_clear_scheduled_hook( 'bon_weekly_report_event' );
wp_clear_scheduled_hook( 'bon_process_queue_event' );
wp_clear_scheduled_hook( 'bon_check_abandoned_carts_event' );
wp_clear_scheduled_hook( 'bon_payment_reminder_event' );
wp_clear_scheduled_hook( 'bon_cleanup_cart_snapshots_event' );

// حذف متادیتاهای سفارش که این پلاگین روی سفارش‌ها ذخیره کرده بود
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
        '_bon_new_order_notified', '_bon_customer_notified', '_bon_sent_messages',
        '_bon_tracking_enqueued', '_bon_tracking_code', '_bon_courier_name', '_bon_tracking_url', '_bon_payment_reminder_sent'
    )"
);

// Remove the same order metadata from HPOS, if its table exists.
$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hpos_meta))) === $hpos_meta) {
    $wpdb->query("DELETE FROM {$hpos_meta} WHERE meta_key IN (
        '_bon_new_order_notified', '_bon_customer_notified', '_bon_sent_messages',
        '_bon_tracking_code', '_bon_courier_name', '_bon_tracking_url',
        '_bon_payment_reminder_sent', '_bon_tracking_enqueued'
    )");
}
foreach (['_transient_bon_', '_transient_timeout_bon_'] as $prefix) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
}

// Operational health metadata contains no message payloads.
delete_option('bon_connection_test');
delete_option('bon_queue_last_started');

delete_option('bon_closed_day_pending');
wp_clear_scheduled_hook('bon_closed_day_report_event');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('bon_admin_pair_').'%'));
