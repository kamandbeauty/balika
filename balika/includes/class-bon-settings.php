<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس تنظیمات - صفحه ادمین با طراحی حرفه‌ای و چند تب
 */
class Bale_Order_Notifier_Settings {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submit' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menu() {
        add_menu_page(
            __( 'بالیکا', 'balika' ),
            __( 'بالیکا', 'balika' ),
            'manage_woocommerce',
            'balika',
            [ $this, 'render_page' ],
            'dashicons-email-alt',
            56
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'balika' ) === false ) {
            return;
        }
        wp_enqueue_style( 'bon-admin-css', BON_PLUGIN_URL . 'assets/css/admin.css', [], BON_VERSION );
    }

    private function parse_lines( $raw ) {
        return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $raw ) ) ) ) );
    }

    private function save_options($options) {
        $before = get_option('bon_settings', []);
        update_option('bon_settings', $options);
        if (get_option('bon_settings', []) !== $options) {
            add_settings_error('bon_messages','bon_saved','ذخیره تنظیمات ناموفق بود؛ مقادیر ذخیره‌شده را بررسی و دوباره تلاش کنید.','error');
            return false;
        }
        add_settings_error('bon_messages','bon_saved',$before === $options ? 'تنظیمات بدون تغییر است.' : 'تنظیمات ذخیره شد.','success');
        return true;
    }

    private function send_mass_message($post) {
        global $wpdb;
        $message = sanitize_textarea_field($post['broadcast_message'] ?? '');
        $token = $post['broadcast_once'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{32}$/D',$token) || trim($message)==='') {
            add_settings_error('bon_messages','bon_broadcast','متن خالی یا فرم نامعتبر است؛ صفحه را تازه کنید.','error'); return;
        }
        $key='bon_broadcast_'.get_current_user_id().'_'.hash('sha256',$token);
        $lock='bon_mass_'.md5($wpdb->prefix.$key);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))) {
            add_settings_error('bon_messages','bon_broadcast','درخواست در حال پردازش است؛ دوباره ارسال نکنید.','warning'); return;
        }
        try {
            if (!get_transient($key)) {
                add_settings_error('bon_messages','bon_broadcast','این فرم منقضی یا قبلاً پردازش شده است؛ ارسال دوباره انجام نشد.','warning'); return;
            }
            // Consume BEFORE enqueue: on interruption never blindly replay a partially queued batch.
            if (!delete_transient($key) || get_transient($key)) {
                add_settings_error('bon_messages','bon_broadcast','ثبت ایمن درخواست ناموفق بود؛ پیامی ثبت نشد.','error'); return;
            }
            $links=class_exists('Bale_Order_Notifier_Customer_Store') ? Bale_Order_Notifier_Customer_Store::get_all_links() : [];
            $destinations=[];
            foreach ($links as $link) {
                if (($link['platform'] ?? '') !== 'bale') continue;
                $chat=trim((string)($link['chat_id'] ?? ''));
                if ($chat!=='') $destinations[$chat]=true;
            }
            $success=0; $failed=0;
            foreach ($destinations as $chat=>$unused) {
                if (Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>(string)$chat,'text'=>$message],0,'broadcast_'.substr(hash('sha256',$key),0,32))) $success++;
                else $failed++;
            }
            $notice=sprintf('از %d مقصد یکتا، %d پیام در صف ثبت شد و ثبت %d پیام ناموفق بود. ثبت در صف به معنی تحویل یا خوانده‌شدن نیست.',count($destinations),$success,$failed);
            if ($failed) $notice.=' کل پیام را دوباره ارسال نکنید؛ ممکن است برای مقصدهای موفق تکرار شود.';
            add_settings_error('bon_messages','bon_broadcast',$notice,$failed ? ($success ? 'warning':'error') : ($success ? 'success':'info'));
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    public function handle_form_submit() {
        if ( ! isset( $_POST['bon_settings_nonce'] ) || ! wp_verify_nonce( $_POST['bon_settings_nonce'], 'bon_save_settings' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

       $post = wp_unslash($_POST);
        foreach ($post as $value) { if (!is_scalar($value)) { add_settings_error('bon_messages','bon_invalid','نوع ورودی فرم نامعتبر است.','error'); return; } }
        $options = get_option( 'bon_settings', [] );
        $action  = $post['bon_action'] ?? '';

        if (in_array($action,['begin_admin_link','approve_admin_link'],true)) { Bale_Order_Notifier_Admin_Link::handle($post); return; }

        if ( $action === 'save_platforms' ) {
            $options['bale_enabled']      = ! empty( $post['bale_enabled'] );
            $options['bale_bot_token']    = sanitize_text_field( $post['bale_bot_token'] ?? '' );
            $options['bale_bot_username'] = sanitize_text_field( ltrim( $post['bale_bot_username'] ?? '', '@' ) );
            $options['bale_admin_user_ids'] = $this->parse_lines( $post['bale_admin_user_ids'] ?? '' );
            $options['bale_chat_ids']     = $this->parse_lines( $post['bale_chat_ids'] ?? '' );
            // Monetary formatting now follows WooCommerce, without a cosmetic currency override.

            $this->save_options($options);
        }

        if ( $action === 'save_customers' ) {
            $options['customer_notifications_enabled'] = ! empty( $post['customer_notifications_enabled'] );
            $options['show_connect_box_on_thankyou']    = ! empty( $post['show_connect_box_on_thankyou'] );
            $options['notify_customer_on_cancel']       = ! empty( $post['notify_customer_on_cancel'] );
            $options['template_customer_order']         = wp_kses_post( $post['template_customer_order'] ?? '' );
            $options['template_customer_tracking']      = wp_kses_post( $post['template_customer_tracking'] ?? '' );
            $options['template_customer_cancelled']     = wp_kses_post( $post['template_customer_cancelled'] ?? '' );

            $this->save_options($options);
        }

        if ( $action === 'save_notifications' ) {
            $statuses = wc_get_order_statuses();
            $enabled  = [];
            foreach ( $statuses as $key => $label ) {
                $clean_key = str_replace( 'wc-', '', $key );
                if ( ! empty( $post['status_' . $clean_key] ) ) {
                    $enabled[] = $clean_key;
                }
            }
            $options['enabled_statuses']    = $enabled;
            $options['notify_new_order']    = ! empty( $post['notify_new_order'] );
            $options['inline_buttons_on']   = ! empty( $post['inline_buttons_on'] );
            $options['notify_low_stock']    = ! empty( $post['notify_low_stock'] );
            $options['low_stock_threshold'] = intval( $post['low_stock_threshold'] ?? 3 );
            $options['min_order_amount']    = floatval( $post['min_order_amount'] ?? 0 );

            $this->save_options($options);
        }

        if ( $action === 'save_templates' ) {
            $options['template_new_order']     = wp_kses_post( $post['template_new_order'] ?? '' );
            $options['template_status_change'] = wp_kses_post( $post['template_status_change'] ?? '' );
            $options['template_low_stock']     = wp_kses_post( $post['template_low_stock'] ?? '' );

            $this->save_options($options);
        }

        if ( $action === 'save_reports' ) {
            $options['daily_report_enabled']  = ! empty( $post['daily_report_enabled'] );
            $options['weekly_report_enabled'] = ! empty( $post['weekly_report_enabled'] );
            $options['auto_stock_reports']    = ! empty( $post['auto_stock_reports'] );
            $options['report_time']           = sanitize_text_field( $post['report_time'] ?? '23:55' );

            if (!$this->save_options($options)) return;

            wp_clear_scheduled_hook( 'bon_daily_report_event' );
            wp_clear_scheduled_hook( 'bon_weekly_report_event' );
            Bale_Order_Notifier_Cron::ensure_closed_day();
            wp_schedule_event( bon_next_report_timestamp( true ), 'weekly', 'bon_weekly_report_event' );

        }

        if ( $action === 'send_report_now' ) {
            $type = sanitize_text_field( $post['report_type'] ?? '' );
            $types = Bale_Order_Notifier_Reports::get_report_types();
            if ( isset( $types[ $type ] ) ) {
                $sent = Bale_Order_Notifier_Cron::send_report_now( $type );
                $ok   = ! empty( $sent );
                add_settings_error( 'bon_messages', 'bon_report', $ok ? sprintf( __( '«%s» ارسال شد.', 'balika' ), $types[ $type ] ) : __( 'ارسال گزارش ناموفق بود. تنظیمات اتصال را بررسی کنید.', 'balika' ), $ok ? 'success' : 'error' );
            }
        }

        if ( $action === 'test_connection' ) {
            $platform = 'bale';
            $api    = new Bale_Order_Notifier_API( $platform );
            $result = $api->get_me();
            update_option('bon_connection_test',['time'=>time(),'ok'=>(bool)$result,'fingerprint'=>self::connection_fingerprint() ],false);
            $label  = 'بله';
            if ( $result ) {
                add_settings_error( 'bon_messages', 'bon_test', sprintf( __( 'اتصال %s موفق! نام بات: %s', 'balika' ), $label, '@' . ( $result['username'] ?? '' ) ), 'success' );
            } else {
                add_settings_error( 'bon_messages', 'bon_test', sprintf( __( 'اتصال %s ناموفق بود. توکن را بررسی کنید.', 'balika' ), $label ), 'error' );
            }
        }

        if ( $action === 'send_test_message' ) {
            $sent = Bale_Order_Notifier_Dispatcher::broadcast( '✅ این یک پیام تستی از افزونه بالیکا است.' );
            add_settings_error( 'bon_messages', 'bon_test_msg', ! empty( $sent ) ? __( 'پیام تست ارسال شد.', 'balika' ) : __( 'ارسال پیام تست ناموفق بود.', 'balika' ), ! empty( $sent ) ? 'success' : 'error' );
        }

        if ( $action === 'set_webhook' ) {
            $platform = 'bale';
            $api = new Bale_Order_Notifier_API( $platform );
            $url = home_url( '/?' . BON_WEBHOOK_QUERY_VAR . '=1&platform=' . $platform . '&secret=' . bon_get_webhook_secret() );
            $result = $api->set_webhook( $url );
            $label  = 'بله';
            add_settings_error( 'bon_messages', 'bon_webhook', $result ? sprintf( __( 'وبهوک %s با موفقیت تنظیم شد.', 'balika' ), $label ) : sprintf( __( 'تنظیم وبهوک %s ناموفق بود.', 'balika' ), $label ), $result ? 'success' : 'error' );
        }

        if ( $action === 'send_mass_message' ) $this->send_mass_message($post);

        if ( $action === 'save_extra' ) {
            $options['abandoned_cart_enabled']          = ! empty( $post['abandoned_cart_enabled'] );
            $options['abandoned_cart_threshold']        = max( 5, intval( $post['abandoned_cart_threshold'] ?? 30 ) );
            $options['abandoned_cart_remind_customer']  = ! empty( $post['abandoned_cart_remind_customer'] );
            $options['template_cart_reminder']          = sanitize_textarea_field( $post['template_cart_reminder'] ?? '' );
            $options['payment_reminder_enabled']        = ! empty( $post['payment_reminder_enabled'] );
            $options['payment_reminder_threshold']      = max( 10, intval( $post['payment_reminder_threshold'] ?? 60 ) );
            $options['form_notifications_enabled']      = ! empty( $post['form_notifications_enabled'] );
            $options['notify_new_review']               = ! empty( $post['notify_new_review'] );
            $options['notify_review_approved']          = ! empty( $post['notify_review_approved'] );
            $options['notify_new_user_registration']    = ! empty( $post['notify_new_user_registration'] );
            $options['notify_customer_order_note']      = ! empty( $post['notify_customer_order_note'] );

            $this->save_options($options);
        }

        if ( $action === 'regenerate_webhook_secret' ) {
            $options['webhook_secret'] = wp_generate_password( 32, false, false );
            if (!$this->save_options($options)) return;
            add_settings_error( 'bon_messages', 'bon_secret', __( 'توکن مخفی جدید تولید شد. لطفاً وبهوک را دوباره ثبت کنید.', 'balika' ), 'success' );
        }

        if ( $action === 'save_advanced' ) {
            $options['delete_data_on_uninstall'] = ! empty( $post['delete_data_on_uninstall'] );
            $this->save_options($options);
        }

        if ( $action === 'retry_failed' ) {
            $count = Bale_Order_Notifier_Queue::retry_failed();
            add_settings_error( 'bon_messages', 'bon_retry', $count === false ? 'خطا در ثبت تلاش مجدد.' : sprintf( '%d پیام برای تلاش مجدد در صف قرار گرفت.', $count ), $count === false ? 'error' : 'success' );
        }
        if ( $action === 'clear_logs' ) {
            Bale_Order_Notifier_Logger::clear_logs();
            add_settings_error( 'bon_messages', 'bon_logs', __( 'لاگ‌ها پاک شدند.', 'balika' ), 'success' );
        }
    }

    public function render_page() {
        $active_tab = is_string($_GET['tab']??null) ? $_GET['tab'] : 'overview';
        ?>
        <div class="wrap bon-wrap" dir="rtl">
            <div class="bon-header">
                <div class="bon-header-icon">📊</div>
                <div>
                    <h1><?php esc_html_e( 'بالیکا', 'balika' ); ?></h1>
                    <p><?php esc_html_e( 'اعلان سفارشات و گزارش فروش ووکامرس در بله', 'balika' ); ?></p>
                </div>
            </div>

            <?php settings_errors( 'bon_messages' ); ?>

            <h2 class="nav-tab-wrapper bon-tabs">
                <a href="?page=balika&amp;tab=overview" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">نمای کلی</a>
                <a href="?page=balika&tab=platforms" class="nav-tab <?php echo $active_tab === 'platforms' ? 'nav-tab-active' : ''; ?>">🔌 <?php esc_html_e( 'اتصال بله', 'balika' ); ?></a>
                <a href="?page=balika&tab=notifications" class="nav-tab <?php echo $active_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">🔔 <?php esc_html_e( 'اعلان‌ها', 'balika' ); ?></a>
                <a href="?page=balika&tab=customers" class="nav-tab <?php echo $active_tab === 'customers' ? 'nav-tab-active' : ''; ?>">👤 <?php esc_html_e( 'پیام به مشتری', 'balika' ); ?></a>
                <a href="?page=balika&tab=broadcast" class="nav-tab <?php echo $active_tab === 'broadcast' ? 'nav-tab-active' : ''; ?>">📣 <?php esc_html_e( 'پیام انبوه', 'balika' ); ?></a>
                <a href="?page=balika&tab=extra" class="nav-tab <?php echo $active_tab === 'extra' ? 'nav-tab-active' : ''; ?>">🧩 <?php esc_html_e( 'امکانات تکمیلی', 'balika' ); ?></a>
                <a href="?page=balika&tab=templates" class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">✏️ <?php esc_html_e( 'قالب پیام', 'balika' ); ?></a>
                <a href="?page=balika&tab=reports" class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>">📈 <?php esc_html_e( 'گزارش‌گیری', 'balika' ); ?></a>
                <a href="?page=balika&tab=webhook" class="nav-tab <?php echo $active_tab === 'webhook' ? 'nav-tab-active' : ''; ?>">🔗 <?php esc_html_e( 'وبهوک و دکمه‌ها', 'balika' ); ?></a>
                <a href="?page=balika&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">🗒 <?php esc_html_e( 'لاگ‌ها', 'balika' ); ?></a>
            </h2>

            <div class="bon-tab-content">
                <?php
                switch ( $active_tab ) {
                    case 'overview': $this->render_overview_tab(); break;
                    case 'notifications': $this->render_notifications_tab(); break;
                    case 'customers':     $this->render_customers_tab(); break;
                    case 'broadcast':     $this->render_broadcast_tab(); break;
                    case 'extra':         $this->render_extra_tab(); break;
                    case 'templates':     $this->render_templates_tab(); break;
                    case 'reports':       $this->render_reports_tab(); break;
                    case 'webhook':       $this->render_webhook_tab(); break;
                    case 'logs':          $this->render_logs_tab(); break;
                    default:              $this->render_platforms_tab(); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    public static function connection_fingerprint() {
        return hash('sha256',(string)bon_get_option('bale_bot_token',''));
    }
    public static function connection_test_state() {
        $test=get_option('bon_connection_test',[]);
        if (!is_array($test) || empty($test['time']) || !is_string($test['fingerprint']??null) || !hash_equals(self::connection_fingerprint(),$test['fingerprint'])) return ['label'=>'هنوز برای توکن فعلی تستی ثبت نشده است.','time'=>0];
        return ['label'=>!empty($test['ok'])?'آخرین تست اتصال موفق بود؛ سلامت فعلی تضمین نمی‌شود.':'آخرین تست اتصال ناموفق بود؛ تنظیمات اتصال را بررسی کنید.','time'=>(int)$test['time']];
    }
    private function render_overview_tab() {
        $stats=Bale_Order_Notifier_Queue::get_queue_stats();
        $test=self::connection_test_state();
        $last=(int)get_option('bon_queue_last_started',0);
        $next=wp_next_scheduled('bon_process_queue_event');
        echo '<div class="bon-card"><h2>وضعیت بله</h2><p>'.esc_html(bon_get_option('bale_enabled',true)?'بله در تنظیمات فعال است.':'بله در تنظیمات غیرفعال است.').'</p><p>'.esc_html($test['label']).'</p>';
        if ($test['time']) echo '<p>زمان تست: '.esc_html(wp_date('Y-m-d H:i:s',$test['time'])).'</p>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=balika&tab=platforms')).'">تنظیم و تست اتصال</a></div>';
        echo '<div class="bon-card"><h2>صف پیام‌ها</h2><p>منتظر: <strong>'.(int)$stats['pending'].'</strong> — ناموفق نهایی: <strong>'.(int)$stats['dead_letter'].'</strong> — پذیرفته‌شده توسط API: <strong>'.(int)$stats['sent'].'</strong></p><p>این شمارش‌ها فقط مربوط به صف و دوره نگهداری آن هستند؛ پذیرش API به معنی خوانده‌شدن نیست.</p>';
        echo '<p>آخرین شروع پردازش با قفل معتبر: '.esc_html($last?wp_date('Y-m-d H:i:s',$last):'هنوز ثبت نشده').'</p><p>اجرای بعدی برنامه‌ریزی‌شده: '.esc_html($next?wp_date('Y-m-d H:i:s',$next):'زمان‌بندی موجود نیست').'</p><p>شروع پردازش به معنی تکمیل موفق آن نیست؛ زمان برنامه‌ریزی‌شده نیز تضمین اجرای کرون نیست.</p>';
        if (!$next || ($stats['pending'] && (!$last || time()-$last>15*MINUTE_IN_SECONDS))) echo '<p class="notice notice-warning">صف نیاز به بررسی دارد؛ زمان‌بندی و اجرای کرون سایت را بررسی کنید.</p>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=balika&tab=logs')).'">بررسی صف و خطاها</a></div>';
        echo '<div class="bon-card"><h2>کارهای روزانه</h2><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=balika-tracking&order_status=processing&presence=missing')).'">سفارش‌های در حال انجام بدون کد</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=balika-tracking')).'">ثبت رهگیری</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=balika-tracking-import')).'">ورود فایل رهگیری</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=balika-operations')).'">مشتریان و تاریخچه پیام‌ها</a></p></div>';
        $contact=Bale_Order_Notifier_Support::contact();
        echo '<div class="bon-card"><h2>پشتیبانی</h2><p>'.esc_html(!$contact['enabled']?'پشتیبانی غیرفعال است.':($contact['mode']==='direct'?'ارتباط مستقیم با حساب بله':'صندوق وردپرس')).'</p>';
        if ($contact['mode']==='direct') echo '<p>مقصد: '.esc_html($contact['url']?:'تنظیم نشده است').'</p><p>گفت‌وگوهای مستقیم در وردپرس دریافت یا ذخیره نمی‌شوند.</p>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=balika-support')).'">تنظیمات پشتیبانی</a></div>';
    }

    private function render_platforms_tab() {
        Bale_Order_Notifier_Admin_Link::render();
        $bale_enabled     = bon_get_option( 'bale_enabled', true );
        $bale_token       = bon_get_option( 'bale_bot_token' );
        $bale_chat_ids    = bon_get_option( 'bale_chat_ids', [] );
        ?>
        <div class="bon-card bon-card-bale">
            <div class="bon-card-title">
                <span class="bon-platform-icon">🔵</span>
                <h2><?php esc_html_e( 'پیام‌رسان بله', 'balika' ); ?></h2>
                <label class="bon-switch">
                    <input type="checkbox" form="bon-platforms-form" name="bale_enabled" <?php checked( $bale_enabled ); ?>>
                    <span class="bon-slider"></span>
                </label>
            </div>

            <form method="post" id="bon-platforms-form">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_platforms">

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'توکن بات بله', 'balika' ); ?></th>
                        <td><input type="text" name="bale_bot_token" class="regular-text" value="<?php echo esc_attr( $bale_token ); ?>" dir="ltr" placeholder="123456:AaBbCcDd..."></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'یوزرنیم بات بله', 'balika' ); ?></th>
                        <td>
                            <input type="text" name="bale_bot_username" class="regular-text" value="<?php echo esc_attr( bon_get_option( 'bale_bot_username' ) ); ?>" dir="ltr" placeholder="my_shop_bot">
                            <p class="description"><?php esc_html_e( 'بدون @ وارد کنید. برای ساخت لینک اتصال خودکار مشتری لازم است.', 'balika' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Chat ID های مدیران', 'balika' ); ?></th>
                        <td>
                            <textarea name="bale_chat_ids" rows="3" class="large-text" dir="ltr" placeholder="123456789, 987654321"><?php echo esc_textarea( implode( "\n", (array) $bale_chat_ids ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'هر chat_id در یک خط یا جدا شده با کاما.', 'balika' ); ?></p>
                        </td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'واحد پول نمایشی', 'balika' ); ?></th>
                        <td><?php echo esc_html(get_woocommerce_currency()); ?><p class="description">مبلغ و واحد پول از ووکامرس خوانده می‌شود؛ تغییر برچسب دیگر مبلغ ریالی را به‌اشتباه تومان نشان نمی‌دهد.</p></td>
                    </tr>
                </table>

                <h3>شناسه کاربران مدیر بات</h3>
                <p>برای استفاده از دکمه‌ها در گروه، شناسه عددی اشخاص مجاز را وارد کنید؛ هر شناسه در یک خط. شناسه گروه مجوز مدیریت اعضای آن نیست.</p>
                <label>بله <textarea name="bale_admin_user_ids" dir="ltr"><?php echo esc_textarea( implode("\n", (array) bon_get_option('bale_admin_user_ids', []) ) ); ?></textarea></label>
                <?php submit_button( __( '💾 ذخیره تنظیمات اتصال', 'balika' ) ); ?>
            </form>

            <div class="bon-action-row">
                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                    <input type="hidden" name="bon_action" value="test_connection">
                    <input type="hidden" name="platform" value="bale">
                    <?php submit_button( __( '🔍 تست اتصال بله', 'balika' ), 'secondary', 'submit', false ); ?>
                </form>

                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                    <input type="hidden" name="bon_action" value="send_test_message">
                    <?php submit_button( __( '📨 ارسال پیام تست', 'balika' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_notifications_tab() {
        $statuses            = wc_get_order_statuses();
        $enabled             = bon_get_option( 'enabled_statuses', [ 'processing', 'completed', 'cancelled', 'refunded', 'failed' ] );
        $notify_new_order    = bon_get_option( 'notify_new_order', true );
        $inline_buttons_on   = bon_get_option( 'inline_buttons_on', true );
        $notify_low_stock    = bon_get_option( 'notify_low_stock', false );
        $low_stock_threshold = bon_get_option( 'low_stock_threshold', 3 );
        $min_order_amount    = bon_get_option( 'min_order_amount', 0 );
        ?>
        <div class="bon-card">
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_notifications">

                <h2>🛍 <?php esc_html_e( 'سفارش‌های جدید', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_new_order" <?php checked( $notify_new_order ); ?>> <?php esc_html_e( 'ارسال اعلان برای هر سفارش جدید', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="inline_buttons_on" <?php checked( $inline_buttons_on ); ?>> <?php esc_html_e( 'نمایش دکمه‌های شیشه‌ای برای تغییر سریع وضعیت سفارش', 'balika' ); ?></label>

                <div class="bon-field-inline">
                    <label><?php esc_html_e( 'حداقل مبلغ سفارش برای ارسال اعلان:', 'balika' ); ?></label>
                    <input type="number" name="min_order_amount" value="<?php echo esc_attr( $min_order_amount ); ?>" min="0" step="1000">
                    <span class="description"><?php esc_html_e( '(۰ یعنی برای همه سفارشات، صرف‌نظر از مبلغ)', 'balika' ); ?></span>
                </div>

                <h2 style="margin-top:25px;">🔄 <?php esc_html_e( 'اعلان تغییر وضعیت سفارش', 'balika' ); ?></h2>
                <p class="description"><?php esc_html_e( 'برای کدام وضعیت‌ها اعلان ارسال شود؟ (مثلاً پرداخت موفق / تکمیل سفارش)', 'balika' ); ?></p>
                <div class="bon-status-grid">
                <?php foreach ( $statuses as $key => $label ):
                    $clean_key = str_replace( 'wc-', '', $key );
                    ?>
                    <label class="bon-checkbox-row">
                        <input type="checkbox" name="status_<?php echo esc_attr( $clean_key ); ?>" <?php checked( in_array( $clean_key, (array) $enabled, true ) ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
                </div>

                <h2 style="margin-top:25px;">📦 <?php esc_html_e( 'هشدار موجودی انبار', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_low_stock" <?php checked( $notify_low_stock ); ?>> <?php esc_html_e( 'ارسال هشدار وقتی موجودی محصول کم شود', 'balika' ); ?></label>
                <div class="bon-field-inline">
                    <label><?php esc_html_e( 'حد آستانه موجودی:', 'balika' ); ?></label>
                    <input type="number" name="low_stock_threshold" value="<?php echo esc_attr( $low_stock_threshold ); ?>" min="1">
                </div>

                <?php submit_button( __( '💾 ذخیره تنظیمات', 'balika' ) ); ?>
            </form>
        </div>
        <?php
    }

    private function render_customers_tab() {
        $enabled       = bon_get_option( 'customer_notifications_enabled', true );
        $show_box      = bon_get_option( 'show_connect_box_on_thankyou', true );
        $notify_cancel = bon_get_option( 'notify_customer_on_cancel', true );
        $default_order = "سلام {customer_name} عزیز 🌸\n\nسفارش شما با موفقیت ثبت شد.\n\n🧾 شماره سفارش: #{order_id}\n💰 مبلغ: {order_total}\n📦 اقلام:\n{order_items}\n\nهمین که سفارش شما ارسال بشه، کد رهگیری همینجا براتون میفرستیم 📬";
        $default_track = "📦 سفارش #{order_id} شما ارسال شد!\n\n🚚 شرکت پستی: {courier_name}\n🔢 کد رهگیری: {tracking_code}\n{tracking_url_line}\nممنون از خریدتون 🌷";
        $default_cancel = "😔 سفارش #{order_id} شما {order_status} شد.\n\nبرای گفت‌وگو با اپراتور /support را بفرستید؛ اگر پشتیبانی غیرفعال بود، از راه ارتباطی فروشگاه استفاده کنید.";

        $tpl_order  = bon_get_option( 'template_customer_order', $default_order );
        $tpl_track  = bon_get_option( 'template_customer_tracking', $default_track );
        $tpl_cancel = bon_get_option( 'template_customer_cancelled', $default_cancel );

        $links_count = 0;
        if ( class_exists( 'Bale_Order_Notifier_Customer_Store' ) ) {
            global $wpdb;
            $table = Bale_Order_Notifier_Customer_Store::table_name();
            $links_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE platform = 'bale'" );
        }
        ?>
        <div class="bon-stat-cards">
            <div class="bon-stat-card bon-stat-sent">
                <span class="bon-stat-number"><?php echo esc_html( $links_count ); ?></span>
                <span class="bon-stat-label"><?php esc_html_e( 'مشتری متصل به بات', 'balika' ); ?></span>
            </div>
        </div>

        <div class="bon-card">
            <h2>👤 <?php esc_html_e( 'اطلاع‌رسانی مستقیم به مشتری', 'balika' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'با فعال‌سازی این بخش، مشتری با کلیک روی لینک اتصال (در صفحه تشکر از خرید) حساب بله خودش را وصل می‌کند و از آن پس، جزئیات سفارش و کد رهگیری به‌صورت خودکار مستقیماً برایش ارسال می‌شود.', 'balika' ); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_customers">

                <label class="bon-checkbox-row"><input type="checkbox" name="customer_notifications_enabled" <?php checked( $enabled ); ?>> <?php esc_html_e( 'فعال‌سازی اطلاع‌رسانی به مشتری', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="show_connect_box_on_thankyou" <?php checked( $show_box ); ?>> <?php esc_html_e( 'نمایش دکمه «اتصال به بله» در صفحه تشکر از خرید و ایمیل سفارش', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_customer_on_cancel" <?php checked( $notify_cancel ); ?>> <?php esc_html_e( 'اطلاع‌رسانی به مشتری هنگام لغو یا مرجوع شدن سفارش', 'balika' ); ?></label>

                <h2 style="margin-top:20px;">🧾 <?php esc_html_e( 'قالب پیام تایید سفارش برای مشتری', 'balika' ); ?></h2>
                <textarea name="template_customer_order" rows="8" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_order ); ?></textarea>

                <h2 style="margin-top:20px;">📦 <?php esc_html_e( 'قالب پیام کد رهگیری برای مشتری', 'balika' ); ?></h2>
                <textarea name="template_customer_tracking" rows="6" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_track ); ?></textarea>

                <h2 style="margin-top:20px;">😔 <?php esc_html_e( 'قالب پیام لغو/مرجوعی سفارش برای مشتری', 'balika' ); ?></h2>
                <textarea name="template_customer_cancelled" rows="4" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_cancel ); ?></textarea>

                <div class="bon-placeholders">
                    <strong><?php esc_html_e( 'متغیرهای قابل استفاده:', 'balika' ); ?></strong><br>
                    <code>{order_id}</code> <code>{customer_name}</code> <code>{order_total}</code> <code>{order_items}</code>
                    <code>{order_status}</code> <code>{tracking_code}</code> <code>{courier_name}</code> <code>{tracking_url_line}</code>
                </div>

                <?php submit_button( __( '💾 ذخیره تنظیمات مشتری', 'balika' ) ); ?>
            </form>

            <hr>
            <h3><?php esc_html_e( 'راهنما', 'balika' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'یوزرنیم بات را در تب «اتصال بله» وارد کنید.', 'balika' ); ?></li>
                <li><?php esc_html_e( 'مشتری بعد از ثبت سفارش، روی دکمه «اتصال به بله» در صفحه تشکر کلیک می‌کند.', 'balika' ); ?></li>
                <li><?php esc_html_e( 'بلافاصله جزئیات همان سفارش برایش ارسال می‌شود و برای سفارش‌های بعدی (با همان شماره تماس) هم متصل می‌ماند.', 'balika' ); ?></li>
                <li><?php esc_html_e( 'کد رهگیری را از داخل صفحه ویرایش سفارش (باکس سمت راست) وارد کنید تا خودکار برای مشتری ارسال شود.', 'balika' ); ?></li>
            </ol>
        </div>
        <?php
    }

    private function render_broadcast_tab() {
        $total_customers = class_exists( 'Bale_Order_Notifier_Customer_Store' ) ? Bale_Order_Notifier_Customer_Store::count_all_links() : 0;
        ?>
        <div class="bon-stat-cards">
            <div class="bon-stat-card bon-stat-sent">
                <span class="bon-stat-number"><?php echo esc_html( $total_customers ); ?></span>
                <span class="bon-stat-label"><?php esc_html_e( 'اتصال ثبت‌شده (تعداد گیرندگان یکتا ممکن است کمتر باشد)', 'balika' ); ?></span>
            </div>
        </div>

        <div class="bon-card">
            <h2>📣 <?php esc_html_e( 'ارسال پیام انبوه به همه مشتریان متصل', 'balika' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'این پیام به همه مشتریانی که تاکنون حساب بله خود را به فروشگاه متصل کرده‌اند ارسال می‌شود (مناسب برای تخفیف‌ها، جشنواره‌ها و اطلاع‌رسانی عمومی). ارسال از طریق صف پیام انجام می‌شود تا سرور سنگین نشود؛ زمان تکمیل به حجم صف و اجرای کرون بستگی دارد. اگر پردازش قطع شد، کل پیام را کورکورانه دوباره ارسال نکنید؛ ابتدا صف را بررسی کنید.', 'balika' ); ?>
            </p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'این پیام برای همه مشتریان متصل ارسال می‌شود. مطمئنید؟', 'balika' ) ); ?>');">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="send_mass_message">
                <?php $once=wp_generate_password(32,false,false); set_transient('bon_broadcast_'.get_current_user_id().'_'.hash('sha256',$once),true,30 * MINUTE_IN_SECONDS); ?>
                <input type="hidden" name="broadcast_once" value="<?php echo esc_attr($once); ?>">
                <textarea name="broadcast_message" rows="6" class="large-text" dir="rtl" placeholder="<?php esc_attr_e( 'متن پیام را اینجا بنویسید...', 'balika' ); ?>"></textarea>
                <?php submit_button( __( '📣 ارسال به همه مشتریان متصل', 'balika' ), 'primary', 'submit', true ); ?>
            </form>
        </div>
        <?php
    }

    private function render_extra_tab() {
        $cart_enabled     = bon_get_option( 'abandoned_cart_enabled', false );
        $cart_threshold   = bon_get_option( 'abandoned_cart_threshold', 30 );
        $cart_remind      = bon_get_option( 'abandoned_cart_remind_customer', false );
        $tpl_cart         = bon_get_option( 'template_cart_reminder', 'سلام 🌸 سبد خریدتون رو کامل نکردید! اگه هنوز مایلید، همین الان می‌تونید ادامه بدید.' );

        $form_enabled     = bon_get_option( 'form_notifications_enabled', false );
        $review_new       = bon_get_option( 'notify_new_review', false );
        $review_approved  = bon_get_option( 'notify_review_approved', false );
        $new_user         = bon_get_option( 'notify_new_user_registration', false );
        $customer_note    = bon_get_option( 'notify_customer_order_note', false );

        $reminder_enabled = bon_get_option( 'payment_reminder_enabled', false );
        $reminder_threshold = bon_get_option( 'payment_reminder_threshold', 60 );
        ?>
        <div class="bon-card">
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_extra">

                <h2>🛒 <?php esc_html_e( 'سبد خرید رها شده', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="abandoned_cart_enabled" <?php checked( $cart_enabled ); ?>> <?php esc_html_e( 'فعال‌سازی اعلان سبد خرید رها شده به ادمین', 'balika' ); ?></label>
                <div class="bon-field-inline">
                    <label><?php esc_html_e( 'مدت انتظار قبل از اعلام رهاشدگی (دقیقه):', 'balika' ); ?></label>
                    <input type="number" name="abandoned_cart_threshold" value="<?php echo esc_attr( $cart_threshold ); ?>" min="5">
                </div>
                <label class="bon-checkbox-row"><input type="checkbox" name="abandoned_cart_remind_customer" <?php checked( $cart_remind ); ?>> <?php esc_html_e( 'یادآوری مستقیم به خود مشتری (فقط اگر حساب او متصل باشد)', 'balika' ); ?></label>
                <textarea name="template_cart_reminder" rows="3" class="large-text" dir="rtl"><?php echo esc_textarea( $tpl_cart ); ?></textarea>
                <p class="description"><?php esc_html_e( 'توجه: به دلیل محدودیت‌های حریم خصوصی بله، شناسایی مشتری مهمان (بدون ورود و بدون اتصال قبلی) در سبد رهاشده ممکن نیست؛ فقط کاربران عضو یا مشتریانی که قبلاً حساب خود را متصل کرده‌اند شناسایی می‌شوند.', 'balika' ); ?></p>

                <h2 style="margin-top:25px;">⏳ <?php esc_html_e( 'یادآوری پرداخت سفارش معلق', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="payment_reminder_enabled" <?php checked( $reminder_enabled ); ?>> <?php esc_html_e( 'ارسال یادآوری به مشتریانی که سفارششان پرداخت نشده', 'balika' ); ?></label>
                <div class="bon-field-inline">
                    <label><?php esc_html_e( 'مدت انتظار قبل از یادآوری (دقیقه):', 'balika' ); ?></label>
                    <input type="number" name="payment_reminder_threshold" value="<?php echo esc_attr( $reminder_threshold ); ?>" min="10">
                </div>

                <h2 style="margin-top:25px;">📝 <?php esc_html_e( 'فرم‌های سایت', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="form_notifications_enabled" <?php checked( $form_enabled ); ?>> <?php esc_html_e( 'اعلان فرم‌های ثبت‌شده به ادمین (Contact Form 7، WPForms، Gravity Forms، فرم المنتور پرو)', 'balika' ); ?></label>
                <p class="description"><?php esc_html_e( 'به‌صورت خودکار فقط برای فرم‌سازهایی که روی سایت شما نصب و فعال هستند کار می‌کند.', 'balika' ); ?></p>

                <h2 style="margin-top:25px;">💬 <?php esc_html_e( 'دیدگاه‌ها و ثبت‌نام', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_new_review" <?php checked( $review_new ); ?>> <?php esc_html_e( 'اعلان دیدگاه جدید محصول به ادمین', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_review_approved" <?php checked( $review_approved ); ?>> <?php esc_html_e( 'اعلان تایید دیدگاه به خود کاربر (در صورت اتصال حساب)', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_new_user_registration" <?php checked( $new_user ); ?>> <?php esc_html_e( 'اعلان ثبت‌نام کاربر جدید به ادمین', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="notify_customer_order_note" <?php checked( $customer_note ); ?>> <?php esc_html_e( 'ارسال یادداشت سفارش (که برای مشتری می‌نویسید) در بله', 'balika' ); ?></label>

                <?php submit_button( __( '💾 ذخیره تنظیمات', 'balika' ) ); ?>
            </form>
        </div>
        <?php
    }

    private function render_templates_tab() {
        $default_new_order = "🛍 سفارش جدید #{order_id}\n\n👤 مشتری: {customer_name}\n📞 تماس: {customer_phone}\n💰 مبلغ: {order_total}\n📦 محصولات:\n{order_items}\n\n📍 آدرس: {shipping_address}";
        $default_status     = "🔄 وضعیت سفارش #{order_id} تغییر کرد\n\nوضعیت جدید: {order_status}\n👤 مشتری: {customer_name}\n💰 مبلغ: {order_total}";
        $default_low_stock  = "⚠️ هشدار موجودی کم\n\nمحصول: {product_name}\nموجودی فعلی: {stock_quantity}";

        $tpl_new    = bon_get_option( 'template_new_order', $default_new_order );
        $tpl_status = bon_get_option( 'template_status_change', $default_status );
        $tpl_stock  = bon_get_option( 'template_low_stock', $default_low_stock );
        ?>
        <div class="bon-card">
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_templates">

                <h2>🆕 <?php esc_html_e( 'قالب پیام سفارش جدید (پرداخت موفق)', 'balika' ); ?></h2>
                <textarea name="template_new_order" rows="8" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_new ); ?></textarea>

                <h2 style="margin-top:20px;">🔄 <?php esc_html_e( 'قالب پیام تکمیل سفارش / تغییر وضعیت', 'balika' ); ?></h2>
                <textarea name="template_status_change" rows="6" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_status ); ?></textarea>

                <h2 style="margin-top:20px;">⚠️ <?php esc_html_e( 'قالب هشدار موجودی', 'balika' ); ?></h2>
                <textarea name="template_low_stock" rows="4" class="large-text code" dir="rtl"><?php echo esc_textarea( $tpl_stock ); ?></textarea>

                <div class="bon-placeholders">
                    <strong><?php esc_html_e( 'متغیرهای پویا قابل استفاده:', 'balika' ); ?></strong><br>
                    <code>{order_id}</code> <code>{customer_name}</code> <code>{customer_phone}</code>
                    <code>{order_total}</code> <code>{order_items}</code> <code>{shipping_address}</code>
                    <code>{order_status}</code> <code>{payment_method}</code> <code>{order_date}</code>
                    <code>{product_name}</code> <code>{stock_quantity}</code>
                </div>
                <div class="bon-placeholders">
                    <strong><?php esc_html_e( 'فرمت‌بندی متن در بله:', 'balika' ); ?></strong><br>
                    <?php esc_html_e( 'بله از تگ HTML پشتیبانی نمی‌کند. برای پررنگ کردن، متن را با یک فاصله + ستاره قبل و بعدش بنویسید، مثال:', 'balika' ); ?>
                    <code dir="ltr">سلام *متن پررنگ* خوش‌آمدید</code><br>
                    <?php esc_html_e( 'برای ایتالیک از زیرخط به همین شکل استفاده کنید:', 'balika' ); ?>
                    <code dir="ltr">سلام _متن ایتالیک_ خوش‌آمدید</code>
                </div>

                <?php submit_button( __( '💾 ذخیره قالب‌ها', 'balika' ) ); ?>
            </form>
        </div>
        <?php
    }

    private function render_reports_tab() {
        $daily   = bon_get_option( 'daily_report_enabled', false );
        $weekly  = bon_get_option( 'weekly_report_enabled', false );
        $auto_stock = bon_get_option( 'auto_stock_reports', false );
        $time    = bon_get_option( 'report_time', '23:55' );
        ?>
        <div class="bon-card">
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_reports">

                <h2>⏰ <?php esc_html_e( 'گزارش‌گیری خودکار زمان‌بندی‌شده', 'balika' ); ?></h2>
                <label class="bon-checkbox-row"><input type="checkbox" name="daily_report_enabled" <?php checked( $daily ); ?>> <?php esc_html_e( 'ارسال گزارش کامل روز تمام‌شده بعد از نیمه‌شب', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="weekly_report_enabled" <?php checked( $weekly ); ?>> <?php esc_html_e( 'ارسال خودکار گزارش هفتگی + پرفروش‌ترین محصولات (شنبه شب)', 'balika' ); ?></label>
                <label class="bon-checkbox-row"><input type="checkbox" name="auto_stock_reports" <?php checked( $auto_stock ); ?>> <?php esc_html_e( 'ضمیمه کردن گزارش موجودی کم/ناموجود به گزارش روزانه', 'balika' ); ?></label>

                <div class="bon-field-inline">
                    <label><?php esc_html_e( 'ساعت گزارش هفتگی (روزانه پس از نیمه‌شب):', 'balika' ); ?></label>
                    <input type="time" name="report_time" value="<?php echo esc_attr( $time ); ?>">
                </div>

                <?php submit_button( __( '💾 ذخیره تنظیمات گزارش', 'balika' ) ); ?>
            </form>

            <hr>
            <h2>⚡ <?php esc_html_e( 'ارسال دستی گزارش (همین الان)', 'balika' ); ?></h2>
            <p class="description"><?php esc_html_e( 'روی هر دکمه بزنید تا آن گزارش فوراً به تمام مقاصد فعال ارسال شود.', 'balika' ); ?></p>
            <div class="bon-report-grid">
                <?php foreach ( Bale_Order_Notifier_Reports::get_report_types() as $key => $label ): ?>
                    <form method="post">
                        <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                        <input type="hidden" name="bon_action" value="send_report_now">
                        <input type="hidden" name="report_type" value="<?php echo esc_attr( $key ); ?>">
                        <button type="submit" class="button bon-report-btn"><?php echo esc_html( $label ); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_webhook_tab() {
        $secret   = bon_get_webhook_secret();
        $bale_url = home_url( '/?' . BON_WEBHOOK_QUERY_VAR . '=1&platform=bale&secret=' . $secret );
        ?>
        <div class="bon-card">
            <h2>🔗 <?php esc_html_e( 'وبهوک دکمه‌های شیشه‌ای و گزارش‌گیری زنده', 'balika' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'با ثبت وبهوک، هم دکمه‌های تغییر وضعیت سفارش و هم منوی گزارش‌گیری زنده (با ارسال دستور /reports به بات) فعال می‌شود.', 'balika' ); ?>
            </p>
            <p style="background:#fff8e5; border:1px solid #f0dca0; border-radius:6px; padding:10px 14px; color:#7a5c00;">
                ⚠️ <?php esc_html_e( 'این آدرس شامل یک توکن مخفی است. آن را در جایی به‌جز پنل بله وارد یا منتشر نکنید؛ هرکس این آدرس را داشته باشد می‌تواند به‌جای شما با بات صحبت کند.', 'balika' ); ?>
            </p>

            <h3><?php esc_html_e( 'آدرس وبهوک بله', 'balika' ); ?></h3>
            <p><code dir="ltr"><?php echo esc_html( $bale_url ); ?></code></p>
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="set_webhook">
                <input type="hidden" name="platform" value="bale">
                <?php submit_button( __( 'ثبت خودکار وبهوک بله', 'balika' ), 'primary', 'submit', false ); ?>
            </form>



            <hr>
            <h3><?php esc_html_e( '📋 دستورات قابل استفاده در بات', 'balika' ); ?></h3>
            <p><code>/reports</code> — <?php esc_html_e( 'نمایش منوی دکمه‌های گزارش‌گیری زنده', 'balika' ); ?></p>

            <h3 style="margin-top:15px;"><?php esc_html_e( 'راهنمای گرفتن Chat ID', 'balika' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'یک پیام به بات خودتان بفرستید.', 'balika' ); ?></li>
                <li><?php esc_html_e( 'وبهوک را طبق دستور بالا ثبت کنید.', 'balika' ); ?></li>
                <li><?php esc_html_e( 'chat_id شما به‌صورت خودکار در تب لاگ‌ها ثبت می‌شود.', 'balika' ); ?></li>
            </ol>

            <hr>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'با بازتولید توکن، باید دوباره وبهوک را ثبت کنید. ادامه می‌دهید؟', 'balika' ) ); ?>');">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="regenerate_webhook_secret">
                <?php submit_button( __( '🔄 بازتولید توکن مخفی وبهوک', 'balika' ), 'delete', 'submit', false ); ?>
                <p class="description"><?php esc_html_e( 'اگر گمان می‌کنید آدرس وبهوک شما لو رفته، از این دکمه استفاده کنید و سپس دوباره وبهوک را ثبت کنید.', 'balika' ); ?></p>
            </form>
        </div>
        <?php
    }

    private function render_logs_tab() {
        $logs  = Bale_Order_Notifier_Logger::get_logs( 100 );
        $stats = class_exists( 'Bale_Order_Notifier_Queue' ) ? Bale_Order_Notifier_Queue::get_queue_stats() : [ 'pending' => 0, 'dead_letter' => 0, 'sent' => 0 ];
        ?>
        <div class="bon-card">
            <h2>سلامت صف ارسال</h2>
            <p>زمان اجرای بعدی صف: <?php $next = wp_next_scheduled('bon_process_queue_event'); echo esc_html( $next ? wp_date('Y-m-d H:i:s', $next) : 'زمان‌بندی موجود نیست؛ افزونه را یک بار غیرفعال و فعال کنید.' ); ?></p>
            <p>ارسال‌های موفق و ناموفق نهایی پس از ۳۰ روز از صف پاک می‌شوند. پیش از تلاش مجدد، مشکل اتصال را رفع کنید.</p>
            <form method="post">
                <?php wp_nonce_field('bon_save_settings', 'bon_settings_nonce'); ?>
                <input type="hidden" name="bon_action" value="retry_failed">
                <?php submit_button('تلاش مجدد پیام‌های ناموفق', 'secondary'); ?>
            </form>
        </div>
        <div class="bon-stat-cards">
            <div class="bon-stat-card bon-stat-pending">
                <span class="bon-stat-number"><?php echo (int) $stats['pending']; ?></span>
                <span class="bon-stat-label"><?php esc_html_e( 'در صف ارسال', 'balika' ); ?></span>
            </div>
            <div class="bon-stat-card bon-stat-sent">
                <span class="bon-stat-number"><?php echo (int) $stats['sent']; ?></span>
                <span class="bon-stat-label"><?php esc_html_e( 'ارسال موفق (صف)', 'balika' ); ?></span>
            </div>
            <div class="bon-stat-card bon-stat-dead">
                <span class="bon-stat-number"><?php echo (int) $stats['dead_letter']; ?></span>
                <span class="bon-stat-label"><?php esc_html_e( 'ناموفق نهایی', 'balika' ); ?></span>
            </div>
        </div>

        <div class="bon-card">
            <h2>⚙️ <?php esc_html_e( 'تنظیمات پیشرفته', 'balika' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="save_advanced">
                <label class="bon-checkbox-row">
                    <input type="checkbox" name="delete_data_on_uninstall" <?php checked( bon_get_option( 'delete_data_on_uninstall', false ) ); ?>>
                    <?php esc_html_e( 'هنگام حذف کامل پلاگین، تمام تنظیمات و جدول‌های آن (لاگ‌ها، صف ارسال، اتصال مشتریان) پاک شوند', 'balika' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'اگر غیرفعال باشد (پیش‌فرض)، در صورت نصب مجدد پلاگین همه تنظیمات و اتصال مشتریان حفظ می‌شود.', 'balika' ); ?></p>
                <?php submit_button( __( '💾 ذخیره', 'balika' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>

        <div class="bon-card">
            <form method="post" style="margin-bottom:15px;">
                <?php wp_nonce_field( 'bon_save_settings', 'bon_settings_nonce' ); ?>
                <input type="hidden" name="bon_action" value="clear_logs">
                <?php submit_button( __( '🗑 پاک کردن لاگ‌ها', 'balika' ), 'delete', 'submit', false ); ?>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'زمان', 'balika' ); ?></th>
                        <th><?php esc_html_e( 'نوع', 'balika' ); ?></th>
                        <th><?php esc_html_e( 'پیام', 'balika' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ): ?>
                        <tr><td colspan="3"><?php esc_html_e( 'هنوز لاگی ثبت نشده است.', 'balika' ); ?></td></tr>
                    <?php else: foreach ( $logs as $log ): ?>
                        <tr>
                            <td><?php echo esc_html( $log->log_time ); ?></td>
                            <td><span class="bon-badge bon-badge-<?php echo esc_attr( $log->type ); ?>"><?php echo esc_html( $log->type ); ?></span></td>
                            <td><?php echo esc_html( $log->message ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
