<?php
if ( ! defined('ABSPATH') ) exit;

/** Operational tools, kept separate from the existing settings screen. */
class Bale_Order_Notifier_Operations {
    private static $instance;
    public static function instance() { return self::$instance ?: (self::$instance = new self()); }
    private function __construct() {
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [__CLASS__, 'legacy_tracking_query'], 10, 2);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_bon_bulk_tracking', [$this, 'bulk_submit']);
        add_action('admin_post_bon_operations', [$this, 'action']);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('woocommerce_account_dashboard', [$this, 'account_connection']);
        add_action('woocommerce_order_status_changed', [$this, 'customer_status'], 35, 4);
    }
    public function menu() {
        add_submenu_page('balika', 'رهگیری گروهی', 'رهگیری گروهی', 'manage_woocommerce', 'balika-tracking', [$this, 'tracking_page']);
        add_submenu_page('balika', 'مشتریان و پیام‌ها', 'مشتریان و پیام‌ها', 'manage_woocommerce', 'balika-operations', [$this, 'page']);
    }
    private function guard() { if (!current_user_can('manage_woocommerce')) wp_die('دسترسی مجاز نیست.', '', ['response' => 403]); }
    private function finish($results, $page) {
        set_transient('bon_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS);
        $url=admin_url('admin.php?page=' . $page);
        if ($page==='balika-tracking' && isset($_POST['return_filters']) && is_array($_POST['return_filters'])) $url=add_query_arg(array_merge(self::tracking_filters($_POST['return_filters']),['paged'=>max(1,absint($_POST['return_page']??1))]),$url);
        wp_safe_redirect($url);
        exit;
    }
    private function notices() {
        $key = 'bon_results_' . get_current_user_id();
        $rows = get_transient($key);
        if (!$rows) return;
        delete_transient($key);
        echo '<div class="notice notice-info"><ul>';
        foreach ($rows as $row) echo '<li style="white-space:pre-wrap">' . esc_html($row) . '</li>';
        echo '</ul></div>';
    }
    public static function statuses() {
        $out = [];
        foreach (wc_get_order_statuses() as $key => $label) $out[substr($key, 3)] = $label;
        return $out;
    }
    public static function config() {
        $value = get_option('bon_tracking_settings', []);
        $statuses = self::statuses();
        $value['sources'] = array_values(array_intersect((array) ($value['sources'] ?? ['processing']), array_keys($statuses)));
        if (!$value['sources']) $value['sources'] = ['processing'];
        $value['target'] = $value['target'] ?? 'completed';
        if ($value['target'] !== '' && !isset($statuses[$value['target']])) $value['target'] = '';
        return $value;
    }
    /** Form dirty detection only; permissions and stale-order checks remain in Tracking::save. */
    public static function tracking_input_hash($row) {
        $fields=[];
        foreach(['code','courier','url'] as $key)$fields[$key]=is_scalar($row[$key]??null)?trim((string)$row[$key]):'';
        $fields['code']=Bale_Order_Notifier_Tracking::normalize_code($fields['code']);
        return hash('sha256',wp_json_encode($fields));
    }
    public function bulk_submit() {
        $this->guard();
        check_admin_referer('bon_bulk_tracking');
        $config = self::config();
        // A changed configuration invalidates an already opened form.
        $expected_config = sanitize_text_field(wp_unslash($_POST['config_hash'] ?? ''));
        if (!hash_equals(hash('sha256', wp_json_encode($config)), $expected_config)) {
            $this->finish(['تنظیمات پنل تغییر کرده است؛ دوباره سفارش‌ها را بررسی کنید.'], 'balika-tracking');
        }
        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        if (count($rows) > 20) $this->finish(['حداکثر ۲۰ ردیف در هر نوبت مجاز است.'], 'balika-tracking');
        $return_filters=self::tracking_filters(is_array($_POST['return_filters']??null)?$_POST['return_filters']:[]);
        $sources=$return_filters['order_status']==='processing'?['processing']:$config['sources'];
        $results = [];
        $failed = [];
        foreach ($rows as $id => $row) {
            if (!is_array($row)) continue;
            if (is_scalar($row['code']??null) && trim((string)$row['code'])==='') continue;
            if (!isset($row['code'])) continue;
            if (!is_string($row['input_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$row['input_hash'])) { $results[]='#'.absint($id).': فرم قدیمی یا ناقص است؛ صفحه را تازه کنید.';continue; }
            if (hash_equals($row['input_hash'],self::tracking_input_hash($row))) continue;
            $id = absint($id);
            if (empty($row['snapshot']) || !is_string($row['snapshot'])) { $results[] = '#' . $id . ': اطلاعات فرم ناقص است.'; continue; }
            $result = Bale_Order_Notifier_Tracking::save($id, [
                'code' => is_scalar($row['code'] ?? null) ? $row['code'] : '',
                'courier' => is_scalar($row['courier'] ?? null) ? $row['courier'] : '',
                'url' => is_scalar($row['url'] ?? null) ? $row['url'] : '',
            ], $config['target'], $sources, sanitize_text_field($row['snapshot']), !empty($row['replace']));
            if (is_wp_error($result)) $failed[$id]=['row'=>$row,'error'=>$result->get_error_message()];
            $results[] = '#' . $id . ' (کد واردشده: ' . Bale_Order_Notifier_Tracking::normalize_code($row['code'] ?? '') . '): ' . (is_wp_error($result) ? $result->get_error_message() : $result['message']);
        }
        set_transient('bon_tracking_failed_'.get_current_user_id(),$failed,10 * MINUTE_IN_SECONDS);
        $this->finish($results ?: ['کد جدید یا اطلاعات تغییریافته‌ای وارد نشده بود؛ هیچ تغییری انجام نشد.'], 'balika-tracking');
    }
    private function form_start($action) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bon_operations');
        echo '<input type="hidden" name="action" value="bon_operations"><input type="hidden" name="operation" value="' . esc_attr($action) . '">';
    }
    public static function legacy_tracking_query($query, $vars) {
        if (!empty($vars['bon_tracking_meta'])) $query['meta_query'][]=$vars['bon_tracking_meta'];
        return $query;
    }
    public static function tracking_filters($input) {
        $out=[];
        foreach (['presence','search_by','term','order_status'] as $key) $out[$key]=is_scalar($input[$key]??null)?sanitize_text_field(wp_unslash($input[$key])):'';
        if (!in_array($out['presence'],['missing','present'],true)) $out['presence']='';
        if (!in_array($out['search_by'],['id','billing_phone','billing_first_name','billing_last_name'],true)) $out['search_by']='id';
        if ($out['order_status']!=='processing') $out['order_status']='';
        $out['term']=substr($out['term'],0,100);
        return $out;
    }
    public static function tracking_query($config,$filters,$page) {
        $args=['type'=>'shop_order','status'=>$config['sources'],'limit'=>20,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC'];
        if (($filters['order_status']??'')==='processing') $args['status']=['processing'];
        if ($filters['term']!=='') {
            if ($filters['search_by']==='id') {
                $id=Bale_Order_Notifier_Tracking::normalize_code($filters['term']);
                $args['include']=[ctype_digit($id) && (int)$id>0 ? (int)$id : 0];
            } else $args[$filters['search_by']]=$filters['term'];
        }
        if ($filters['presence']) {
            $meta=$filters['presence']==='missing' ? ['relation'=>'OR',['key'=>'_bon_tracking_code','compare'=>'NOT EXISTS'],['key'=>'_bon_tracking_code','value'=>'','compare'=>'=']] : ['key'=>'_bon_tracking_code','value'=>'','compare'=>'!='];
            $args['bon_tracking_meta']=$meta;
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) $args['meta_query']=[$meta];
        }
        return $args;
    }
    public function tracking_page() {
        $this->guard();
        $config = self::config();
        $statuses = self::statuses();
        $page = max(1, absint($_GET['paged'] ?? 1));
        $filters=self::tracking_filters($_GET);
        $result=wc_get_orders(self::tracking_query($config,$filters,$page));
        $failed=(array)get_transient('bon_tracking_failed_'.get_current_user_id());
        wp_enqueue_script('bon-tracking-admin',BON_PLUGIN_URL.'assets/js/tracking-admin.js',[],BON_VERSION,true);
        echo '<div class="wrap bon-operations" dir="rtl"><h1>ثبت گروهی کد رهگیری</h1>';
        $this->notices();
        echo '<p>«دسته سفارش» در این صفحه یعنی وضعیت سفارش، نه دسته‌بندی محصول. کدهای خالی ذخیره نمی‌شوند. تغییر وضعیت ممکن است ایمیل‌ها و عملیات استاندارد ووکامرس را هم اجرا کند.</p><div class="bon-card">';
        $this->form_start('tracking_settings');
        echo '<h2>سفارش‌های قابل نمایش</h2>';
        foreach ($statuses as $key => $label) echo '<label style="display:inline-block;margin:8px"><input type="checkbox" name="sources[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, $config['sources'], true), true, false) . '> ' . esc_html($label) . '</label>';
        echo '<p><label>پس از ثبت کد رهگیری: <select name="target"><option value="">وضعیت تغییر نکند</option>';
        foreach ($statuses as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($config['target'], $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p><p><label>نام پیش‌فرض شرکت حمل <input name="courier" value="' . esc_attr($config['courier'] ?? 'پست') . '"></label></p>';
        submit_button('ذخیره تنظیمات نمایش و مقصد');
        echo '</form></div><div class="bon-card"><h2>ورود کدها</h2><p>بدون تیک‌زدن: فقط ردیف‌های دارای کد که اطلاعاتشان را وارد یا تغییر داده‌اید ثبت می‌شوند. ردیف خالی و اطلاعات دست‌نخورده تغییری نمی‌کنند. مقصد فعلی: <strong>' . esc_html($statuses[$config['target']] ?? 'بدون تغییر وضعیت') . '</strong>. هر صفحه حداکثر ۲۰ سفارش.</p>';
        $processing_url=add_query_arg(array_merge($filters,['order_status'=>'processing','paged'=>1]),admin_url('admin.php?page=balika-tracking'));
        $configured_url=add_query_arg(array_merge($filters,['order_status'=>'','paged'=>1]),admin_url('admin.php?page=balika-tracking'));
        echo '<p><a class="button '.($filters['order_status']==='processing'?'button-primary':'').'" href="'.esc_url($processing_url).'">سفارش‌های در حال انجام</a> <a class="button" href="'.esc_url($configured_url).'">وضعیت‌های تنظیم‌شده</a></p>';
        if ($filters['order_status']==='processing') echo '<p>فیلتر فعال: فقط سفارش‌های در حال انجام؛ تنظیمات ذخیره‌شده وضعیت‌ها تغییر نکرده است.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="balika-tracking"><input type="hidden" name="order_status" value="'.esc_attr($filters['order_status']).'"><label>رهگیری <select name="presence">';
        foreach ([''=>'همه','missing'=>'بدون کد','present'=>'دارای کد'] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($filters['presence'],$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <label>جست‌وجو بر اساس <select name="search_by">';
        foreach (['id'=>'شناسه داخلی سفارش','billing_phone'=>'تلفن صورتحساب','billing_first_name'=>'نام','billing_last_name'=>'نام خانوادگی'] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($filters['search_by'],$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <label>عبارت <input name="term" value="'.esc_attr($filters['term']).'" maxlength="100"></label> <button class="button">جست‌وجو</button><p>نام، نام خانوادگی و تلفن باید مطابق مقدار ثبت‌شده باشند؛ شماره نمایشی سفارشی سفارش با شناسه داخلی متفاوت است.</p></form>';
        if ($failed) {
            echo '<details open><summary>ورودی‌های ناموفق قبلی (تا ۱۰ دقیقه؛ پیش از تکرار وضعیت سفارش را بررسی کنید)</summary>';
            foreach ($failed as $fid=>$entry) echo '<p>#'.absint($fid).' — '.esc_html($entry['error']).' — کد: <code>'.esc_html(Bale_Order_Notifier_Tracking::normalize_code($entry['row']['code']??'')).'</code></p>';
            echo '</details>';
        }
        echo '<form id="bon-tracking-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        foreach ($filters as $key=>$value) echo '<input type="hidden" name="return_filters['.esc_attr($key).']" value="'.esc_attr($value).'">';
        echo '<input type="hidden" name="return_page" value="'.(int)$page.'">';
        wp_nonce_field('bon_bulk_tracking');
        echo '<input type="hidden" name="action" value="bon_bulk_tracking"><input type="hidden" name="config_hash" value="' . esc_attr(hash('sha256', wp_json_encode($config))) . '">';
        echo '<table class="widefat striped"><thead><tr><th>سفارش / مشتری</th><th>وضعیت / اتصال</th><th>کد رهگیری</th><th>حمل و لینک اختیاری</th></tr></thead><tbody>';
        foreach ($result->orders as $order) {
            $id = $order->get_id();
            $prefix = 'rows[' . $id . ']';
            $old = (string) $order->get_meta('_bon_tracking_code');
            $draft=$failed[$id]??null;
            $retry=$draft && is_string($draft['row']['snapshot']??null) && hash_equals($draft['row']['snapshot'],Bale_Order_Notifier_Tracking::fingerprint($order));
            $display_code=$retry ? Bale_Order_Notifier_Tracking::normalize_code($draft['row']['code']??'') : $old;
            $display_courier=$retry && is_scalar($draft['row']['courier']??null) ? $draft['row']['courier'] : ($order->get_meta('_bon_courier_name') ?: ($config['courier']??'پست'));
            $display_url=$retry && is_scalar($draft['row']['url']??null) ? $draft['row']['url'] : $order->get_meta('_bon_tracking_url');
            $input_hash=self::tracking_input_hash(['code'=>$old,'courier'=>$order->get_meta('_bon_courier_name') ?: ($config['courier'] ?? 'پست'),'url'=>$order->get_meta('_bon_tracking_url')]);
            echo '<tr><td><input type="hidden" name="'.esc_attr($prefix.'[input_hash]').'" value="'.esc_attr($input_hash).'"><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a><br>' . esc_html($order->get_formatted_billing_full_name()) . '<br>' . esc_html($order->get_billing_phone()) . '</td><td>' . esc_html(wc_get_order_status_name($order->get_status())) . '<br>' . (Bale_Order_Notifier_Customer_Store::is_linked($order->get_billing_phone()) ? 'متصل به بله' : '⚠ متصل نیست') . '</td>';
            echo '<td><input type="hidden" name="' . esc_attr($prefix . '[snapshot]') . '" value="' . esc_attr(Bale_Order_Notifier_Tracking::fingerprint($order)) . '"><input type="text" dir="ltr" maxlength="100" name="' . esc_attr($prefix . '[code]') . '" value="' . esc_attr($display_code) . '" aria-label="کد رهگیری سفارش ' . esc_attr($id) . '">';
            if ($draft) echo '<p class="notice notice-error">'.esc_html($draft['error']).'</p>';
            if ($old !== '') echo '<br><label><input type="checkbox" name="' . esc_attr($prefix . '[replace]') . '" value="1"> اجازه جایگزینی اطلاعات قبلی</label>';
            echo '</td><td><input name="' . esc_attr($prefix . '[courier]') . '" value="' . esc_attr($display_courier) . '" aria-label="شرکت حمل"><br><input type="url" dir="ltr" name="' . esc_attr($prefix . '[url]') . '" value="' . esc_attr($display_url) . '" placeholder="https://…" aria-label="لینک رهگیری"></td></tr>';
        }
        if (!$result->orders) echo '<tr><td colspan="4">سفارشی با وضعیت‌های انتخاب‌شده وجود ندارد.</td></tr>';
        echo '</tbody></table>';
        echo '<p id="bon-tracking-dirty" aria-live="polite"></p>';
        if ($result->orders) submit_button('ثبت');
        echo '</form><p>' . esc_html(sprintf('مجموع %d سفارش؛ صفحه %d از %d', $result->total, $page, max(1, $result->max_num_pages))) . '</p>';
        echo wp_kses_post(paginate_links(['base' => add_query_arg(array_merge($filters,['paged'=>'%#%']), admin_url('admin.php?page=balika-tracking')), 'format' => '', 'current' => $page, 'total' => $result->max_num_pages]));
        echo '</div></div>';
    }

    public static function portable_keys() {
        return [
            'template_new_order' => 'text', 'template_status_change' => 'text', 'template_low_stock' => 'text',
            'template_customer_order' => 'text', 'template_customer_tracking' => 'text',
            'template_customer_cancelled' => 'text', 'template_customer_status' => 'text',
            'template_payment_reminder' => 'text', 'template_cart_reminder' => 'text',
            'customer_notifications_enabled' => 'bool', 'notify_new_order' => 'bool',
            'notify_customer_on_cancel' => 'bool', 'notify_low_stock' => 'bool',
            'customer_statuses' => 'statuses', 'enabled_statuses' => 'statuses',
        ];
    }
    public static function portable_settings() {
        return array_intersect_key((array) get_option('bon_settings', []), self::portable_keys());
    }
    public static function validate_import($json) {
        if (!is_string($json) || strlen($json) > 65536) return new WP_Error('size', 'حداکثر حجم ورودی ۶۴ کیلوبایت است.');
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !isset($data['settings']) || !is_array($data['settings'])) return new WP_Error('format', 'قالب JSON معتبر بالیکا لازم است.');
        $keys = self::portable_keys();
        $clean = [];
        foreach ($data['settings'] as $key => $value) {
            if (!isset($keys[$key])) return new WP_Error('key', 'کلید غیرمجاز در ورودی: ' . sanitize_key($key));
            if ($keys[$key] === 'bool') {
                if (!is_bool($value)) return new WP_Error('type', 'مقدار روشن/خاموش نامعتبر است.');
                $clean[$key] = $value;
            } elseif ($keys[$key] === 'statuses') {
                if (!is_array($value)) return new WP_Error('type', 'فهرست وضعیت نامعتبر است.');
                foreach ($value as $status) if (!is_string($status) || !isset(self::statuses()[$status])) return new WP_Error('status', 'یکی از وضعیت‌ها در سایت مقصد وجود ندارد.');
                $clean[$key] = array_values(array_unique($value));
            } else {
                if (!is_string($value) || strlen($value) > 8000) return new WP_Error('type', 'متن قالب نامعتبر یا بیش از حد طولانی است.');
                $clean[$key] = sanitize_textarea_field($value);
            }
        }
        if (!$clean) return new WP_Error('empty', 'تنظیمی برای ورود وجود ندارد.');
        return $clean;
    }

    public function action() {
        $this->guard();
        check_admin_referer('bon_operations');
        $op = sanitize_key($_POST['operation'] ?? '');
        if ($op === 'config_export') {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="balika-settings.json"');
            echo wp_json_encode(['format' => 1, 'settings' => self::portable_settings()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($op === 'config_preview') {
            $clean = self::validate_import(wp_unslash($_POST['config_json'] ?? ''));
            if (is_wp_error($clean)) $this->finish([$clean->get_error_message()], 'balika-operations');
            set_transient('bon_import_' . get_current_user_id(), ['settings' => $clean, 'before' => hash('sha256', wp_json_encode(self::portable_settings()))], 10 * MINUTE_IN_SECONDS);
            $this->finish(['پیش‌نمایش ورود: ' . implode('، ', array_keys($clean)) . '. هنوز تغییری اعمال نشده؛ برای اعمال، پایین صفحه تأیید کنید.'], 'balika-operations');
        }
        if ($op === 'config_apply') {
            $key = 'bon_import_' . get_current_user_id();
            $pending = get_transient($key);
            if (empty($_POST['confirm_import']) || !$pending) $this->finish(['تأیید یا پیش‌نمایش معتبر وجود ندارد.'], 'balika-operations');
            if (!hash_equals($pending['before'], hash('sha256', wp_json_encode(self::portable_settings())))) $this->finish(['تنظیمات پس از پیش‌نمایش تغییر کرده‌اند؛ دوباره پیش‌نمایش بگیرید.'], 'balika-operations');
            update_option('bon_settings', array_merge((array) get_option('bon_settings', []), $pending['settings']));
            delete_transient($key);
            $this->finish(['فقط تنظیمات تأییدشده وارد شدند؛ اتصال بات و اطلاعات مشتریان تغییر نکرد.'], 'balika-operations');
        }
        if ($op === 'range_report') {
            $bounds = Bale_Order_Notifier_Reports::range_bounds(wp_unslash($_POST['from'] ?? ''), wp_unslash($_POST['to'] ?? ''));
            if (is_wp_error($bounds)) $this->finish([$bounds->get_error_message()], 'balika-operations');
            $mode = sanitize_key($_POST['mode'] ?? 'preview');
            if ($mode === 'csv') {
                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="balika-sales.csv"');
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['order_id', 'order_number', 'created_at', 'status', 'total', 'currency'], ',', '"', '');
                foreach (Bale_Order_Notifier_Reports::range_orders($bounds) as $order) {
                    fputcsv($stream, array_map(['Bale_Order_Notifier_Reports', 'csv_cell'], [$order->get_id(), $order->get_order_number(), $order->get_date_created()->date('c'), $order->get_status(), $order->get_total(), $order->get_currency()]), ',', '"', '');
                }
                fclose($stream);
                exit;
            }
            $text = Bale_Order_Notifier_Reports::range_report($bounds);
            if ($mode === 'send') {
                $sent = Bale_Order_Notifier_Dispatcher::broadcast($text);
                $this->finish([$sent ? 'گزارش برای ارسال به مدیران پذیرفته شد.' : 'ارسال گزارش پذیرفته نشد؛ اتصال بله را بررسی کنید.'], 'balika-operations');
            }
            $this->finish([$text], 'balika-operations');
        }
        if ($op === 'preview_template') {
            $order = wc_get_order(absint($_POST['order_id'] ?? 0));
            if (!$order || !current_user_can('edit_shop_order', $order->get_id())) $this->finish(['سفارش معتبر انتخاب کنید.'], 'balika-operations');
            $template = sanitize_textarea_field(wp_unslash($_POST['template'] ?? ''));
            $text = Bale_Order_Notifier_Customer_Hooks::instance()->build_customer_message('template_customer_tracking', $order, $template);
            $this->finish(['پیش‌نمایش؛ ذخیره یا ارسال نشده است:', $text], 'balika-operations');
        }
        if ($op === 'tracking_settings') {
            $statuses = self::statuses();
            $sources = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['sources'] ?? [])), array_keys($statuses)));
            $target = sanitize_key($_POST['target'] ?? '');
            if (!$sources || ($target !== '' && !isset($statuses[$target]))) $this->finish(['حداقل یک وضعیت مبدأ و مقصد معتبر انتخاب کنید.'], 'balika-tracking');
            update_option('bon_tracking_settings', ['sources' => $sources, 'target' => $target, 'courier' => sanitize_text_field(wp_unslash($_POST['courier'] ?? 'پست'))], false);
            $this->finish(['تنظیمات ذخیره شد.'], 'balika-tracking');
        }
        if ($op === 'unlink') {
            if (empty($_POST['confirm_unlink'])) $this->finish(['ابتدا قطع اتصال این چت را تأیید کنید.'], 'balika-operations');
            global $wpdb;
            $table = Bale_Order_Notifier_Customer_Store::table_name();
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND platform = 'bale'", absint($_POST['link_id'] ?? 0)));
            if ($row) Bale_Order_Notifier_Customer_Store::unlink_chat('bale', $row->chat_id);
            $this->finish([$row ? 'اتصال این چت و پیام‌های منتظر آن حذف شد.' : 'اتصال یافت نشد.'], 'balika-operations');
        }
        if ($op === 'retry') {
            if (empty($_POST['confirm_retry'])) $this->finish(['ارسال مجدد متن قبلی نیاز به تأیید دارد.'], 'balika-operations');
            global $wpdb;
            $table = Bale_Order_Notifier_Queue::table_name();
            $id = absint($_POST['message_id'] ?? 0);
            $count = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'pending', attempts = 0, next_attempt = %s WHERE id = %d AND platform = 'bale' AND status = 'dead-letter'", current_time('mysql'), $id));
            $this->finish([$count ? 'پیام ناموفق دوباره در صف قرار گرفت.' : 'پیام قابل ارسال مجدد نیست؛ ممکن است قبلاً در صف باشد.'], 'balika-operations');
        }
        if ($op === 'experience') {
            $options = get_option('bon_settings', []);
            $options['form_rules_enabled'] = !empty($_POST['form_rules_enabled']);
            $options['form_rules'] = sanitize_textarea_field(wp_unslash($_POST['form_rules'] ?? ''));
            $options['support_url'] = esc_url_raw(wp_unslash($_POST['support_url'] ?? ''), ['https']);
            $options['customer_statuses'] = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['customer_statuses'] ?? [])), array_keys(self::statuses())));
            $options['template_customer_status'] = sanitize_textarea_field(wp_unslash($_POST['template_customer_status'] ?? ''));
            update_option('bon_settings', $options);
            $this->finish(['تنظیمات تجربه مشتری ذخیره شد.'], 'balika-operations');
        }
        $this->finish(['عملیات نامعتبر است.'], 'balika-operations');
    }

    public function page() {
        $this->guard();
        global $wpdb;
        echo '<div class="wrap" dir="rtl"><h1>مشتریان، پیام‌ها و تجربه مشتری</h1>';
        $this->notices();
        $search = sanitize_text_field(wp_unslash($_GET['phone'] ?? ''));
        $page = max(1, absint($_GET['paged'] ?? 1));
        $table = Bale_Order_Notifier_Customer_Store::table_name();
        $like = '%' . $wpdb->esc_like($search) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE platform = 'bale' AND phone LIKE %s ORDER BY id DESC LIMIT 20 OFFSET %d", $like, ($page - 1) * 20));
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE platform = 'bale' AND phone LIKE %s", $like));
        echo '<div class="bon-card"><h2>اتصال مشتریان</h2><p>اتصال قدیمی به معنی تأیید مالکیت نیست. قطع اتصال یک چت، اتصال همه شماره‌های همان چت و پیام‌های منتظرش را حذف می‌کند.</p><form method="get"><input type="hidden" name="page" value="balika-operations"><input name="phone" value="' . esc_attr($search) . '" placeholder="جست‌وجوی شماره تلفن"><button class="button">جست‌وجو</button></form><table class="widefat striped"><tr><th>شماره</th><th>شناسه چت بله</th><th>تاریخ اتصال</th><th>عملیات</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->phone) . '</td><td>' . esc_html($row->chat_id) . '</td><td>' . esc_html($row->linked_at) . '</td><td>';
            $this->form_start('unlink');
            echo '<input type="hidden" name="link_id" value="' . (int) $row->id . '"><label><input type="checkbox" name="confirm_unlink" value="1" required> تأیید قطع اتصال</label> <button class="button">قطع اتصال این چت</button></form></td></tr>';
        }
        echo '</table>' . wp_kses_post(paginate_links(['base' => add_query_arg(['paged' => '%#%', 'phone' => $search], admin_url('admin.php?page=balika-operations')), 'format' => '', 'current' => $page, 'total' => ceil($total / 20)])) . '</div>';
        echo '<div class="bon-card"><h2>تاریخچه پیام سفارش</h2><form method="get"><input type="hidden" name="page" value="balika-operations"><input type="number" min="1" name="order_id" value="' . absint($_GET['order_id'] ?? 0) . '" placeholder="شناسه داخلی سفارش"><button class="button">نمایش</button></form>';
        $order_id = absint($_GET['order_id'] ?? 0);
        if ($order_id && current_user_can('edit_shop_order', $order_id)) $this->history($order_id);
        echo '</div><div class="bon-card"><h2>پیام‌ها و پشتیبانی مشتری</h2>';
        $this->form_start('experience');
        echo '<p><label>لینک پشتیبانی (https) <input type="url" dir="ltr" class="regular-text" name="support_url" value="' . esc_attr(bon_get_option('support_url')) . '" placeholder="https://ble.ir/…"></label></p><p>اعلان تغییر وضعیت به مشتری متصل:</p>';
        foreach (self::statuses() as $key => $label) {
            if (in_array($key, ['cancelled', 'refunded'], true)) continue;
            echo '<label style="margin:8px;display:inline-block"><input type="checkbox" name="customer_statuses[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, (array) bon_get_option('customer_statuses', []), true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '<p>اعلان لغو و مرجوعی از تنظیمات فعلی مشتری پیروی می‌کند. وضعیت‌های انتخاب‌شده علاوه بر پیام رهگیری ارسال می‌شوند.</p><textarea name="template_customer_status" rows="4" class="large-text">' . esc_textarea(bon_get_option('template_customer_status', 'سفارش #{order_id} به وضعیت {order_status} تغییر کرد.')) . '</textarea><p>متغیرها: {order_id}، {order_status}، {customer_name}، {tracking_code}، {payment_url}</p>';
        echo '<h3>حریم خصوصی اعلان فرم‌ها</h3><label><input type="checkbox" name="form_rules_enabled" ' . checked(bon_get_option('form_rules_enabled', false), true, false) . '> فقط فرم‌ها و فیلدهای فهرست زیر ارسال شوند</label><p>هر خط: شناسه فرم = شناسه فیلدهای مجاز، جداشده با کاما. فرم‌های خارج از فهرست ارسال نمی‌شوند. خالی‌بودن فهرست در حالت فعال، تمام اعلان فرم‌ها را مسدود می‌کند.</p><p dir="ltr">cf7:123=your-name,your-email<br>wpforms:45=1,2<br>gravity:6=1,3<br>elementor:Contact=name,email</p><textarea name="form_rules" rows="5" class="large-text code" dir="ltr">' . esc_textarea(bon_get_option('form_rules', '')) . '</textarea><p>برای المنتور، نام دقیق فرم را جای Contact بنویسید. فیلدهای متداول رمز، توکن و کارت حتی اگر در فهرست باشند حذف می‌شوند؛ فیلدهای حساس سفارشی را نیز از فهرست مجاز خارج کنید.</p>';
        submit_button('ذخیره تنظیمات تجربه مشتری');
        echo '</form></div><div class="bon-card"><h2>گزارش بازه دلخواه</h2><p>تاریخ‌ها میلادی و بر اساس منطقه زمانی سایت هستند. حداکثر بازه: ۳۶۶ روز. محاسبه ناخالص است، نه سود یا فروش خالص پس از مرجوعی.</p>';
        $this->form_start('range_report');
        echo '<label>از <input type="date" name="from" required></label> <label>تا <input type="date" name="to" required></label><p><button class="button" name="mode" value="preview">پیش‌نمایش گزارش</button> <button class="button" name="mode" value="send">ارسال به مدیران بله</button> <button class="button" name="mode" value="csv">دریافت CSV</button></p></form></div><div class="bon-card"><h2>پیش‌نمایش قالب مشتری</h2><p>پیش‌نمایش متنی با اطلاعات سفارش واقعی؛ بدون ذخیره تنظیمات و بدون ارسال به بله.</p>';
        $this->form_start('preview_template');
        echo '<input type="number" name="order_id" min="1" required placeholder="شناسه داخلی سفارش"><textarea name="template" rows="4" class="large-text" required>سفارش #{order_id}
کد رهگیری: {tracking_code}
{tracking_url_line}</textarea>';
        submit_button('نمایش پیش‌نمایش', 'secondary');
        echo '</form></div><div class="bon-card"><h2>انتقال تنظیمات بین سایت‌ها</h2><p>فقط قالب‌های پیام و انتخاب اعلان‌ها منتقل می‌شوند؛ توکن بات، شناسه چت، لینک پشتیبانی و اطلاعات مشتریان در خروجی نیستند. محتوای دستی قالب‌ها را پیش از انتقال بررسی کنید.</p>';
        $this->form_start('config_export');
        submit_button('دریافت JSON تنظیمات قابل انتقال', 'secondary');
        echo '</form>';
        $this->form_start('config_preview');
        echo '<textarea name="config_json" rows="5" class="large-text code" dir="ltr" maxlength="65536" placeholder="JSON تنظیمات" required></textarea>';
        submit_button('اعتبارسنجی و پیش‌نمایش ورود', 'secondary');
        echo '</form>';
        if ($pending = get_transient('bon_import_' . get_current_user_id())) {
            echo '<h3>مقادیر آماده اعمال (اعتبار ۱۰ دقیقه)</h3><pre dir="ltr" style="white-space:pre-wrap">' . esc_html(wp_json_encode($pending['settings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            $this->form_start('config_apply');
            echo '<label><input type="checkbox" name="confirm_import" value="1" required> جایگزینی این تنظیمات را تأیید می‌کنم</label>';
            submit_button('اعمال تنظیمات تأییدشده');
            echo '</form>';
        }
        echo '</div></div>';
    }

    public function history($order_id) {
        global $wpdb;
        $table = Bale_Order_Notifier_Queue::table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, message_type, status, attempts, created_at, sent_at, last_error, payload FROM {$table} WHERE order_id = %d AND platform = 'bale' ORDER BY id DESC LIMIT 50", $order_id));
        $types = ['tracking' => 'رهگیری', 'customer_order' => 'پیام سفارش مشتری', 'customer_status' => 'تغییر وضعیت مشتری', 'payment_reminder' => 'یادآوری پرداخت', 'admin_order' => 'اعلان مدیر', 'customer_note' => 'یادداشت سفارش'];
        $labels = ['pending' => 'در صف', 'sent' => 'پذیرفته‌شده توسط API بله', 'dead-letter' => 'ناموفق نهایی'];
        echo '<p>آخرین ۵۰ پیام ثبت‌شده پس از ارتقا. پذیرش توسط API به معنی خوانده‌شدن نیست؛ تعداد تلاش برای قطعه فعلی پیام است؛ پیام‌های نهایی پس از ۳۰ روز پاک می‌شوند.</p><table class="widefat striped"><tr><th>نوع / زمان</th><th>وضعیت / تلاش</th><th>خطا / عملیات</th></tr>';
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            echo '<tr><td>' . esc_html($types[$row->message_type] ?? $row->message_type) . '<br>چت: ' . esc_html($payload['chat_id'] ?? '') . '<br>' . esc_html($row->created_at) . '</td><td>' . esc_html($labels[$row->status] ?? $row->status) . ' / ' . (int) $row->attempts . '<br>' . esc_html($row->sent_at) . '</td><td>' . esc_html($row->last_error) . '<details><summary>متن پیام</summary><pre style="white-space:pre-wrap">' . esc_html($payload['text'] ?? '') . '</pre></details>';
            if ($row->status === 'dead-letter' && current_user_can('manage_woocommerce')) {
                // A button associated with a detached form avoids nested forms in the order editor.
                echo ' <a href="' . esc_url(admin_url('admin.php?page=balika-operations&order_id=' . $order_id)) . '">مدیریت</a>';
                if (isset($_GET['page']) && $_GET['page'] === 'balika-operations') {
                    $this->form_start('retry');
                    echo '<input type="hidden" name="message_id" value="' . (int) $row->id . '"><label><input type="checkbox" name="confirm_retry" value="1" required> متن قبلی هنوز معتبر است</label> <button class="button">تلاش مجدد همین پیام</button></form>';
                }
            }
            echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="3">پیامی ثبت نشده است.</td></tr>';
        echo '</table>';
    }
    public function meta_boxes() {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) $screens[] = wc_get_page_screen_id('shop-order');
        foreach (array_unique($screens) as $screen) add_meta_box('bon_order_history', 'تاریخچه پیام‌های بله', [$this, 'history_box'], $screen, 'normal', 'default');
    }
    public function history_box($object) {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);
        if ($order && current_user_can('edit_shop_order', $order->get_id())) $this->history($order->get_id());
    }
    public function account_connection() {
        if (!is_user_logged_in() || !bon_get_option('customer_notifications_enabled', true)) return;
        $orders = wc_get_orders(['customer_id' => get_current_user_id(), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']);
        if ($orders) Bale_Order_Notifier_Customer_Hooks::instance()->render_connect_box($orders[0]->get_id());
    }
    public function customer_status($id, $old, $new, $order) {
        if (!bon_get_option('customer_notifications_enabled', true) || in_array($new, ['cancelled', 'refunded'], true)) return;
        if (!in_array($new, (array) bon_get_option('customer_statuses', []), true)) return;
        if (!in_array('bale', bon_get_enabled_platforms(), true)) return;
        $text = Bale_Order_Notifier_Customer_Hooks::instance()->build_customer_message('template_customer_status', $order);
        if (trim($text) === '') return;
        foreach (Bale_Order_Notifier_Customer_Store::get_links_for_phone($order->get_billing_phone()) as $link) {
            Bale_Order_Notifier_Queue::enqueue('bale', 'sendMessage', ['chat_id' => $link['chat_id'], 'text' => $text], $id, 'customer_status');
        }
    }
}
