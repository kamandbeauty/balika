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
        add_action('init',[__CLASS__,'ensure_closed_week']);
        add_action('bon_closed_day_report_event',[$this,'send_daily_report']);
        add_action( 'bon_closed_week_report_event', [ $this, 'send_weekly_report' ] );
        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
    }

    public function add_custom_schedules( $schedules ) {
        $schedules['bon_five_minutes'] = [
            'interval' => 5 * 60,
            'display'  => __( 'هر ۵ دقیقه (صف ارسال بله نوتیفای)', 'balika' ),
        ];
        return $schedules;
    }

    /** Reject calendar overflow instead of allowing DateTime to normalize corrupt cursors. */
    public static function valid_report_day($day) {
        return is_string($day) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$day)
            && checkdate((int)substr($day,5,2),(int)substr($day,8,2),(int)substr($day,0,4));
    }

    public static function ensure_closed_day() {
        // Retire the old 23:55 recurring event, including existing installations.
        if (wp_next_scheduled('bon_daily_report_event')) wp_clear_scheduled_hook('bon_daily_report_event');
        $today=new DateTimeImmutable('today',wp_timezone());
        $day=get_option('bon_closed_day_pending','');
        if (!self::valid_report_day($day)) {
            $day=$today->format('Y-m-d');
            update_option('bon_closed_day_pending',$day,false);
            if (get_option('bon_closed_day_pending')!==$day) return;
            wp_clear_scheduled_hook('bon_closed_day_report_event');
        }
        if (wp_next_scheduled('bon_closed_day_report_event')) return;
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
            $enabled=bon_get_option('daily_report_enabled',false) && in_array('bale',bon_get_enabled_platforms(),true);
            // Persist the snapshot and ALL destination queue rows before advancing the day.
            // A partial insertion can be safely resumed without replaying existing rows.
            if (($enabled || Bale_Order_Notifier_Daily_Delivery::record($day)) && !Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds)) {
                Bale_Order_Notifier_Logger::log('error','ثبت صف گزارش روز '.$day.' کامل نشد؛ در اجرای بعدی تکمیل می‌شود.');
                wp_clear_scheduled_hook('bon_closed_day_report_event');
                wp_schedule_single_event(time()+300,'bon_closed_day_report_event');
                return;
            }
            foreach (['low_stock','out_of_stock'] as $kind) {
                $key=$day.'_'.$kind;
                if (($enabled && bon_get_option('auto_stock_reports',false)) || Bale_Order_Notifier_Daily_Delivery::record($key)) {
                    $existing=Bale_Order_Notifier_Daily_Delivery::record($key);
                    $text=$existing?$existing['text']:'گزارش موجودی در زمان تهیه — '.$day."\n".Bale_Order_Notifier_Reports::generate($kind);
                    if (!Bale_Order_Notifier_Daily_Delivery::prepare($key,$bounds,$text)) {
                        wp_clear_scheduled_hook('bon_closed_day_report_event');wp_schedule_single_event(time()+300,'bon_closed_day_report_event');return;
                    }
                }
            }
            $next=(new DateTimeImmutable($day,wp_timezone()))->modify('+1 day')->format('Y-m-d');
            update_option('bon_closed_day_pending',$next,false);
            if (get_option('bon_closed_day_pending')!==$next) return;
            wp_clear_scheduled_hook('bon_closed_day_report_event');
            self::ensure_closed_day();
            if (!$enabled) return;

        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    public static function ensure_closed_week() {
        if (wp_next_scheduled('bon_weekly_report_event')) wp_clear_scheduled_hook('bon_weekly_report_event');
        $day=get_option('bon_closed_week_pending','');
        if (!self::valid_report_day($day)) {
            $start=(new DateTimeImmutable('@'.bon_week_start_timestamp()))->setTimezone(wp_timezone());
            $day=$start->modify('+6 days')->format('Y-m-d');
            update_option('bon_closed_week_pending',$day,false);
            if (get_option('bon_closed_week_pending')!==$day) return;
            wp_clear_scheduled_hook('bon_closed_week_report_event');
        }
        if (wp_next_scheduled('bon_closed_week_report_event')) return;
        $end=new DateTimeImmutable($day.' 00:00:00',wp_timezone());
        wp_schedule_single_event(max(time()+1,$end->modify('+1 day')->getTimestamp()),'bon_closed_week_report_event');
    }
    public function send_weekly_report() {
        global $wpdb;$lock='bon_daily_'.md5($wpdb->prefix);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return;
        try {
            $day=get_option('bon_closed_week_pending','');
            $end=Bale_Order_Notifier_Reports::range_bounds($day,$day);
            if (is_wp_error($end) || $end[1]>=time()) return;
            $from=(new DateTimeImmutable($day,wp_timezone()))->modify('-6 days')->format('Y-m-d');
            $bounds=Bale_Order_Notifier_Reports::range_bounds($from,$day);$key=$day.'_weekly';
            $existing=Bale_Order_Notifier_Daily_Delivery::record($key);
            if ((bon_get_option('weekly_report_enabled',false) && in_array('bale',bon_get_enabled_platforms(),true)) || $existing) {
                $text=$existing?$existing['text']:'گزارش هفته کامل '.$from.' تا '.$day."\n".Bale_Order_Notifier_Reports::range_report($bounds);
                if (!Bale_Order_Notifier_Daily_Delivery::prepare($key,$bounds,$text)) {
                    wp_clear_scheduled_hook('bon_closed_week_report_event');wp_schedule_single_event(time()+300,'bon_closed_week_report_event');return;
                }
            }
            $next=(new DateTimeImmutable($day,wp_timezone()))->modify('+7 days')->format('Y-m-d');
            update_option('bon_closed_week_pending',$next,false);
            if (get_option('bon_closed_week_pending')!==$next) return;
            wp_clear_scheduled_hook('bon_closed_week_report_event');self::ensure_closed_week();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    /**
     * ارسال فوری یک گزارش مشخص به همه مقاصد فعال (برای دکمه ارسال دستی و کرون)
     */
    public static function send_report_now( $type ) {
        $message = Bale_Order_Notifier_Reports::generate( $type );
        return Bale_Order_Notifier_Dispatcher::broadcast( $message );
    }
}
