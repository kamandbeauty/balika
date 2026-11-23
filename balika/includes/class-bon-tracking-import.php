<?php
if (!defined('ABSPATH')) exit;

/** Bounded, user-owned CSV import. All writes reuse the tracking service. */
class Bale_Order_Notifier_Tracking_Import {
    const MAX_ROWS = 200;
    const MAX_BYTES = 1048576;
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_bon_tracking_import', [$this, 'handle']);
    }
    public function menu() {
        add_submenu_page('balika', 'ورود فایل رهگیری', 'ورود فایل رهگیری', 'manage_woocommerce', 'balika-tracking-import', [$this, 'page']);
    }
    private function guard() {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی مجاز نیست.', '', ['response' => 403]);
    }
    private static function key($token) {
        return 'bon_csv_' . get_current_user_id() . '_' . hash('sha256', $token);
    }
    private function load($token) {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9]{32}$/D', $token)) return false;
        return get_transient(self::key($token));
    }
    private function store($token, $data) { set_transient(self::key($token), $data, 30 * MINUTE_IN_SECONDS); }
    private function redirect($token = '', $error = '') {
        if ($error !== '') set_transient('bon_csv_error_' . get_current_user_id(), $error, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'balika-tracking-import', 'batch' => $token], admin_url('admin.php')));
        exit;
    }
    public static function parse($contents) {
        if (!is_string($contents) || strlen($contents) > self::MAX_BYTES) return new WP_Error('size', 'حداکثر حجم فایل ۱ مگابایت است.');
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (strpos($contents, "\0") !== false || !preg_match('//u', $contents)) return new WP_Error('encoding', 'فایل باید CSV با کدگذاری UTF-8 باشد؛ در Excel گزینه CSV UTF-8 را انتخاب کنید.');
        $first = strtok($contents, "\r\n");
        if ($first === false) return new WP_Error('empty', 'فایل خالی است.');
        $delimiter = ','; $width = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $n = count(str_getcsv($first, $candidate, '"', ''));
            if ($n > $width) { $width = $n; $delimiter = $candidate; }
        }
        if ($width < 2 || $width > 30) return new WP_Error('columns', 'فایل باید بین ۲ تا ۳۰ ستون داشته باشد.');
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents); rewind($stream);
        $headers = fgetcsv($stream, 0, $delimiter, '"', '');
        $rows = []; $record = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $record++;
            if (count($cells) === 1 && ($cells[0] === null || trim($cells[0]) === '')) continue;
            if (count($rows) >= self::MAX_ROWS) { fclose($stream); return new WP_Error('rows', 'حداکثر ۲۰۰ ردیف مجاز است؛ فایل را تقسیم کنید. هیچ ردیفی وارد نشده است.'); }
            if (count($cells) !== count($headers)) { fclose($stream); return new WP_Error('width', 'تعداد ستون‌های رکورد ' . $record . ' با سرستون‌ها یکسان نیست.'); }
            foreach ($cells as $cell) if (strlen((string) $cell) > 2000) { fclose($stream); return new WP_Error('cell', 'یک سلول بیش از ۲۰۰۰ بایت است.'); }
            $rows[] = ['record' => $record, 'cells' => array_map('trim', $cells)];
        }
        fclose($stream);
        if (!$rows) return new WP_Error('empty', 'فایل ردیف اطلاعات ندارد.');
        return ['headers' => array_map('trim', $headers), 'rows' => $rows];
    }
    /** Explicit identifiers only; never fuzzy-match customers, amounts or telephone numbers. */
    public static function resolve($value, $mode) {
        if ($mode === 'id') {
            if (!preg_match('/^[1-9][0-9]*$/D', $value)) return new WP_Error('id', 'شناسه داخلی باید عدد صحیح باشد؛ نماد علمی Excel پذیرفته نیست.');
            $order = wc_get_order($value);
            return $order && $order->get_type() === 'shop_order' ? $order : new WP_Error('missing', 'سفارش یافت نشد.');
        }
        $ids = [];
        if (preg_match('/^[1-9][0-9]*$/D', $value)) {
            $order = wc_get_order($value);
            if ($order && (string) $order->get_order_number() === $value) $ids[] = $order->get_id();
        }
        // A numbering plugin can supply exact candidates. Every candidate is verified below.
        $ids = apply_filters('bon_import_order_number_candidates', $ids, $value);
        if (!is_array($ids) || count($ids) > 10) return new WP_Error('ambiguous', 'تعداد تطبیق‌ها مبهم است؛ از شناسه داخلی استفاده کنید.');
        $matches = [];
        foreach (array_slice(array_unique(array_map('absint', (array) $ids)), 0, 10) as $id) {
            $order = wc_get_order($id);
            if ($order && $order->get_type() === 'shop_order' && (string) $order->get_order_number() === $value) $matches[$id] = $order;
        }
        if (count($matches) !== 1) return new WP_Error('ambiguous', 'شماره نمایشی تطبیق یکتا ندارد؛ از شناسه داخلی استفاده کنید یا اتصال افزونه شماره‌گذاری را تنظیم کنید.');
        return reset($matches);
    }
    public static function preview($data, $mapping, $mode, $config) {
        $width = count($data['headers']);
        foreach (['order', 'code', 'courier', 'url'] as $field) {
            if (!isset($mapping[$field]) || !is_scalar($mapping[$field]) || !preg_match('/^-?[0-9]+$/D', (string) $mapping[$field])) return new WP_Error('mapping', 'ستون‌ها را انتخاب کنید.');
            $mapping[$field] = (int) $mapping[$field];
            if ($mapping[$field] < -1 || $mapping[$field] >= $width || (in_array($field, ['order','code'], true) && $mapping[$field] < 0)) return new WP_Error('mapping', 'ستون انتخاب‌شده نامعتبر است.');
        }
        $selected = array_filter($mapping, function($i) { return $i >= 0; });
        if (count(array_unique($selected)) !== count($selected)) return new WP_Error('mapping', 'هر ستون فقط برای یک فیلد قابل استفاده است.');
        $rows = []; $orders_seen = []; $codes_seen = [];
        foreach ($data['rows'] as $input) {
            $cells = $input['cells'];
            $row = ['record' => $input['record'], 'reference' => $cells[$mapping['order']], 'code' => Bale_Order_Notifier_Tracking::normalize_code($cells[$mapping['code']]), 'courier' => $mapping['courier'] >= 0 ? sanitize_text_field($cells[$mapping['courier']]) : ($config['courier'] ?? 'پست'), 'url' => $mapping['url'] >= 0 ? $cells[$mapping['url']] : '', 'error' => '', 'done' => false];
            $order = self::resolve($row['reference'], $mode);
            if (is_wp_error($order)) $row['error'] = $order->get_error_message();
            elseif (!current_user_can('edit_shop_order', $order->get_id())) $row['error'] = 'مجوز ویرایش این سفارش را ندارید.';
            elseif (!in_array($order->get_status(), $config['sources'], true)) $row['error'] = 'وضعیت سفارش در مبدأ انتخاب‌شده نیست.';
            else {
                $row['order_id'] = $order->get_id();
                $row['order_number'] = $order->get_order_number();
                $row['name'] = $order->get_formatted_billing_full_name();
                $row['snapshot'] = Bale_Order_Notifier_Tracking::fingerprint($order);
                $row['status'] = $order->get_status();
                $row['old_code'] = (string) $order->get_meta('_bon_tracking_code');
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,99}$/D', $row['code'])) $row['error'] = 'کد رهگیری نامعتبر است؛ صفرهای حذف‌شده یا نماد علمی Excel خودکار اصلاح نمی‌شوند.';
            if ($row['url'] !== '' && !wp_http_validate_url($row['url'])) $row['error'] = 'لینک رهگیری نامعتبر است.';
            $index = count($rows);
            // Reject every occurrence of duplicates, not just the last occurrence.
            foreach (['order' => $row['order_id'] ?? null, 'code' => strtoupper($row['code'])] as $kind => $value) {
                if (!$value) continue;
                $seen = $kind === 'order' ? $orders_seen : $codes_seen;
                if (isset($seen[$value])) {
                    $row['error'] = 'سفارش یا کد تکراری در فایل؛ قبل از ثبت، فایل را اصلاح کنید.';
                    $rows[$seen[$value]]['error'] = $row['error'];
                }
                if ($kind === 'order') $orders_seen[$value] = $index; else $codes_seen[$value] = $index;
            }
            $rows[] = $row;
        }
        return $rows;
    }
    public function handle() {
        $this->guard(); check_admin_referer('bon_tracking_import');
        $op = sanitize_key($_POST['operation'] ?? '');
        if ($op === 'upload') {
            $file = $_FILES['csv'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > self::MAX_BYTES || strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'csv') $this->redirect('', 'فایل CSV معتبر با حداکثر حجم یک مگابایت انتخاب کنید. فایل XLSX پذیرفته نیست.');
            $parsed = self::parse(file_get_contents($file['tmp_name'], false, null, 0, self::MAX_BYTES + 1));
            if (is_wp_error($parsed)) $this->redirect('', $parsed->get_error_message());
            $token = wp_generate_password(32, false, false);
            $this->store($token, ['parsed' => $parsed, 'phase' => 'mapping']);
            $this->redirect($token);
        }
        $token = isset($_POST['batch']) && is_string($_POST['batch']) ? wp_unslash($_POST['batch']) : '';
        $data = $this->load($token);
        if (!$data) $this->redirect('', 'جلسه ورود فایل منقضی شده یا متعلق به این کاربر نیست.');
        if ($op === 'preview' && $data['phase'] === 'mapping') {
            $config = Bale_Order_Notifier_Operations::config();
            $mode = ($_POST['mode'] ?? '') === 'number' ? 'number' : 'id';
            $preview = self::preview($data['parsed'], (array) ($_POST['mapping'] ?? []), $mode, $config);
            if (is_wp_error($preview)) $this->redirect($token, $preview->get_error_message());
            $this->store($token, ['phase' => 'preview', 'rows' => $preview, 'config' => $config]);
            $this->redirect($token);
        }
        if ($op === 'apply' && $data['phase'] === 'preview') {
            if (empty($_POST['confirm'])) $this->redirect($token, 'تأیید نهایی لازم است.');
            $selected = array_values(array_unique(array_map('absint', (array) ($_POST['selected'] ?? []))));
            if (!$selected || count($selected) > 20) $this->redirect($token, 'بین ۱ تا ۲۰ ردیف را در هر نوبت انتخاب کنید.');
            global $wpdb;
            $lock = 'bon_csv_' . md5(self::key($token));
            if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) $this->redirect($token, 'این فایل در حال ثبت است.');
            try {
                $data = $this->load($token);
                if (!$data || $data['phase'] !== 'preview') throw new RuntimeException('جلسه ورود فایل معتبر نیست.');
                if ($data['config'] !== Bale_Order_Notifier_Operations::config()) throw new RuntimeException('تنظیمات مبدأ یا مقصد عوض شده؛ فایل را دوباره پیش‌نمایش کنید.');
                foreach ($selected as $index) {
                    if (!isset($data['rows'][$index]) || $data['rows'][$index]['done'] || $data['rows'][$index]['error'] !== '') continue;
                    $row = $data['rows'][$index];
                    $replace = !empty($_POST['replace'][$index]);
                    $result = Bale_Order_Notifier_Tracking::save($row['order_id'], $row, $data['config']['target'], $data['config']['sources'], $row['snapshot'], $replace);
                    $data['rows'][$index]['result'] = is_wp_error($result) ? $result->get_error_message() : $result['message'];
                    // Failures are also frozen: re-preview before trying a potentially changed order again.
                    $data['rows'][$index]['done'] = true;
                    $this->store($token, $data);
                }
            } catch (Throwable $e) {
                set_transient('bon_csv_error_' . get_current_user_id(), 'ثبت متوقف شد؛ وضعیت ردیف‌ها را بررسی کنید. ' . $e->getMessage(), 5 * MINUTE_IN_SECONDS);
            } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
            $this->redirect($token);
        }
        if ($op === 'download' && $data['phase'] === 'preview') {
            nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="balika-import-results.csv"');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['record','reference','order_id','tracking_code','result'], ',', '"', '');
            foreach ($data['rows'] as $row) fputcsv($out, array_map(['Bale_Order_Notifier_Reports', 'csv_cell'], [$row['record'], $row['reference'], $row['order_id'] ?? '', $row['code'], $row['result'] ?? ($row['error'] ?: 'ثبت نشده')]), ',', '"', '');
            fclose($out); exit;
        }
        $this->redirect($token, 'عملیات یا مرحله نامعتبر است.');
    }
    private function form($op, $token = '', $upload = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($upload ? ' enctype="multipart/form-data"' : '') . '>';
        wp_nonce_field('bon_tracking_import');
        echo '<input type="hidden" name="action" value="bon_tracking_import"><input type="hidden" name="operation" value="' . esc_attr($op) . '"><input type="hidden" name="batch" value="' . esc_attr($token) . '">';
    }
    public function page() {
        $this->guard();
        $token = isset($_GET['batch']) && is_string($_GET['batch']) ? wp_unslash($_GET['batch']) : '';
        $data = $this->load($token);
        echo '<div class="wrap bon-operations" dir="rtl"><h1>ورود فایل کدهای رهگیری</h1><p>۱. بارگذاری CSV ← ۲. انتخاب ستون‌ها ← ۳. بررسی پیش‌نمایش ← ۴. تأیید و ثبت. فایل اصلی در کتابخانه رسانه یا مسیر عمومی ذخیره نمی‌شود.</p>';
        $key = 'bon_csv_error_' . get_current_user_id();
        if ($error = get_transient($key)) { delete_transient($key); echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>'; }
        if (!$data) {
            echo '<p>فایل CSV UTF-8 خروجی Excel، حداکثر ۲۰۰ ردیف و یک مگابایت. ستون کد را پیش از ورود اطلاعات در Excel از نوع Text قرار دهید. XLSX در این مرحله پشتیبانی نمی‌شود.</p>';
            $this->form('upload', '', true);
            echo '<input type="file" name="csv" accept=".csv,text/csv" required>';
            submit_button('بارگذاری و انتخاب ستون‌ها'); echo '</form></div>'; return;
        }
        echo '<p>اعتبار جلسه: ۳۰ دقیقه از آخرین ذخیره. این فایل فقط برای کاربر فعلی قابل دسترسی است. <a href="' . esc_url(admin_url('admin.php?page=balika-tracking-import')) . '">شروع فایل جدید</a></p>';
        if ($data['phase'] === 'mapping') {
            $this->form('preview', $token);
            echo '<p><label>نوع شماره سفارش <select name="mode"><option value="id">شناسه داخلی ووکامرس (پیشنهادی)</option><option value="number">شماره نمایشی سفارش؛ فقط تطبیق دقیق</option></select></label></p>';
            foreach (['order'=>'ستون سفارش', 'code'=>'ستون کد رهگیری', 'courier'=>'شرکت حمل (اختیاری)', 'url'=>'لینک رهگیری (اختیاری)'] as $field => $label) {
                echo '<p><label>' . esc_html($label) . ' <select name="mapping[' . esc_attr($field) . ']">';
                echo '<option value="-1">انتخاب نشده</option>';
                foreach ($data['parsed']['headers'] as $i => $header) echo '<option value="' . (int) $i . '">' . esc_html(($i+1) . ' — ' . $header) . '</option>';
                echo '</select></label></p>';
            }
            echo '<p>نمونه اولین ردیف: <code>' . esc_html(implode(' | ', $data['parsed']['rows'][0]['cells'])) . '</code></p>';
            submit_button('ساخت پیش‌نمایش بدون تغییر سفارش'); echo '</form></div>'; return;
        }
        $page = max(1, absint($_GET['paged'] ?? 1));
        $start = ($page - 1) * 20;
        $statuses = Bale_Order_Notifier_Operations::statuses();
        echo '<p>مقصد پس از ثبت: <strong>' . esc_html($statuses[$data['config']['target']] ?? 'بدون تغییر') . '</strong>. مشتریِ متصل‌نشده کد و وضعیت می‌گیرد اما پیام بله دریافت نمی‌کند. کد تکراری فعلاً در همین فایل بررسی می‌شود، نه در همه سفارش‌های سایت.</p><div style="overflow:auto">';
        $this->form('apply', $token);
        echo '<table class="widefat striped"><tr><th>انتخاب / رکورد</th><th>سفارش و گیرنده</th><th>کد قبلی ← جدید</th><th>وضعیت / شرکت حمل</th><th>اعتبارسنجی / نتیجه</th></tr>';
        foreach (array_slice($data['rows'], $start, 20, true) as $i => $row) {
            echo '<tr><td>';
            if (!$row['done'] && $row['error'] === '') echo '<input type="checkbox" name="selected[]" value="' . (int) $i . '" aria-label="انتخاب رکورد ' . (int) $row['record'] . '">';
            echo (int) $row['record'] . '</td><td>' . esc_html($row['order_number'] ?? $row['reference']) . '<br>' . esc_html($row['name'] ?? '') . '</td><td dir="ltr">' . esc_html(($row['old_code'] ?? '') . ' → ' . $row['code']);
            if (!$row['done'] && !empty($row['old_code'])) echo '<br><label><input type="checkbox" name="replace[' . (int) $i . ']" value="1"> اجازه جایگزینی</label>';
            echo '</td><td>' . esc_html($statuses[$row['status'] ?? ''] ?? '') . '<br>' . esc_html($row['courier']) . '<br><small dir="ltr">' . esc_html($row['url']) . '</small></td><td>' . esc_html($row['result'] ?? ($row['error'] ?: 'آماده تأیید؛ هنوز ثبت نشده')) . '</td></tr>';
        }
        echo '</table><p><label><input type="checkbox" name="confirm" value="1" required> تطبیق سفارش‌ها، کدها و وضعیت مقصد را بررسی کرده‌ام.</label></p>';
        submit_button('ثبت فقط ردیف‌های انتخاب‌شده این صفحه'); echo '</form></div>';
        echo wp_kses_post(paginate_links(['base'=>add_query_arg(['page'=>'balika-tracking-import','batch'=>$token,'paged'=>'%#%'],admin_url('admin.php')), 'format'=>'', 'current'=>$page, 'total'=>ceil(count($data['rows'])/20)]));
        $this->form('download', $token); submit_button('دریافت CSV نتیجه همه ردیف‌ها', 'secondary'); echo '</form></div>';
    }
}
