<?php
if (!defined('ABSPATH')) exit;

/** Optional channel destinations and bounded scheduled publications, using the existing Bale queue. */
class Bale_Order_Notifier_Channels {
    public function __construct() {
        add_action('init', [$this, 'register_type']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_bon_commerce_admin', [$this, 'handle']);
        add_action('bon_process_queue_event', [$this, 'dispatch_due'], 5);
    }
    public function register_type() {
        register_post_type('bon_channel_job', ['label'=>'انتشار بله', 'public'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'rewrite'=>false, 'query_var'=>false, 'supports'=>[]]);
    }
    public function menu() {
        add_submenu_page('balika', 'فروشگاه و کانال‌های بله', 'فروشگاه و کانال‌ها', 'manage_woocommerce', 'balika-commerce', [$this, 'page']);
    }
    public static function channels() { return (array) get_option('bon_channels', []); }
    public static function valid_destination($value) {
        return is_string($value) && (bool) preg_match('/^(?:-?[1-9][0-9]{0,19}|@[A-Za-z][A-Za-z0-9_]{3,63})$/D', $value);
    }
    public static function source($id) {
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['product', 'post'], true) || $post->post_status !== 'publish' || $post->post_password !== '') return false;
        if ($post->post_type === 'product' && !Bale_Order_Notifier_Commerce::public_product(wc_get_product($id))) return false;
        return $post;
    }
    public static function scheduled_time($input) {
        if (!is_string($input)) return new WP_Error('time', 'زمان نامعتبر است.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, wp_timezone());
        if (!$date || $date->format('Y-m-d\TH:i') !== $input || $date->getTimestamp() < time() - 60 || $date->getTimestamp() > time() + 366 * DAY_IN_SECONDS) return new WP_Error('time', 'زمان معتبر از اکنون تا یک سال آینده انتخاب کنید.');
        return max(time(), $date->getTimestamp());
    }
    public static function message($source) {
        $id = $source->ID;
        if ($source->post_type === 'product') {
            $product = wc_get_product($id);
            $data = Bale_Order_Notifier_Commerce::product_data($product);
            $text = $data['name'] . "\n" . $data['price'] . "\n" . $data['description'];
            $url = Bale_Order_Notifier_Commerce::enabled('storefront') ? Bale_Order_Notifier_Commerce::url($id) : get_permalink($id);
            $label = 'مشاهده و خرید';
        } else {
            $text = get_the_title($id) . "\n\n" . wp_trim_words(wp_strip_all_tags(strip_shortcodes($source->post_excerpt ?: $source->post_content)), 70, '…');
            $url = get_permalink($id); $label = 'خواندن نوشته';
        }
        return ['text'=>html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8'), 'reply_markup'=>wp_json_encode(['inline_keyboard'=>[[['text'=>$label, 'url'=>$url]]]])];
    }
    public static function can_deliver($job_id) {
        if (!Bale_Order_Notifier_Commerce::enabled('channels') || !in_array('bale', bon_get_enabled_platforms(), true)) return false;
        if (get_post_type($job_id) !== 'bon_channel_job' || get_post_meta($job_id, '_bon_state', true) !== 'queued') return false;
        $channel = self::channels()[get_post_meta($job_id, '_bon_channel', true)] ?? null;
        if (!$channel || empty($channel['enabled']) || $channel['destination'] !== get_post_meta($job_id, '_bon_destination', true)) return false;
        return (bool) self::source((int) get_post_meta($job_id, '_bon_source', true));
    }
    public function dispatch_due() {
        if (!Bale_Order_Notifier_Commerce::enabled('channels') || !in_array('bale', bon_get_enabled_platforms(), true)) return;
        $ids = get_posts([
            'post_type'=>'bon_channel_job', 'post_status'=>'private', 'posts_per_page'=>10, 'fields'=>'ids',
            'meta_key'=>'_bon_publish_at', 'orderby'=>'meta_value_num', 'order'=>'ASC',
            'meta_query'=>[['key'=>'_bon_state', 'value'=>'scheduled'], ['key'=>'_bon_publish_at', 'value'=>time(), 'compare'=>'<=', 'type'=>'NUMERIC']],
        ]);
        foreach ($ids as $id) $this->dispatch($id);
    }
    public function dispatch($id) {
        global $wpdb;
        $lock = 'bon_channel_' . md5($wpdb->prefix . ':' . (int) $id);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) return;
        try {
            if (!Bale_Order_Notifier_Commerce::enabled('channels') || !in_array('bale', bon_get_enabled_platforms(), true) || get_post_type($id) !== 'bon_channel_job' || get_post_meta($id, '_bon_state', true) !== 'scheduled') return;
            if ((int) get_post_meta($id, '_bon_publish_at', true) > time()) return;
            $channel = self::channels()[get_post_meta($id, '_bon_channel', true)] ?? null;
            $source = self::source((int) get_post_meta($id, '_bon_source', true));
            if (!$channel || empty($channel['enabled']) || $channel['destination'] !== get_post_meta($id, '_bon_destination', true) || !$source) {
                update_post_meta($id, '_bon_state', 'blocked'); return;
            }
            $table = Bale_Order_Notifier_Queue::table_name();
            // Check the outbox before enqueueing: safe recovery after a crash between insert and metadata update.
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE message_type = %s LIMIT 1", 'channel_' . $id));
            if (!$exists) {
                $params = self::message($source);
                $params['chat_id'] = $channel['destination'];
                if (!Bale_Order_Notifier_Queue::enqueue('bale', 'sendMessage', $params, 0, 'channel_' . $id)) {
                    update_post_meta($id, '_bon_error', 'ثبت در صف ناموفق؛ در اجرای بعدی دوباره تلاش می‌شود.'); return;
                }
            }
            update_post_meta($id, '_bon_state', 'queued');
            update_post_meta($id, '_bon_error', '');
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    private function guard() { if (!current_user_can('manage_woocommerce')) wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    private function finish($message) {
        set_transient('bon_commerce_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=balika-commerce')); exit;
    }
    public function handle() {
        $this->guard(); check_admin_referer('bon_commerce_admin');
        $action = sanitize_key($_POST['operation'] ?? '');
        if ($action === 'settings') {
            $settings = ['storefront'=>!empty($_POST['storefront']), 'bot'=>!empty($_POST['bot']), 'channels'=>!empty($_POST['channels']), 'direct_cart'=>!empty($_POST['direct_cart']), 'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? get_bloginfo('name')))];
            update_option('bon_commerce_settings', $settings, false);
            $this->finish('تنظیمات ماژول‌ها ذخیره شد. پرداخت و ورود بومی بله همچنان غیرفعال هستند.');
        }
        if ($action === 'add_channel') {
            $channels = self::channels();
            if (count($channels) >= 30) $this->finish('حداکثر ۳۰ مقصد پشتیبانی می‌شود.');
            $destination = sanitize_text_field(wp_unslash($_POST['destination'] ?? ''));
            $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
            if (!$label || strlen($label) > 200 || !self::valid_destination($destination)) $this->finish('نام و شناسه کانال معتبر وارد کنید؛ شناسه عددی یا @username.');
            foreach ($channels as $channel) if ($channel['destination'] === $destination) $this->finish('این مقصد قبلاً ثبت شده است.');
            $channels[wp_generate_uuid4()] = ['label'=>$label, 'destination'=>$destination, 'enabled'=>true];
            update_option('bon_channels', $channels, false); $this->finish('مقصد ثبت شد. بات باید در بله اجازه انتشار در این کانال را داشته باشد.');
        }
        if (in_array($action, ['toggle_channel', 'delete_channel', 'test_channel'], true)) {
            $channels = self::channels(); $key = sanitize_text_field($_POST['channel'] ?? '');
            if (!isset($channels[$key])) $this->finish('مقصد یافت نشد.');
            if ($action === 'test_channel') {
                if (!Bale_Order_Notifier_Commerce::enabled('channels') || !in_array('bale', bon_get_enabled_platforms(), true) || empty($channels[$key]['enabled'])) $this->finish('ابتدا ماژول کانال و مقصد را فعال کنید.');
                $result = (new Bale_Order_Notifier_API('bale'))->send_message($channels[$key]['destination'], '✅ پیام آزمایشی اتصال کانال از بالیکا', [], false);
                $this->finish($result ? 'API بله پیام آزمایشی را پذیرفت.' : 'تست ناموفق؛ دسترسی انتشار بات، شناسه مقصد و توکن را بررسی کنید.');
            }
            if ($action === 'delete_channel') {
                if (empty($_POST['confirm'])) $this->finish('حذف مقصد نیاز به تأیید دارد.');
                unset($channels[$key]);
            } else $channels[$key]['enabled'] = empty($channels[$key]['enabled']);
            update_option('bon_channels', $channels, false); $this->finish('مقصد به‌روز شد؛ پیام‌های در حال ارسال قابل پس‌گرفتن نیستند.');
        }
        if ($action === 'schedule') {
            if (!Bale_Order_Notifier_Commerce::enabled('channels')) $this->finish('ماژول کانال غیرفعال است.');
            $channel_key = sanitize_text_field($_POST['channel'] ?? '');
            $channel = self::channels()[$channel_key] ?? null;
            $source = self::source(absint($_POST['source'] ?? 0));
            if (!$channel || empty($channel['enabled']) || !$source || !current_user_can('edit_post', $source->ID)) $this->finish('محصول یا نوشته عمومی و مقصد فعالِ مجاز انتخاب کنید.');
            $time = self::scheduled_time(wp_unslash($_POST['publish_at'] ?? ''));
            if (is_wp_error($time)) $this->finish($time->get_error_message());
            $token = isset($_POST['schedule_token']) && is_string($_POST['schedule_token']) ? $_POST['schedule_token'] : '';
            $token_key = 'bon_channel_once_' . get_current_user_id() . '_' . hash('sha256', $token);
            if (!preg_match('/^[A-Za-z0-9]{32}$/D', $token) || !get_transient($token_key)) $this->finish('فرم منقضی یا قبلاً ثبت شده؛ صفحه را تازه کنید.');
            global $wpdb;
            if (wp_using_ext_object_cache()) $used = wp_cache_add($token_key . '_used', 1, 'bon', HOUR_IN_SECONDS);
            else $used = $wpdb->delete($wpdb->options, ['option_name'=>'_transient_' . $token_key]);
            delete_transient($token_key);
            if (!$used) $this->finish('این فرم قبلاً پردازش شده است.');
            $id = wp_insert_post(['post_type'=>'bon_channel_job', 'post_status'=>'private', 'post_title'=>get_the_title($source->ID), 'post_author'=>get_current_user_id(), 'meta_input'=>['_bon_state'=>'scheduled', '_bon_source'=>$source->ID, '_bon_channel'=>$channel_key, '_bon_destination'=>$channel['destination'], '_bon_publish_at'=>$time]], true);
            if (is_wp_error($id) || !$id) $this->finish('ذخیره برنامه ناموفق بود.');
            $this->finish('برنامه ثبت شد؛ انتشار در اولین اجرای صف پس از زمان انتخابی انجام می‌شود.');
        }
        if (in_array($action, ['cancel_job', 'resume_job'], true)) {
            $id = absint($_POST['job'] ?? 0);
            if (get_post_type($id) !== 'bon_channel_job') $this->finish('برنامه یافت نشد.');
            if (empty($_POST['confirm'])) $this->finish('تأیید عملیات لازم است.');
            global $wpdb;
            $table = Bale_Order_Notifier_Queue::table_name();
            $queue_lock = 'bon_queue_' . md5($table);
            $job_lock = 'bon_channel_' . md5($wpdb->prefix . ':' . $id);
            if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $queue_lock))) $this->finish('صف در حال ارسال است؛ دوباره تلاش کنید.');
            $locked = false; $message = 'برنامه در حال پردازش است؛ دوباره تلاش کنید.';
            try {
                $locked = (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $job_lock));
                if ($locked) {
                    if ($action === 'cancel_job') {
                        update_post_meta($id, '_bon_state', 'cancelled');
                        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'cancelled' WHERE message_type = %s AND status IN ('pending','paused','dead-letter')", 'channel_' . $id));
                        $message = 'انتشارهای ارسال‌نشده لغو شدند؛ پیام ارسال‌شده یا قطعه ارسال‌شده حذف نمی‌شود.';
                    } else {
                        $state = get_post_meta($id, '_bon_state', true);
                        if ($state === 'cancelled') $message = 'برنامه لغوشده قابل ادامه نیست؛ برنامه جدید بسازید.';
                        elseif ($state === 'blocked') {
                            update_post_meta($id, '_bon_state', 'scheduled'); $message = 'برنامه برای اعتبارسنجی دوباره فعال شد.';
                        } elseif (self::can_deliver($id)) {
                            $count = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'pending', attempts = 0, next_attempt = %s WHERE message_type = %s AND status IN ('paused','dead-letter')", current_time('mysql'), 'channel_' . $id));
                            $message = $count ? 'پیام‌های متوقف یا ناموفق برای ادامه ثبت شدند. متن از زمان ورود به صف ثابت می‌ماند.' : 'پیام متوقف یا ناموفقی برای ادامه وجود ندارد؛ پیام‌های موفق دوباره ارسال نمی‌شوند.';
                        } else $message = 'ماژول، مقصد یا محتوای برنامه هنوز مجاز نیست.';
                    }
                }
            } finally {
                if ($locked) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $job_lock));
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $queue_lock));
            }
            $this->finish($message);
        }
        $this->finish('عملیات نامعتبر است.');
    }
    private function form($op, $fields = []) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:4px">';
        wp_nonce_field('bon_commerce_admin');
        echo '<input type="hidden" name="action" value="bon_commerce_admin"><input type="hidden" name="operation" value="' . esc_attr($op) . '">';
        foreach ($fields as $key=>$value) echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
    }
    public function page() {
        $this->guard(); $settings = Bale_Order_Notifier_Commerce::settings(); $channels = self::channels();
        echo '<div class="wrap" dir="rtl"><h1>فروشگاه مستقل و کانال‌های بله</h1>';
        $key = 'bon_commerce_notice_' . get_current_user_id();
        if ($notice = get_transient($key)) { delete_transient($key); echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        echo '<div class="bon-card"><h2>ماژول‌های اختیاری</h2><p>همه ماژول‌ها پیش‌فرض خاموش‌اند. رابط فروشگاه مستقل از قالب است؛ تسویه‌حساب در صفحه استاندارد ووکامرس انجام می‌شود.</p>';
        $this->form('settings');
        foreach (['storefront'=>'فروشگاه React مستقل', 'direct_cart'=>'افزودن مستقیم محصولات ساده به سبد (ابتدا با افزونه‌های محصول تست کنید)', 'bot'=>'کاتالوگ ربات: /shop، /products، /search و /product', 'channels'=>'مدیریت انتشار زمان‌دار کانال‌ها'] as $key=>$label) echo '<p><label><input type="checkbox" name="' . esc_attr($key) . '" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label></p>';
        echo '<p><label>نام فروشگاه <input name="name" value="' . esc_attr($settings['name'] ?? get_bloginfo('name')) . '"></label></p><button class="button button-primary">ذخیره ماژول‌ها</button></form><p>آدرس فروشگاه: <a target="_blank" rel="noopener" href="' . esc_url(Bale_Order_Notifier_Commerce::url()) . '">' . esc_html(Bale_Order_Notifier_Commerce::url()) . '</a></p><p><strong>ورود با هویت بله و پرداخت بومی بله غیرفعال‌اند.</strong> تا دریافت مستندات رسمی، هیچ شناسه ارسالی از مرورگر برای ورود به حساب معتبر شمرده نمی‌شود. ورود مشتری و پرداخت از مسیر استاندارد سایت است.</p></div>';
        echo '<div class="bon-card"><h2>مقصدهای انتشار</h2><p>بات را در بله مدیر کانال و دارای اجازه انتشار کنید. این پنل کانال جدید در بله نمی‌سازد و اعضای آن را مدیریت نمی‌کند.</p>';
        foreach ($channels as $id=>$channel) {
            echo '<div><strong>' . esc_html($channel['label']) . '</strong> <code>' . esc_html($channel['destination']) . '</code> — ' . (!empty($channel['enabled']) ? 'فعال' : 'متوقف');
            $this->form('test_channel', ['channel'=>$id]); echo '<button class="button">ارسال پیام تست</button></form>';
            $this->form('toggle_channel', ['channel'=>$id]); echo '<button class="button">تغییر فعال/متوقف</button></form>';
            $this->form('delete_channel', ['channel'=>$id]); echo '<label><input type="checkbox" name="confirm" value="1" required> تأیید حذف</label> <button class="button">حذف مقصد</button></form></div>';
        }
        $this->form('add_channel');
        echo '<p><input name="label" placeholder="نام مقصد" required> <input name="destination" dir="ltr" placeholder="@channel یا شناسه عددی" required> <button class="button">افزودن مقصد</button></p></form></div>';
        echo '<div class="bon-card"><h2>برنامه انتشار محصول یا نوشته</h2><p>شناسه داخلی محصول یا نوشته منتشرشده را وارد کنید. زمان‌ها میلادی و بر اساس منطقه زمانی وردپرس هستند. فاصله بررسی صف ۵ دقیقه است و در سایت کم‌ترافیک به cron واقعی نیاز دارید.</p>';
        $schedule_token = wp_generate_password(32, false, false);
        set_transient('bon_channel_once_' . get_current_user_id() . '_' . hash('sha256', $schedule_token), 1, HOUR_IN_SECONDS);
        $this->form('schedule', ['schedule_token'=>$schedule_token]);
        echo '<input type="number" name="source" min="1" placeholder="شناسه محصول یا نوشته" required> <select name="channel" required><option value="">مقصد</option>';
        foreach ($channels as $key=>$channel) if (!empty($channel['enabled'])) echo '<option value="' . esc_attr($key) . '">' . esc_html($channel['label']) . '</option>';
        echo '</select> <input type="datetime-local" name="publish_at" required> <button class="button button-primary">ثبت برنامه انتشار</button></form><p>متن و قیمت هنگام ورود به صف تولید می‌شوند؛ برای انتشار قیمت تازه پس از تغییر، برنامه قبلی را لغو و برنامه جدید بسازید.</p></div>';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $query = new WP_Query(['post_type'=>'bon_channel_job', 'post_status'=>'private', 'posts_per_page'=>20, 'paged'=>$page, 'orderby'=>'ID', 'order'=>'DESC']);
        echo '<div class="bon-card"><h2>تاریخچه و کنترل انتشار</h2><table class="widefat striped"><tr><th>محتوا / مقصد</th><th>زمان / برنامه</th><th>صف</th><th>عملیات</th></tr>';
        $labels = ['scheduled'=>'زمان‌بندی‌شده', 'queued'=>'ثبت در صف', 'blocked'=>'مسدود: مقصد یا محتوا نامعتبر', 'cancelled'=>'لغوشده'];
        global $wpdb; $table = Bale_Order_Notifier_Queue::table_name();
        foreach ($query->posts as $job) {
            $state = get_post_meta($job->ID, '_bon_state', true);
            $queue = $wpdb->get_results($wpdb->prepare("SELECT status, last_error FROM {$table} WHERE message_type = %s ORDER BY id DESC LIMIT 5", 'channel_' . $job->ID));
            echo '<tr><td>#' . (int) $job->ID . ' ' . esc_html($job->post_title) . '<br><code>' . esc_html(get_post_meta($job->ID, '_bon_destination', true)) . '</code></td><td>' . esc_html(wp_date('Y-m-d H:i', (int) get_post_meta($job->ID, '_bon_publish_at', true))) . '<br>' . esc_html($labels[$state] ?? $state) . '</td><td>';
            $queue_labels = ['pending'=>'در صف', 'sent'=>'پذیرفته‌شده توسط API بله', 'dead-letter'=>'ناموفق نهایی', 'paused'=>'متوقف', 'cancelled'=>'لغوشده'];
            foreach ($queue as $row) echo esc_html($queue_labels[$row->status] ?? $row->status) . ' ' . esc_html($row->last_error) . '<br>';
            echo esc_html(get_post_meta($job->ID, '_bon_error', true)) . '</td><td>';
            if ($state !== 'cancelled') {
                $this->form('cancel_job', ['job'=>$job->ID]); echo '<label><input type="checkbox" name="confirm" value="1" required> تأیید</label> <button class="button">لغو ارسال‌نشده‌ها</button></form>';
                $this->form('resume_job', ['job'=>$job->ID]); echo '<label><input type="checkbox" name="confirm" value="1" required> متن قبلی معتبر است</label> <button class="button">ادامه / تلاش مجدد</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</table><p>رکورد صف پس از دوره نگهداری پاک می‌شود؛ خالی بودن ستون صف به معنی ارسال موفق نیست. پیام پذیرفته‌شده لزوماً خوانده نشده است.</p>' . wp_kses_post(paginate_links(['base'=>add_query_arg('paged', '%#%', admin_url('admin.php?page=balika-commerce')), 'format'=>'', 'current'=>$page, 'total'=>$query->max_num_pages])) . '</div></div>';
    }
}
