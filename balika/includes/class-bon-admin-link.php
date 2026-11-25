<?php
if (!defined('ABSPATH')) exit;
/** Pairing requests grant no authority until approved by an authenticated WP manager. */
class Bale_Order_Notifier_Admin_Link {
    public static function key($token) { return 'bon_admin_link_'.hash('sha256',$token); }
    private static function request_is_current($request,$token) {
        return is_array($request) && !empty($request['user']) && isset($request['expires'])
            && is_int($request['expires']) && $request['expires']>time()
            && get_user_meta((int)$request['user'],'bon_admin_pair_'.(int)$request['user'],true)===$token;
    }
    public static function incoming($message) {
        $text=trim($message['text']??'');
        if (!preg_match('/^\/start(?:@\S+)?\s+admin_([A-Za-z0-9]{32})$/D',$text,$m)) return false;
        $chat=(string)($message['chat']['id']??'');
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$chat) || $chat!==(string)($message['from']['id']??'') || ($message['chat']['type']??'private')!=='private') return true;
        $key=self::key($m[1]);$request=get_transient($key);
        $api=new Bale_Order_Notifier_API('bale');
        if (!self::request_is_current($request,$m[1]) || !user_can($request['user'],'manage_woocommerce')) { $api->send_reply($chat,'لینک اتصال نامعتبر یا منقضی است؛ از پنل سایت لینک تازه بگیرید.');return true; }
        global $wpdb;$lock='bon_pair_'.md5($wpdb->prefix.$key);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return true;
        try {
            $request=get_transient($key);
            if (!self::request_is_current($request,$m[1]) || !user_can($request['user'],'manage_woocommerce') || !empty($request['chat'])) { $api->send_reply($chat,'این درخواست قبلاً دریافت شده؛ وضعیت را در پنل سایت بررسی کنید.');return true; }
            $request['chat']=$chat;$request['name']=sanitize_text_field($message['from']['first_name']??'');
            set_transient($key,$request,max(1,$request['expires']-time()));
            $saved=get_transient($key);
            $api->send_reply($chat,$saved===$request?'درخواست دریافت شد. اکنون به پنل سایت برگردید، صفحه را تازه کنید و حساب را تأیید کنید. هنوز دسترسی مدیریتی فعال نشده است.':'ذخیره درخواست ناموفق بود؛ از پنل دوباره تلاش کنید.');
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        return true;
    }
    public static function handle($post) {
        $uid=get_current_user_id();$option='bon_admin_pair_'.$uid;
        if ($post['bon_action']==='begin_admin_link') {
            $old=get_user_meta($uid,$option,true);
            $token=wp_generate_password(32,false,false);$key=self::key($token);
            $request=['user'=>$uid,'expires'=>time()+600];
            set_transient($key,$request,600);
            if (get_transient($key)!==$request) {
                delete_transient($key);
                add_settings_error('bon_messages','pair','ذخیره درخواست اتصال ناموفق بود؛ دوباره تلاش کنید.','error');return;
            }
            update_user_meta($uid,$option,$token);
            if (get_user_meta($uid,$option,true)!==$token) {
                delete_transient($key);
                add_settings_error('bon_messages','pair','ذخیره لینک اتصال ناموفق بود؛ دوباره تلاش کنید.','error');return;
            }
            if (is_string($old) && $old && $old!==$token) delete_transient(self::key($old));
            add_settings_error('bon_messages','pair','لینک اتصال ده دقیقه اعتبار دارد؛ فقط خودتان آن را باز کنید.','info');return;
        }
        $token=get_user_meta($uid,$option,true);$key=self::key((string)$token);
        global $wpdb;$lock='bon_pair_'.md5($wpdb->prefix.$key);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) return;
        try {
            $request=get_transient($key);
            if (!self::request_is_current($request,$token) || (int)$request['user']!==$uid || empty($request['chat']) || ($post['pair_chat']??'')!==$request['chat'] || empty($post['confirm_pair'])) { add_settings_error('bon_messages','pair','درخواست منقضی، تغییرکرده یا تأیید نشده است.','error');return; }
            $options=get_option('bon_settings',[]);
            foreach (['bale_admin_user_ids','bale_chat_ids'] as $field) $options[$field]=array_values(array_unique(array_merge((array)($options[$field]??[]),[$request['chat']])));
            update_option('bon_settings',$options);
            if (get_option('bon_settings',[])!==$options) { add_settings_error('bon_messages','pair','ذخیره اتصال ناموفق بود.','error');return; }
            delete_transient($key);delete_user_meta($uid,$option);
            add_settings_error('bon_messages','pair','حساب بله به مدیران مجاز و گیرندگان گزارش اضافه شد. در ربات /reports را بفرستید.','success');
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function render() {
        echo '<div class="bon-card"><h2>اتصال آسان حساب بله مدیر</h2><p>بدون واردکردن Chat ID: لینک را باز کنید، در ربات شروع را بزنید، سپس به این صفحه برگردید و حساب خود را تأیید کنید. این اتصال جای ورود امن وردپرس را نمی‌گیرد.</p>';
        echo '<form method="post">';wp_nonce_field('bon_save_settings','bon_settings_nonce');echo '<input type="hidden" name="bon_action" value="begin_admin_link"><button class="button">اتصال حساب بله من / لینک تازه</button></form>';
        $token=get_user_meta(get_current_user_id(),'bon_admin_pair_'.get_current_user_id(),true);
        $request=$token?get_transient(self::key($token)):false;
        if (!self::request_is_current($request,$token)) $request=false;
        $username=bon_get_option('bale_bot_username','');
        if ($request && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/D',$username)) echo '<p><a class="button button-primary" rel="noopener noreferrer" target="_blank" href="'.esc_url('https://ble.ir/'.$username.'?start=admin_'.$token).'">بازکردن ربات در بله</a></p>';
        elseif ($request) echo '<p>ابتدا نام کاربری ربات را در تنظیمات اتصال وارد کنید.</p>';
        if (!empty($request['chat'])) {
            echo '<p>حساب درخواست‌دهنده: '.esc_html($request['name']).' — شناسه '.esc_html($request['chat']).'</p><form method="post">';wp_nonce_field('bon_save_settings','bon_settings_nonce');
            echo '<input type="hidden" name="bon_action" value="approve_admin_link"><input type="hidden" name="pair_chat" value="'.esc_attr($request['chat']).'"><label><input type="checkbox" name="confirm_pair" required value="1"> این حساب خود من است و دسترسی مدیریتی آن را تأیید می‌کنم</label> <button class="button button-primary">تأیید اتصال مدیر</button></form>';
        }
        echo '<p>فقط حساب خودتان را تأیید کنید. حذف دسترسی و مقصد دریافت از فهرست‌های مدیران مجاز و Chat ID در همین صفحه انجام می‌شود.</p></div>';
    }
}
