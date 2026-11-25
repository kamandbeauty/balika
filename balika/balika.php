<?php
/**
 * Plugin Name: بالیکا | Balika Bale Notify
 * Plugin URI: https://javiiid.ir/
 * Description: اعلان سفارشات ووکامرس، گزارش فروش، و پیام‌رسانی مستقیم به مشتری در پیام‌رسان بله. | WooCommerce notifications for Bale Messenger
 * Version: 2.5.0-rc.2
 * Author: استودیو جادید
 * Author URI: https://javiiid.ir/
 * Text Domain: balika
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // جلوگیری از دسترسی مستقیم
}

define( 'BON_VERSION', '2.5.0-rc.2' );
define( 'BON_PLUGIN_FILE', __FILE__ );
define( 'BON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BON_WEBHOOK_QUERY_VAR', 'bon_bale_webhook' );

/**
 * بررسی فعال بودن ووکامرس قبل از بارگذاری پلاگین
 */
function bon_check_woocommerce_active() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'پلاگین «اعلان سفارش بله» نیازمند فعال بودن ووکامرس است.', 'balika' );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * بارگذاری فایل‌های کلاس
 */
function bon_load_includes() {
    require_once BON_PLUGIN_DIR . 'includes/class-bon-api.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-logger.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-queue.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-dispatcher.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-reports.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-admin-link.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-settings.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-tracking.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-operations.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-tracking-import.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-commerce.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-channels.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-support.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-customer-store.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-customer-hooks.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-order-hooks.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-cart-hooks.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-form-hooks.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-site-hooks.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-webhook.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-daily-delivery.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-cron.php';
}

/**
 * راه‌اندازی پلاگین
 */
function bon_init_plugin() {
    if ( ! bon_check_woocommerce_active() ) {
        return;
    }

    bon_load_includes();
    Bale_Order_Notifier_Customer_Store::ensure_owner_schema();
    add_action('woocommerce_before_order_object_save',[Bale_Order_Notifier_Customer_Store::class,'owner_will_save'],10,1);
    // Remove the retired payment controller option; never alter Woo gateway settings.
    if (!get_option('bon_support_migrated', false)) {
        delete_option('bon_balepay_settings');
        update_option('bon_support_migrated', true, false);
    }
    if ( get_option( 'bon_db_version' ) !== BON_VERSION ) {
        wp_clear_scheduled_hook( 'bon_daily_report_event' );
        wp_clear_scheduled_hook( 'bon_weekly_report_event' );
        bon_activate_plugin();
        update_option( 'bon_db_version', BON_VERSION, false );
    }

    Bale_Order_Notifier_Settings::instance();
    Bale_Order_Notifier_Operations::instance();
    new Bale_Order_Notifier_Tracking_Import();
    new Bale_Order_Notifier_Commerce();
    new Bale_Order_Notifier_Channels();
    new Bale_Order_Notifier_Support();
    new Bale_Order_Notifier_Daily_Delivery();
    Bale_Order_Notifier_Order_Hooks::instance();
    Bale_Order_Notifier_Customer_Hooks::instance();
    Bale_Order_Notifier_Cart_Hooks::instance();
    Bale_Order_Notifier_Form_Hooks::instance();
    Bale_Order_Notifier_Site_Hooks::instance();
    Bale_Order_Notifier_Webhook::instance();
    Bale_Order_Notifier_Cron::instance();
    Bale_Order_Notifier_Queue::instance();
}
add_action( 'plugins_loaded', 'bon_init_plugin' );

/**
 * لود فایل ترجمه
 */
add_action( 'init', function () {
    load_plugin_textdomain( 'balika', false, dirname( plugin_basename( BON_PLUGIN_FILE ) ) . '/languages' );
} );

/**
 * فعال‌سازی پلاگین
 */
