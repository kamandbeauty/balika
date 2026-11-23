<?php
if (!defined('ABSPATH')) exit;

/** Private, text-only human support inbox. Identity is the private Bale chat, NEVER a billing phone. */
class Bale_Order_Notifier_Support {
    const THREAD = 'bon_support_thread';
    const MESSAGE = 'bon_support_message';
    public function __construct() {
        add_action('init', [$this, 'register_types']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_bon_support', [$this, 'admin_action']);
        add_action('bon_process_queue_event', [$this, 'cleanup'], 30);
    }
    public function register_types() {
        foreach ([self::THREAD, self::MESSAGE] as $type) register_post_type($type, ['public'=>false, 'publicly_queryable'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'rewrite'=>false, 'query_var'=>false, 'supports'=>[], 'can_export'=>false, 'map_meta_cap'=>false, 'capabilities'=>array_fill_keys(['read','read_post','edit_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts'],'manage_woocommerce')]);
    }
    private static function store($data) {
        $id=wp_insert_post(wp_slash($data),true);
        if(is_wp_error($id)||!$id)return new WP_Error('save','ذخیره ناموفق بود.');
        $post=get_post($id);$valid=$post && $post->post_type===$data['post_type'] && $post->post_status==='private';
        foreach(($data['meta_input']??[]) as $key=>$value) if((string)get_post_meta($id,$key,true)!==(string)$value)$valid=false;
        if(!$valid){wp_delete_post($id,true);return new WP_Error('meta','ذخیره کامل اطلاعات ناموفق بود.');}
        return $id;
    }
    public static function contact() {
        $saved=get_option('bon_support_contact',[]);$saved=is_array($saved)?$saved:[];
        return ['enabled'=>isset($saved['enabled'])?(bool)$saved['enabled']:(bool)get_option('bon_support_enabled',false),
            'mode'=>($saved['mode']??'direct')==='inbox'?'inbox':'direct',
            'url'=>self::contact_url($saved['url']??'')?:''];
    }
    public static function contact_url($value) {
        if(!is_string($value))return false;
        $value=trim($value);if($value==='')return '';
        if(!preg_match('~^(?:https://ble\.ir/|@)?([A-Za-z][A-Za-z0-9_]{2,63})/?$~iD',$value,$m))return false;
        return 'https://ble.ir/'.$m[1];
    }
    public static function enabled() { return self::contact()['enabled']; }
    public static function ready() { return self::enabled() && self::contact()['mode']==='inbox' && in_array('bale', bon_get_enabled_platforms(), true); }
    public static function text($value) {
        if (!is_string($value) || strlen($value)>8000) return false;
        $text = trim(sanitize_textarea_field($value));
        return preg_match('/^.{1,2000}$/usD', $text) ? $text : false;
    }
    public static function private_chat($message) {
        $id = $message['chat']['id'] ?? null; $actor = $message['from']['id'] ?? null;
        return is_scalar($id) && is_scalar($actor) && preg_match('/^[1-9][0-9]{0,19}$/D', (string)$id)
            && (string)$id === (string)$actor && ($message['chat']['type'] ?? 'private') === 'private';
    }
    private static function active($chat) {
        $ids = get_posts(['post_type'=>self::THREAD, 'post_status'=>'private', 'fields'=>'ids', 'posts_per_page'=>1, 'orderby'=>'ID', 'order'=>'DESC', 'meta_query'=>[['key'=>'_bon_chat','value'=>$chat],['key'=>'_bon_state','value'=>'open']]]);
        return $ids ? (int)$ids[0] : 0;
    }
    private static function chat_lock($chat) { global $wpdb; return 'bon_support_' . md5($wpdb->prefix . ':' . $chat); }
    private static function lock($key) { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)); }
    private static function unlock($key) { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    private static function say($chat, $text) {
        // Acknowledgements are not queued: a late acknowledgement must not misrepresent current state.
        if (in_array('bale', bon_get_enabled_platforms(), true)) (new Bale_Order_Notifier_API('bale'))->send_message($chat, $text, [], false);
    }
    public static function incoming($message) {
        $text = trim(is_string($message['text'] ?? null) ? $message['text'] : '');
        $command = preg_replace('/^\/(support|end_support|stop)@\S+$/', '/$1', $text);
        $is_support = in_array($command, ['/support','/end_support'], true);
        if (!self::private_chat($message)) return $is_support; // No private text or identity is relayed from groups.
        $chat = (string)$message['chat']['id'];
        if ($command === '/stop' || $command === '/end_support') {
            $id = self::active($chat);
            $result = $id ? self::close($id) : true;
            if ($command === '/end_support') self::say($chat, is_wp_error($result) ? $result->get_error_message() : 'گفت‌وگوی پشتیبانی بسته شد. برای گفت‌وگوی تازه /support را بفرستید.');
            // Let the existing /stop handler ALSO unlink order notifications, unless closing was busy.
            if (is_wp_error($result) && $command === '/stop') { self::say($chat, $result->get_error_message()); return true; }
            return $command === '/end_support';
        }
        // Direct contact never creates/forwards a ticket or resumes old queued replies.
        if (self::contact()['mode']==='direct') {
            if($command==='/support') {
                if(!in_array('bale',bon_get_enabled_platforms(),true))return true;
                $url=self::contact()['url'];
                if(!self::enabled() || !$url)self::say($chat,'لینک پشتیبانی فعلاً فعال یا تنظیم نشده است؛ از راه ارتباطی فروشگاه استفاده کنید.');
                else (new Bale_Order_Notifier_API('bale'))->send_message($chat,'برای گفت‌وگوی مستقیم با پشتیبانی روی دکمه زیر بزنید. پیام را در چت حساب پشتیبانی بنویسید؛ متن شما در این بات به پشتیبانی منتقل نمی‌شود.',[[['text'=>'گفت‌وگوی مستقیم با پشتیبانی','url'=>$url]]],false);
                return true;
            }
            if(strpos($text,'/')!==0 && self::active($chat)) { self::say($chat,'روش پشتیبانی به ارتباط مستقیم تغییر کرده است؛ این پیام ذخیره یا منتقل نشد. /support را بفرستید.');return true; }
            return false;
        }
        if (!self::ready()) {
            if ($command === '/support' || (strpos($text,'/')!==0 && self::active($chat))) { self::say($chat, 'پشتیبانی انسانی فعلاً غیرفعال است؛ پیام ثبت نشد. از راه ارتباطی فروشگاه استفاده کنید.'); return true; }
            return false;
        }
        if ($command !== '/support' && strpos($text, '/') === 0) return false;
        $lock = self::chat_lock($chat);
        if (!self::lock($lock)) { self::say($chat, 'گفت‌وگو در حال به‌روزرسانی است؛ پیام ثبت نشد، کمی بعد دوباره بفرستید.'); return true; }
        $notice = '';
        try {
            $id = self::active($chat);
            if (!$id && $command !== '/support') return false;
            if ($command === '/support') {
                if (!$id) {
                    $start_key='bon_support_start_'.md5($chat . ':' . (int)floor(time()/HOUR_IN_SECONDS));
                    if ((int)get_transient($start_key)>=5) { self::say($chat,'حداکثر ۵ گفت‌وگوی تازه در ساعت؛ کمی بعد دوباره تلاش کنید.'); return true; }
                    $id = self::store(['post_type'=>self::THREAD, 'post_status'=>'private', 'post_title'=>'گفت‌وگوی بله', 'meta_input'=>['_bon_chat'=>$chat, '_bon_state'=>'open']]);
                    if (is_wp_error($id) || !$id) { $notice='ایجاد گفت‌وگو ناموفق بود؛ دوباره تلاش کنید.'; }
                    else set_transient($start_key,(int)get_transient($start_key)+1,2*HOUR_IN_SECONDS);
                }
                if (!$notice) $notice='گفت‌وگوی پشتیبانی #' . $id . " باز است. پیام متنی بعدی شما در پنل ذخیره و برای مدیران تنظیم‌شده در بله ارسال می‌شود و پاسخ همینجا می‌آید؛ پاسخ فوری تضمین نیست.\nحداکثر ۲۰۰۰ نویسه؛ فعلاً فایل و صدا پذیرفته نمی‌شود. رمز، اطلاعات کارت یا اطلاعات حساس نفرستید. نسخه پنل از ۳۰ روز پس از بستن گفت‌وگو به‌تدریج پاک می‌شود؛ نسخه ارسال‌شده به بله مدیران تابع نگهداری آنان است.\nپایان گفت‌وگو: /end_support";
            } else {
                $body = self::text($message['text'] ?? null);
                $mid = $message['message_id'] ?? null;
                if ($body === false || !is_scalar($mid) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string)$mid)) { $notice='پیام ثبت نشد؛ فقط متن ۱ تا ۲۰۰۰ نویسه پشتیبانی می‌شود، نه فایل یا صدا.'; }
                else {
                    $key = hash('sha256', $chat . ':' . $mid);
                    $existing = get_posts(['post_type'=>self::MESSAGE, 'post_status'=>'private', 'fields'=>'ids', 'posts_per_page'=>1, 'meta_key'=>'_bon_inbound_key', 'meta_value'=>$key]);
                    if ($existing) { self::notify_admins((int)$existing[0]); return true; }
                    $rate = 'bon_support_rate_' . md5($chat . ':' . (int)floor(time()/MINUTE_IN_SECONDS));
                    $used = (int)get_transient($rate);
                    if ($used>=10) $notice='پیام ثبت نشد؛ حداکثر ۱۰ پیام در دقیقه. کمی بعد دوباره بفرستید.';
                    else {
                        $saved = self::store(['post_type'=>self::MESSAGE, 'post_status'=>'private', 'post_parent'=>$id, 'post_content'=>$body, 'post_title'=>'پیام مشتری', 'meta_input'=>['_bon_direction'=>'in', '_bon_inbound_key'=>$key]]);
                        if (is_wp_error($saved) || !$saved) $notice='ذخیره پیام ناموفق بود؛ دوباره تلاش کنید.';
                        else { self::notify_admins($saved); set_transient($rate,$used+1,2*MINUTE_IN_SECONDS); $notice='پیام شما در گفت‌وگوی #' . $id . ' ثبت شد؛ پاسخ اپراتور همینجا ارسال می‌شود.'; }
                    }
                }
            }
        } finally { self::unlock($lock); }
        if ($notice) self::say($chat,$notice);
        return true;
    }
    /** Private administrator destinations only; never copy support text to groups/channels. */
    public static function admin_recipients() {
        $ids=[];
        foreach(array_merge((array)bon_get_option('bale_admin_user_ids',[]),(array)bon_get_option('bale_chat_ids',[])) as $id) {
            if(!is_scalar($id))continue;
            $id=trim((string)$id);
            if(preg_match('/^[1-9][0-9]{0,19}$/D',$id))$ids[$id]=$id;
        }
        return array_slice(array_values($ids),0,30);
    }
    private static function inbound_thread($mid) {
        $msg=get_post($mid);
        if(!$msg || $msg->post_type!==self::MESSAGE || $msg->post_status!=='private' || get_post_meta($mid,'_bon_direction',true)!=='in')return false;
        $thread=get_post($msg->post_parent);
        return $thread && $thread->post_type===self::THREAD && $thread->post_status==='private' && get_post_meta($thread->ID,'_bon_state',true)==='open' ? $thread : false;
    }
    public static function can_deliver_admin($type,$payload) {
        if(!self::ready() || !is_array($payload) || !preg_match('/^support_admin_([1-9][0-9]*)_([1-9][0-9]{0,19})$/D',$type,$m))return false;
        $thread=self::inbound_thread((int)$m[1]);
        return $thread && (string)($payload['chat_id']??'')===$m[2]
            && in_array($m[2],self::admin_recipients(),true)
            && (string)get_post_meta($thread->ID,'_bon_chat',true)!==$m[2];
    }
    private static function notify_admins($mid,$retry=false) {
        global $wpdb;
        $thread=self::inbound_thread($mid);
        if(!self::ready() || !$thread)return new WP_Error('disabled','حالت صندوق یا بله فعال نیست، یا گفت‌وگو بسته است.');
        $chat=(string)get_post_meta($thread->ID,'_bon_chat',true);
        $ids=array_values(array_diff(self::admin_recipients(),[$chat]));
        if(!$ids){update_post_meta($mid,'_bon_admin_notice','مقصد خصوصی مدیر تنظیم نشده است.');return new WP_Error('recipient','شناسه خصوصی مدیر را در تنظیمات اتصال بله ثبت کنید.');}
        $stored=get_post_meta($mid,'_bon_admin_enqueued',true);$accepted=is_array($stored)?$stored:[];
        $added=0;$failed=0;$table=Bale_Order_Notifier_Queue::table_name();$msg=get_post($mid);
        foreach($ids as $id) {
            $type='support_admin_'.$mid.'_'.$id;
            $old=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE message_type=%s ORDER BY id DESC LIMIT 1",$type));
            if($old) {
                if($retry && in_array($old->status,['paused','dead-letter'],true)) {
                    if($wpdb->update($table,['status'=>'pending','attempts'=>0,'next_attempt'=>current_time('mysql')],['id'=>$old->id])===false)$failed++;else $added++;
                }
                continue;
            }
            if(in_array($id,$accepted,true))continue; // Retention is not permission to send the old text again.
            $url=add_query_arg(['page'=>'balika-support','thread'=>$thread->ID],admin_url('admin.php'));
            $params=['chat_id'=>$id,'text'=>'پیام مشتری — پشتیبانی #'.$thread->ID."\nچت مشتری: ".$chat."\n\n".$msg->post_content."\n\nپاسخ از پیشخوان؛ پاسخ‌دادن به این پیام در بله به مشتری منتقل نمی‌شود.",
                'reply_markup'=>wp_json_encode(['inline_keyboard'=>[[['text'=>'مشاهده و پاسخ در پیشخوان','url'=>$url]]]])];
            if(Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',$params,0,$type)) {
                $accepted[]=$id;update_post_meta($mid,'_bon_admin_enqueued',array_values(array_unique($accepted)));$added++;
            } else $failed++;
        }
        $notice=$failed?'ثبت ارسال به بعضی مدیران ناموفق بود؛ از تلاش مجدد استفاده کنید.':($added?'ارسال متن به مدیران در صف ثبت شد؛ تحویل هنوز تأیید نشده است.':'ارسال جدیدی ثبت نشد؛ پیام منتظر/موفق یا دارای سابقه قبلی تکرار نمی‌شود.');
        update_post_meta($mid,'_bon_admin_notice',$notice);
        return $failed?new WP_Error('queue',$notice):$notice;
    }
    public static function can_deliver($message_id) {
        if (!self::ready()) return false;
        $msg = get_post($message_id);
        if (!$msg || $msg->post_type!==self::MESSAGE || $msg->post_status!=='private' || get_post_meta($message_id,'_bon_direction',true)!=='out') return false;
        $thread = get_post($msg->post_parent);
        return $thread && $thread->post_type===self::THREAD && $thread->post_status==='private'
            && get_post_meta($thread->ID,'_bon_state',true)==='open'
            && preg_match('/^[1-9][0-9]{0,19}$/D',(string)get_post_meta($thread->ID,'_bon_chat',true));
    }
    public static function close($id) {
        global $wpdb;
        if (get_post_type($id)!==self::THREAD) return new WP_Error('missing','گفت‌وگو یافت نشد.');
        $worker = 'bon_queue_' . md5(Bale_Order_Notifier_Queue::table_name());
        $chat_lock = self::chat_lock((string)get_post_meta($id,'_bon_chat',true));
        if (!self::lock($worker)) return new WP_Error('busy','ارسال در حال انجام است؛ گفت‌وگو بسته نشد، دوباره تلاش کنید.');
        $locked=false;
        try {
            $locked=self::lock($chat_lock);
            if (!$locked) return new WP_Error('busy','گفت‌وگو در حال تغییر است؛ دوباره تلاش کنید.');
            update_post_meta($id,'_bon_state','closed');
            if (get_post_meta($id,'_bon_state',true)!=='closed') return new WP_Error('save','بستن گفت‌وگو ذخیره نشد.');
            update_post_meta($id,'_bon_closed_at',time());
            $table=Bale_Order_Notifier_Queue::table_name();
            $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET status='cancelled' WHERE status IN ('pending','paused','dead-letter') AND (message_type IN (SELECT CONCAT('support_', ID) FROM {$wpdb->posts} WHERE post_type=%s AND post_parent=%d) OR SUBSTRING_INDEX(message_type,'_',3) IN (SELECT CONCAT('support_admin_', ID) FROM {$wpdb->posts} WHERE post_type=%s AND post_parent=%d))",self::MESSAGE,$id,self::MESSAGE,$id));
            if ($changed===false) return new WP_Error('queue','گفت‌وگو بسته شد؛ پاک‌سازی صف ناموفق بود. کنترل تحویل مانع ارسال پاسخ‌های بسته‌شده می‌شود.');
            return true;
        } finally { if($locked)self::unlock($chat_lock);self::unlock($worker); }
    }
    private static function enqueue_reply($id) {
        global $wpdb;
        if (!self::can_deliver($id)) return new WP_Error('disabled','حالت صندوق یا بله فعال نیست، یا گفت‌وگو بسته است.');
        $msg=get_post($id);$chat=(string)get_post_meta($msg->post_parent,'_bon_chat',true);
        $table=Bale_Order_Notifier_Queue::table_name();
        $old=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE message_type=%s ORDER BY id DESC LIMIT 1",'support_'.$id));
        if ($old) {
            if (!in_array($old->status,['paused','dead-letter'],true)) return new WP_Error('exists','پاسخ قبلاً ثبت شده؛ پیام موفق یا منتظر دوباره ساخته نمی‌شود.');
            return $wpdb->update($table,['status'=>'pending','attempts'=>0,'next_attempt'=>current_time('mysql')],['id'=>$old->id])!==false;
        }
        if (get_post_meta($id,'_bon_enqueued',true)) return new WP_Error('expired','سابقه صف این پاسخ موجود نیست؛ ارسال مجدد کور مجاز نیست.');
        $ok=Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>$chat,'text'=>'پاسخ پشتیبانی #' . $msg->post_parent . ":\n\n" . $msg->post_content],0,'support_'.$id);
        if ($ok) update_post_meta($id,'_bon_enqueued',1);
        return $ok;
    }
    private function guard() { if(!current_user_can('manage_woocommerce'))wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    public function menu() { add_submenu_page('balika','پشتیبانی انسانی','پشتیبانی انسانی','manage_woocommerce','balika-support',[$this,'page']); }
    private function finish($text,$id=0) { set_transient('bon_support_notice_'.get_current_user_id(),$text,5*MINUTE_IN_SECONDS);wp_safe_redirect(add_query_arg(['page'=>'balika-support','thread'=>$id],admin_url('admin.php')));exit; }
    public function admin_action() {
        $this->guard();check_admin_referer('bon_support');
        $op=sanitize_key($_POST['operation']??'');$id=absint($_POST['thread']??0);
        if($op==='settings') {
            $worker='bon_queue_'.md5(Bale_Order_Notifier_Queue::table_name());
            if(!self::lock($worker))$this->finish('ارسال در حال انجام است؛ تنظیم تغییر نکرد، دوباره تلاش کنید.');
            try {
                $current=self::contact();$mode=$_POST['mode']??$current['mode'];$url=self::contact_url(wp_unslash($_POST['contact_url']??$current['url']));$enabled=!empty($_POST['enabled']);
                if(!in_array($mode,['direct','inbox'],true)||$url===false)$notice='روش پشتیبانی یا آیدی/لینک بله معتبر نیست؛ تنظیمات تغییر نکرد.';
                elseif($enabled && $mode==='direct' && !$url)$notice='برای فعال‌کردن ارتباط مستقیم، آیدی یا لینک حساب پشتیبانی را وارد کنید.';
                else {$value=['enabled'=>$enabled,'mode'=>$mode,'url'=>$url];update_option('bon_support_contact',$value,false);$notice=self::contact()===$value?'تنظیم ذخیره شد؛ پاسخ‌های متوقف‌شده فقط در حالت صندوق و با تلاش مجدد صریح ادامه می‌یابند.':'ذخیره تنظیمات ناموفق بود.';}
            }
            finally {self::unlock($worker);}
            $this->finish($notice);
        }
        if(get_post_type($id)!==self::THREAD)$this->finish('گفت‌وگو یافت نشد.');
        if($op==='close') { $result=self::close($id);$this->finish(is_wp_error($result)?$result->get_error_message():'گفت‌وگو بسته شد؛ پاسخ‌های ارسال‌نشده لغو شدند. پیام تحویل‌داده‌شده پس گرفته نمی‌شود.',$id); }
        if(!in_array($op,['reply','retry','notify_admin'],true))$this->finish('عملیات نامعتبر است.',$id);
        $chat=(string)get_post_meta($id,'_bon_chat',true);$lock=self::chat_lock($chat);
        if(!self::lock($lock))$this->finish('گفت‌وگو در حال تغییر است؛ دوباره تلاش کنید.',$id);
        $notice='پاسخ ثبت نشد.';
        try {
            if(!self::ready() || get_post_meta($id,'_bon_state',true)!=='open')$notice='حالت صندوق یا بله فعال نیست، یا گفت‌وگو بسته است.';
            elseif($op==='notify_admin') {
                $mid=absint($_POST['message']??0);$msg=get_post($mid);
                $result=$msg && (int)$msg->post_parent===$id ? self::notify_admins($mid,true) : new WP_Error('missing','پیام متعلق به این گفت‌وگو نیست.');
                $notice=is_wp_error($result)?$result->get_error_message():$result;
            }
            elseif($op==='retry') {
                $mid=absint($_POST['message']??0);$msg=get_post($mid);
                $result=$msg && (int)$msg->post_parent===$id ? self::enqueue_reply($mid) : new WP_Error('missing','پیام متعلق به این گفت‌وگو نیست.');
                $notice=is_wp_error($result)?$result->get_error_message():($result?'پاسخ برای ارسال در صف ثبت شد؛ تحویل هنوز تأیید نشده است.':'ثبت صف ناموفق بود.');
            } else {
                $body=self::text(wp_unslash($_POST['body']??''));$token=$_POST['once']??'';
                $key='bon_support_once_'.get_current_user_id().'_'.hash('sha256',is_string($token)?$token:'');
                if($body===false)$notice='متن معتبر ۱ تا ۲۰۰۰ نویسه وارد کنید.';
                elseif(!is_string($token) || !preg_match('/^[A-Za-z0-9]{32}$/D',$token) || (int)get_transient($key)!==$id)$notice='فرم منقضی یا قبلاً ارسال شده است؛ صفحه را تازه کنید.';
                else {
                    global $wpdb;
                    $used=wp_using_ext_object_cache()?wp_cache_add($key.'_used',1,'bon',HOUR_IN_SECONDS):$wpdb->delete($wpdb->options,['option_name'=>'_transient_'.$key]);delete_transient($key);
                    if(!$used)$notice='این فرم قبلاً پردازش شده است.';
                    else {
                        $mid=self::store(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'post_author'=>get_current_user_id(),'post_title'=>'پاسخ اپراتور','post_content'=>$body,'meta_input'=>['_bon_direction'=>'out']]);
                        if(is_wp_error($mid)||!$mid)$notice='ذخیره پاسخ ناموفق بود؛ صفحه را تازه کنید.';
                        else { $result=self::enqueue_reply($mid);$notice=is_wp_error($result)?$result->get_error_message():($result?'پاسخ ثبت و در صف ارسال قرار گرفت؛ تحویل هنوز تأیید نشده است.':'پاسخ ذخیره شد ولی صف ناموفق بود؛ از تلاش مجدد همان پاسخ استفاده کنید.'); }
                    }
                }
            }
        } finally { self::unlock($lock); }
        $this->finish($notice,$id);
    }
    private function form($op,$id=0) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bon_support');
        echo '<input type="hidden" name="action" value="bon_support"><input type="hidden" name="operation" value="'.esc_attr($op).'"><input type="hidden" name="thread" value="'.(int)$id.'">';
    }
    public function page() {
        $this->guard();echo '<div class="wrap" dir="rtl"><h1>پشتیبانی انسانی بله</h1>';
        $key='bon_support_notice_'.get_current_user_id();if($notice=get_transient($key)){delete_transient($key);echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';}
        $contact=self::contact();$this->form('settings');
        echo '<p><label><input type="checkbox" name="enabled" '.checked(self::enabled(),true,false).'> فعال‌سازی پشتیبانی</label></p><p><label>روش پشتیبانی <select name="mode"><option value="direct" '.($contact['mode']==='direct'?'selected':'').'>ارتباط مستقیم با حساب بله پشتیبانی (پیشنهادی)</option><option value="inbox" '.($contact['mode']==='inbox'?'selected':'').'>صندوق وردپرس و ارسال رونوشت به مدیران</option></select></label></p>';
        echo '<p><label>آیدی یا لینک حساب بله پشتیبانی <input type="text" dir="ltr" class="regular-text" name="contact_url" maxlength="100" value="'.esc_attr($contact['url']).'" placeholder="@YourSupport یا https://ble.ir/YourSupport"></label></p><p>در حالت مستقیم، /support فقط دکمه ورود به این حساب را می‌دهد؛ گفت‌وگو در بله انجام می‌شود و پیام‌ها در وردپرس ذخیره یا منتقل نمی‌شوند. حساب شخص پشتیبان را وارد کنید، نه گروه، کانال یا همین بات. وجود و مالکیت حساب از روی لینک قابل تأیید نیست.</p>';
        if($contact['url'])echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="'.esc_url($contact['url']).'">بازکردن لینک پشتیبانی برای بررسی</a></p>';
        submit_button('ذخیره تنظیمات پشتیبانی');echo '</form><p>حالت صندوق، قابلیت قبلی را نگه می‌دارد. تغییر به ارتباط مستقیم، گفت‌وگوهای قبلی را حذف نمی‌کند، ولی ارسال پاسخ و رونوشت صف آن‌ها متوقف می‌شود. برای دریافت پیام تازه در صندوق، صفحه را تازه کنید.</p>';
        echo '<p>متن مشتری به شناسه‌های خصوصی مدیر در تنظیمات اتصال بله ارسال می‌شود؛ گروه و کانال حذف می‌شوند. مقصدهای فعلی: <code>'.esc_html(implode('، ',self::admin_recipients())?:'تنظیم نشده').'</code>. <a href="'.esc_url(admin_url('admin.php?page=balika&tab=platforms')).'">تنظیم مقصد مدیران</a></p>';
        $id=absint($_GET['thread']??0);
        if($id && get_post_type($id)===self::THREAD) {
            $state=get_post_meta($id,'_bon_state',true);echo '<h2>گفت‌وگو #'.(int)$id.' — '.esc_html($state==='open'?'باز':'بسته').'</h2><p>چت بله: <code>'.esc_html(get_post_meta($id,'_bon_chat',true)).'</code> — هویت تلفنی یا مالکیت سفارش تأیید نشده است.</p>';
            echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'balika-support','thread'=>$id],admin_url('admin.php'))).'">تازه‌سازی پیام‌ها</a></p>';
            $page=max(1,absint($_GET['mpage']??1));$query=new WP_Query(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'posts_per_page'=>20,'paged'=>$page,'orderby'=>'ID','order'=>'DESC']);
            global $wpdb;$table=Bale_Order_Notifier_Queue::table_name();
            foreach(array_reverse($query->posts) as $msg) {
                $out=get_post_meta($msg->ID,'_bon_direction',true)==='out';echo '<div class="bon-card"><strong>'.($out?'اپراتور #'.(int)$msg->post_author:'مشتری').'</strong> — '.esc_html($msg->post_date).'<p style="white-space:pre-wrap">'.esc_html($msg->post_content).'</p>';
                if($out){$row=$wpdb->get_row($wpdb->prepare("SELECT status,last_error FROM {$table} WHERE message_type=%s ORDER BY id DESC LIMIT 1",'support_'.$msg->ID));$labels=['pending'=>'منتظر ارسال','sent'=>'پذیرفته‌شده توسط API بله؛ نه لزوماً خوانده‌شده','paused'=>'متوقف','cancelled'=>'لغوشده','dead-letter'=>'ناموفق'];echo '<p>'.esc_html($row?($labels[$row->status]??$row->status):'سابقه صف موجود نیست؛ موفقیت معلوم نیست').'</p>';if($row && $row->last_error)echo '<p>'.esc_html($row->last_error).'</p>';if($state==='open' && $contact['mode']==='inbox' && ((!$row && !get_post_meta($msg->ID,'_bon_enqueued',true)) || ($row && in_array($row->status,['paused','dead-letter'],true)))){$this->form('retry',$id);echo '<input type="hidden" name="message" value="'.(int)$msg->ID.'"><button class="button">تلاش مجدد همین پاسخ</button></form>';}}
                if(!$out){echo '<p>'.esc_html(get_post_meta($msg->ID,'_bon_admin_notice',true)?:'وضعیت ارسال به مدیر ثبت نشده است.').'</p>';if($state==='open' && $contact['mode']==='inbox'){$this->form('notify_admin',$id);echo '<input type="hidden" name="message" value="'.(int)$msg->ID.'"><button class="button">تلاش مجدد ارسال به مدیران بله</button></form>';}}
                echo '</div>';
            }
            echo wp_kses_post(paginate_links(['base'=>add_query_arg(['page'=>'balika-support','thread'=>$id,'mpage'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$page,'total'=>$query->max_num_pages]));
            if($state==='open' && $contact['mode']==='inbox'){$once=wp_generate_password(32,false,false);set_transient('bon_support_once_'.get_current_user_id().'_'.hash('sha256',$once),$id,HOUR_IN_SECONDS);$this->form('reply',$id);echo '<input type="hidden" name="once" value="'.esc_attr($once).'"><textarea name="body" rows="5" class="large-text" maxlength="2000" required aria-label="پاسخ اپراتور"></textarea>';submit_button('ثبت پاسخ برای ارسال');echo '</form>';}if($state==='open'){$this->form('close',$id);submit_button('بستن گفت‌وگو و لغو پاسخ‌های منتظر','secondary');echo '</form>';}
        }
        $page=max(1,absint($_GET['paged']??1));$threads=new WP_Query(['post_type'=>self::THREAD,'post_status'=>'private','posts_per_page'=>20,'paged'=>$page,'orderby'=>'ID','order'=>'DESC']);echo '<h2>گفت‌وگوها</h2><table class="widefat"><tr><th>شماره</th><th>چت</th><th>وضعیت</th></tr>';
        foreach($threads->posts as $thread)echo '<tr><td><a href="'.esc_url(add_query_arg(['page'=>'balika-support','thread'=>$thread->ID],admin_url('admin.php'))).'">#'.(int)$thread->ID.'</a></td><td>'.esc_html(get_post_meta($thread->ID,'_bon_chat',true)).'</td><td>'.esc_html(get_post_meta($thread->ID,'_bon_state',true)==='open'?'باز':'بسته').'</td></tr>';
        echo '</table>'.wp_kses_post(paginate_links(['base'=>add_query_arg(['page'=>'balika-support','paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$page,'total'=>$threads->max_num_pages])).'<p>گفت‌وگوهای بسته پس از ۳۰ روز به‌تدریج پاک می‌شوند؛ گفت‌وگوهای باز تا بسته‌شدن نگهداری می‌شوند. برای کاهش دسترسی به اطلاعات، فقط به اپراتور مجاز نقش مدیریت ووکامرس بدهید.</p></div>';
    }
    public function cleanup() {
        $ids=get_posts(['post_type'=>self::THREAD,'post_status'=>'private','fields'=>'ids','posts_per_page'=>5,'meta_query'=>[['key'=>'_bon_state','value'=>'closed'],['key'=>'_bon_closed_at','value'=>time()-30*DAY_IN_SECONDS,'compare'=>'<','type'=>'NUMERIC']]]);
        foreach($ids as $id){$messages=get_posts(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'fields'=>'ids','posts_per_page'=>100]);foreach($messages as $mid)wp_delete_post($mid,true);if(count($messages)<100)wp_delete_post($id,true);}
    }
}
