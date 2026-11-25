<?php
if (!defined('ABSPATH')) exit;

/** Durable daily snapshot; enqueue under the same lock as the queue worker. */
class Bale_Order_Notifier_Daily_Delivery {
    public function __construct() {
        add_action('admin_menu',[$this,'menu']);
        add_action('admin_post_bon_daily_retry',[$this,'retry']);
    }
    public static function key($day) { return 'bon_daily_record_'.$day; }
    public static function type($day,$chat) { return 'daily_'.$day.'_'.substr(hash('sha256',(string)$chat),0,24); }
    public static function destinations() {
        $out=[];
        foreach ((array)bon_get_option('bale_chat_ids',[]) as $chat) {
            if (!is_scalar($chat)) continue;
            $chat=trim((string)$chat);
            if ($chat!=='') $out[$chat]=$chat;
        }
        return array_values($out);
    }
    public static function record($day) {
        if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:_(?:weekly|low_stock|out_of_stock))?$/D',$day)) return false;
        $record=get_option(self::key($day),false);
        return is_array($record) && isset($record['text'],$record['targets']) && is_array($record['targets']) ? $record : false;
    }
    /** Caller holds the daily producer lock. Return true only once every recipient is durably queued. */
    public static function prepare($day,$bounds,$text=null) {
        $record=self::record($day);
        if (!$record) {
            $targets=self::destinations();
            if (!$targets) return false;
            $record=['day'=>$day,'created'=>time(),'targets'=>$targets,'text'=>$text===null?'گزارش روز کامل '.$day."\n".Bale_Order_Notifier_Reports::range_report($bounds):$text];
            update_option(self::key($day),$record,false);
            if (get_option(self::key($day))!==$record) return false;
        }
        global $wpdb;$table=Bale_Order_Notifier_Queue::table_name();$lock='bon_queue_'.md5($table);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return false;
        try {
            $complete=true;
            foreach ($record['targets'] as $chat) {
                $type=self::type($day,$chat);
                $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE platform='bale' AND message_type=%s LIMIT 1",$type));
                if (!empty($wpdb->last_error)) { $complete=false; continue; }
                if ($existing) continue;
                if (!Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>(string)$chat,'text'=>$record['text']],0,$type)) $complete=false;
            }
            return $complete;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function can_deliver($type,$params) {
        if (!in_array('bale',bon_get_enabled_platforms(),true)) return false;
        if (!preg_match('/^daily_(\d{4}-\d{2}-\d{2}(?:_(?:weekly|low_stock|out_of_stock))?)_[a-f0-9]{24}$/D',$type,$m) || !is_array($params)) return false;
        $weekly=substr($m[1],-7)==='_weekly';
        if (!bon_get_option($weekly?'weekly_report_enabled':'daily_report_enabled',false)) return false;
        if (!$weekly && strlen($m[1])>10 && !bon_get_option('auto_stock_reports',false)) return false;
        $record=self::record($m[1]);$chat=(string)($params['chat_id']??'');
        return $record && in_array($chat,$record['targets'],true) && in_array($chat,self::destinations(),true) && hash_equals(self::type($m[1],$chat),$type) && ($params['text']??null)===$record['text'];
    }
    public static function retry_one($id) {
        global $wpdb;$table=Bale_Order_Notifier_Queue::table_name();$lock='bon_queue_'.md5($table);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return 'صف در حال پردازش است؛ دوباره تلاش کنید.';
        try {
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND platform='bale'",$id));
            if (!$row || !in_array($row->status,['dead-letter','paused'],true)) return 'فقط پیام ناموفق نهایی یا متوقف قابل تلاش مجدد است؛ پیام موفق یا منتظر تغییر نکرد.';
            if (!self::can_deliver($row->message_type,json_decode($row->payload,true))) return 'گزارش، مقصد یا مجوز فعلی ارسال معتبر نیست.';
            $ok=$wpdb->update($table,['status'=>'pending','attempts'=>0,'next_attempt'=>current_time('mysql'),'last_error'=>''],['id'=>$id,'status'=>$row->status]);
            return $ok===1?'همان پیام برای تلاش مجدد در صف قرار گرفت؛ مقصدهای دیگر تغییر نکردند.':'ثبت تلاش مجدد ناموفق بود.';
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public function menu() { add_submenu_page('balika','سابقه گزارش‌ها','سابقه گزارش‌ها','manage_woocommerce','balika-daily-delivery',[$this,'page']); }
    public function retry() {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی مجاز نیست.');
        check_admin_referer('bon_daily_retry');
        $message=self::retry_one(absint($_POST['message_id']??0));
        set_transient('bon_daily_notice_'.get_current_user_id(),$message,300);
        wp_safe_redirect(admin_url('admin.php?page=balika-daily-delivery'));exit;
    }
    public function page() {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی مجاز نیست.');
        global $wpdb;$table=Bale_Order_Notifier_Queue::table_name();$page=max(1,absint($_GET['paged']??1));
        $pattern=$wpdb->esc_like('bon_daily_record_').'%';
        $records=$wpdb->get_results($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC LIMIT 20 OFFSET %d",$pattern,($page-1)*20));
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$pattern));
        echo '<div class="wrap" dir="rtl"><h1>سابقه سابقه گزارش‌ها</h1><p>هر گزارش با متن و گیرندگان زمان ساخت نگهداری می‌شود. پذیرش API به معنی خوانده‌شدن نیست. پس از پنج تلاش ناموفق، تلاش مجدد دستی لازم است.</p><p>سابقه گزارش‌ها از پاک‌سازی ۳۰ روزه صف مستثنا هستند تا حذف سابقه باعث ارسال دوباره نشود. سوابق فعلاً تا حذف اختیاری داده‌های افزونه نگهداری می‌شوند.</p>';
        $pending=get_option('bon_closed_day_pending','');
        if (is_string($pending) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$pending)) echo '<p>روز بعدی در انتظار آماده‌سازی/تکمیل صف: '.esc_html($pending).'</p>';
        if (!self::destinations()) echo '<p class="notice notice-warning">هیچ مقصدی برای گزارش تنظیم نشده است؛ شناسه مدیر را در اتصال بله ثبت کنید.</p>';
        $key='bon_daily_notice_'.get_current_user_id();$notice=get_transient($key);
        if ($notice) { delete_transient($key);echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; }
        if (!$records) echo '<p>هنوز گزارش روزانه‌ای ثبت نشده است.</p>';
        $labels=['uncertain'=>'نتیجه تحویل ثبت نشده؛ نیازمند بررسی (بدون تلاش مجدد خودکار)','pending'=>'در صف','sent'=>'پذیرفته‌شده توسط API بله','dead-letter'=>'ناموفق نهایی','paused'=>'متوقف','cancelled'=>'لغوشده'];
        foreach ($records as $item) {
            $day=substr($item->option_name,strlen('bon_daily_record_'));$record=self::record($day);if (!$record) continue;
            echo '<h2>'.esc_html($day).'</h2><details><summary>متن ثبت‌شده گزارش</summary><pre style="white-space:pre-wrap">'.esc_html($record['text']).'</pre></details><table class="widefat striped"><tr><th>مقصد</th><th>وضعیت / تلاش</th><th>خطا / عملیات</th></tr>';
            foreach ($record['targets'] as $chat) {
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE platform='bale' AND message_type=%s ORDER BY id LIMIT 1",self::type($day,$chat)));
                echo '<tr><td>'.esc_html($chat).'</td><td>'.esc_html($row?($labels[$row->status]??$row->status):'ثبت صف ناقص؛ در اجرای بعدی گزارش دوباره بررسی می‌شود').($row?' / '.(int)$row->attempts:'').'</td><td>'.esc_html($row?$row->last_error:'');
                if ($row && in_array($row->status,['dead-letter','paused'],true)) {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bon_daily_retry');
                    echo '<input type="hidden" name="action" value="bon_daily_retry"><input type="hidden" name="message_id" value="'.(int)$row->id.'"><button class="button">تلاش مجدد همین مقصد</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%',admin_url('admin.php?page=balika-daily-delivery')),'format'=>'','current'=>$page,'total'=>ceil($total/20)]));echo '</div>';
    }
}