function bon_activate_plugin() {
    // Activation may run after plugins_loaded, before normal class bootstrapping.
    require_once BON_PLUGIN_DIR . 'includes/class-bon-cron.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-logger.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-queue.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-customer-store.php';
    require_once BON_PLUGIN_DIR . 'includes/class-bon-cart-hooks.php';
    Bale_Order_Notifier_Logger::create_table();
    Bale_Order_Notifier_Queue::create_table();
    Bale_Order_Notifier_Customer_Store::create_table();
    Bale_Order_Notifier_Cart_Hooks::create_table();

    Bale_Order_Notifier_Cron::ensure_closed_day();
    Bale_Order_Notifier_Cron::ensure_closed_week();
    if ( ! wp_next_scheduled( 'bon_process_queue_event' ) ) {
        wp_schedule_event( time() + 300, 'bon_five_minutes', 'bon_process_queue_event' );
    }
    if ( ! wp_next_scheduled( 'bon_check_abandoned_carts_event' ) ) {
        wp_schedule_event( time() + 300, 'bon_five_minutes', 'bon_check_abandoned_carts_event' );
    }
    if ( ! wp_next_scheduled( 'bon_payment_reminder_event' ) ) {
        wp_schedule_event( time() + 300, 'bon_five_minutes', 'bon_payment_reminder_event' );
    }
    if ( ! wp_next_scheduled( 'bon_cleanup_cart_snapshots_event' ) ) {
        wp_schedule_event( strtotime( '04:00:00' ), 'daily', 'bon_cleanup_cart_snapshots_event' );
    }

    flush_rewrite_rules();
}
register_activation_hook( BON_PLUGIN_FILE, 'bon_activate_plugin' );

/**
 * ثبت بازه زمانی سفارشی کرون در صورتی که پلاگین هنوز init نشده باشد (برای فعال‌سازی)
 */
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['bon_five_minutes'] ) ) {
        $schedules['bon_five_minutes'] = [ 'interval' => 300, 'display' => 'هر ۵ دقیقه' ];
    }
    return $schedules;
} );

/**
 * غیرفعال‌سازی پلاگین
 */
function bon_deactivate_plugin() {
    wp_clear_scheduled_hook('bon_closed_day_report_event');
    wp_clear_scheduled_hook('bon_closed_week_report_event');
    wp_clear_scheduled_hook( 'bon_daily_report_event' );
    wp_clear_scheduled_hook( 'bon_weekly_report_event' );
    wp_clear_scheduled_hook( 'bon_process_queue_event' );
    wp_clear_scheduled_hook( 'bon_check_abandoned_carts_event' );
    wp_clear_scheduled_hook( 'bon_payment_reminder_event' );
    wp_clear_scheduled_hook( 'bon_cleanup_cart_snapshots_event' );
    flush_rewrite_rules();
}
register_deactivation_hook( BON_PLUGIN_FILE, 'bon_deactivate_plugin' );

/**
 * لینک تنظیمات در صفحه پلاگین‌ها
 */
add_filter( 'plugin_action_links_' . plugin_basename( BON_PLUGIN_FILE ), function ( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=balika' ) . '">' . esc_html__( 'تنظیمات', 'balika' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

/** Site-local report boundaries, converted to UTC timestamps for WP-Cron. */
function bon_next_report_timestamp( $weekly = false ) {
    $options = get_option( 'bon_settings', [] );
    $clock = $options['report_time'] ?? '23:55';
    if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $clock ) ) $clock = '23:55';
    $now = new DateTimeImmutable( 'now', wp_timezone() );
    $next = new DateTimeImmutable( 'today ' . $clock, wp_timezone() );
    if ( $weekly ) {
        while ( (int) $next->format('w') !== 6 ) $next = $next->modify('+1 day');
    }
    if ( $next <= $now ) $next = $next->modify( $weekly ? '+7 days' : '+1 day' );
    return $next->getTimestamp();
}
function bon_week_start_timestamp() {
    $today = new DateTimeImmutable( 'today', wp_timezone() );
    $days = ( (int) $today->format('w') - (int) get_option('start_of_week', 1) + 7 ) % 7;
    return $today->modify( '-' . $days . ' days' )->getTimestamp();
}
