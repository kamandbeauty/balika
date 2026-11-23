<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس کرون - ارسال خودکار گزارش‌ها طبق زمان‌بندی و پردازش دوره‌ای صف ارسال
 */
class Bale_Order_Notifier_Cron {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init',[__CLASS__,'ensure_closed_day']);
        add_action('bon_closed_day_report_event',[$this,'send_daily_report']);
        add_action( 'bon_weekly_report_event', [ $this, 'send_weekly_report' ] );
        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
    }

    public function add_custom_schedules( $schedules ) {
        $schedules['bon_five_minutes'] = [
            'interval' => 5 * 60,
            'display'  => __( 'هر ۵ دقیقه (صف ارسال بله نوتیفای)', 'balika' ),
        ];
        return $schedules;
    }

    public static function ensure_closed_day() {
        // Retire the old 23:55 recurring event, including existing installations.
        if (wp_next_scheduled('bon_daily_report_event')) wp_clear_scheduled_hook('bon_daily_report_event');
        if (wp_next_scheduled('bon_closed_day_report_event')) return;
        $today=new DateTimeImmutable('today',wp_timezone());
        $day=get_option('bon_closed_day_pending','');
        if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)) $day=$today->format('Y-m-d');
        update_option('bon_closed_day_pending',$day,false);
        $end=new DateTimeImmutable($day.' 00:00:00',wp_timezone());
        wp_schedule_single_event(max(time()+1,$end->modify('+1 day')->getTimestamp()),'bon_closed_day_report_event');
    }
    public static function closed_bounds($day) {
        return Bale_Order_Notifier_Reports::range_bounds($day,$day);
    }
    public function send_daily_report() {
        global $wpdb;$lock='bon_daily_'.md5($wpdb->prefix);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return;
        try {
            $day=get_option('bon_closed_day_pending','');$bounds=self::closed_bounds($day);
            if (is_wp_error($bounds) || $bounds[1]>=time()) return;
            // Advance before dispatch: a repeated cron invocation cannot replay this day.
            $next=(new DateTimeImmutable($day,wp_timezone()))->modify('+1 day')->format('Y-m-d');
            update_option('bon_closed_day_pending',$next,false);
            if (get_option('bon_closed_day_pending')!==$next) return;
            wp_clear_scheduled_hook('bon_closed_day_report_event');
            self::ensure_closed_day();
            if (!bon_get_option('daily_report_enabled',false) || !in_array('bale',bon_get_enabled_platforms(),true)) return;
            $message='گزارش روز کامل '.$day."\n".Bale_Order_Notifier_Reports::range_report($bounds);
            $sent=Bale_Order_Notifier_Dispatcher::broadcast($message,[],0,'daily_report');
            Bale_Order_Notifier_Logger::log($sent?'info':'error','گزارش روز کامل '.$day.($sent?' برای ارسال پذیرفته شد؛ نتیجه هر مقصد باید جدا بررسی شود.':' به مقصدی ثبت نشد؛ تنظیمات گیرندگان و اتصال را بررسی کنید.'));
            if (bon_get_option('auto_stock_reports',false)) { self::send_report_now('low_stock');self::send_report_now('out_of_stock'); }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    public function send_weekly_report() {
        if ( ! bon_get_option( 'weekly_report_enabled', false ) ) {
            return;
        }
        self::send_report_now( 'this_week' );
        self::send_report_now( 'top_products' );
    }

    /**
     * ارسال فوری یک گزارش مشخص به همه مقاصد فعال (برای دکمه ارسال دستی و کرون)
     */
    public static function send_report_now( $type ) {
        $message = Bale_Order_Notifier_Reports::generate( $type );
        return Bale_Order_Notifier_Dispatcher::broadcast( $message );
    }
}
