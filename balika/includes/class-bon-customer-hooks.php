<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس هوک‌های مشتری:
 * ۱) نمایش دکمه «اتصال به بله» در صفحه تشکر بعد از خرید
 * ۲) ارسال خودکار جزئیات سفارش برای خود مشتری (در صورت اتصال حساب)
 * ۳) افزودن باکس «کد رهگیری» به صفحه ویرایش سفارش و ارسال خودکار آن به مشتری هنگام ثبت
 */
class Bale_Order_Notifier_Customer_Hooks {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            // حتی اگر غیرفعال باشد، متاباکس کد رهگیری برای مدیر مفید است؛ بنابراین همیشه ثبت می‌شود
        }

        add_action( 'woocommerce_thankyou', [ $this, 'render_connect_box' ], 5 );
        add_action( 'woocommerce_email_before_order_table', [ $this, 'render_connect_box_email' ], 5, 3 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_new_order_notify_customer' ], 30, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_new_order_notify_customer' ], 30, 1 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed_notify_customer' ], 25, 4 );
        add_action( 'bon_payment_reminder_event', [ $this, 'send_payment_reminders' ] );

        add_action( 'add_meta_boxes', [ $this, 'register_tracking_meta_box' ] );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_tracking_meta_box' ], 20, 1 );
        add_action( 'save_post_shop_order', [ $this, 'save_tracking_meta_box' ], 20, 1 );
    }

    /**
     * جست‌وجوی جدیدترین سفارش‌های ثبت‌شده با یک شماره تلفن مشخص (با تمام فرمت‌های احتمالی ذخیره‌شده در ووکامرس)
     */
    public function find_recent_orders_by_phone( $phone, $limit = 3 ) {
        $normalized = Bale_Order_Notifier_Customer_Store::normalize_phone( $phone );
        if ( empty( $normalized ) ) {
            return [];
        }
        $local10 = substr( $normalized, 3 ); // حذف +98 → 9xxxxxxxxx

        $variants = array_unique( [
            $normalized,        // +989121234567
            '0' . $local10,     // 09121234567 (فرمت رایج ذخیره‌شده در ووکامرس)
            $local10,           // 9121234567
            '98' . $local10,    // 989121234567
            '0098' . $local10,  // 0098912...
        ] );

        $found = [];
        foreach ( $variants as $variant ) {
            foreach ( wc_get_orders( [ 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'billing_phone' => $variant ] ) as $order ) {
                $found[ $order->get_id() ] = $order;
            }
        }
        usort( $found, function ( $a, $b ) {
            return $b->get_date_created()->getTimestamp() <=> $a->get_date_created()->getTimestamp();
        } );
        return array_slice( $found, 0, $limit );
    }

    /**
     * پردازش پیام حاوی شماره تلفن از سمت مشتری:
     * حساب را متصل می‌کند و بر اساس جدیدترین سفارش، کد رهگیری (در صورت وجود) یا وضعیت فعلی را پاسخ می‌دهد.
     * این تنها راهی است که مشتری با ارسال شماره‌اش اطلاعات دریافت می‌کند؛ هیچ داده دیگری از سایت افشا نمی‌شود.
     */
    public function handle_phone_message( $platform, $chat_id, $raw_phone ) {
        $api = new Bale_Order_Notifier_API( $platform );

        // --- محدودیت نرخ: حداکثر ۵ تلاش شماره تلفن در هر ساعت برای هر chat_id ---
        // جلوگیری از سوءاستفاده برای حدس زدن این‌که کدام شماره‌ها در سایت سفارش ثبت کرده‌اند
        $rate_key   = 'bon_phone_rl_' . $platform . '_' . $chat_id;
        $attempts   = (int) get_transient( $rate_key );
        if ( $attempts >= 5 ) {
            $api->send_message( $chat_id, '⏳ تعداد درخواست‌های شما زیاد بوده. لطفاً چند دقیقه دیگر دوباره امتحان کنید.' );
            Bale_Order_Notifier_Logger::log( 'error', "محدودیت نرخ برای chat_id {$chat_id} در {$platform} فعال شد (احتمال حدس‌زنی شماره)." );
            return;
        }
        set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

        if ( ! Bale_Order_Notifier_Customer_Store::owns_phone( $raw_phone, $platform, $chat_id ) ) {
            $api->send_message( $chat_id, 'برای اتصال امن، از دکمه اتصال در صفحه تشکر یا ایمیل سفارش استفاده کنید. ارسال شماره تلفن به‌تنهایی کافی نیست.' );
            return;
        }

        $orders = $this->find_recent_orders_by_phone( $raw_phone, 1 );

        if ( empty( $orders ) ) {
            $api->send_message( $chat_id, '✅ شماره شما ثبت شد، اما سفارشی با این شماره پیدا نکردم. برای سفارش‌های بعدی، اطلاعات به‌صورت خودکار همینجا ارسال می‌شود.' );
            Bale_Order_Notifier_Logger::log( 'info', "مشتری شماره {$raw_phone} را ارسال کرد اما سفارشی یافت نشد." );
            return;
        }

        $order         = $orders[0];
        $tracking_code = $order->get_meta( '_bon_tracking_code' );

        if ( ! empty( $tracking_code ) ) {
            $message = $this->build_customer_message( 'template_customer_tracking', $order );
        } else {
            $message  = $this->build_customer_message( 'template_customer_order', $order );
            $message .= "\n\n📦 وضعیت فعلی: " . wc_get_order_status_name( $order->get_status() ) . "\nهمین که مرسوله ارسال بشه، کد رهگیری همینجا براتون میاد.";
        }

        $api->send_message( $chat_id, $message );
        Bale_Order_Notifier_Logger::log( 'success', "پاسخ خودکار به مشتری (شماره {$raw_phone}) برای سفارش #{$order->get_id()} ارسال شد." );
    }

    /**
     * ساخت لینک دیپ‌لینک اتصال حساب مشتری به بات (بر پایه شماره سفارش)
     */
    private function build_connect_link( $platform, $order_id ) {
        if ( $platform !== 'bale' ) return '';
        $username = bon_get_option( $platform . '_bot_username' );
        if ( empty( $username ) ) {
            return '';
        }
        $base = 'https://ble.ir/';
        $token = wp_generate_password( 40, false, false );
        set_transient( 'bon_connect_' . hash( 'sha256', $token ), (int) $order_id, DAY_IN_SECONDS );
        return $base . ltrim( $username, '@' ) . '?start=connect_' . $token;
    }

    /**
     * نمایش باکس «برای پیگیری سفارش در بله عضو شوید» در صفحه تشکر از خرید
     */
    public function render_connect_box( $order_id ) {
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            return;
        }
        if ( ! bon_get_option( 'show_connect_box_on_thankyou', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // اگر شماره تلفن مشتری از قبل متصل است، نیازی به نمایش دوباره نیست
        // Always allow the order owner to reconnect using a fresh secure link.

        $bale_link = bon_get_option( 'bale_enabled', true ) ? $this->build_connect_link( 'bale', $order_id ) : '';
        if ( empty( $bale_link ) ) {
            return; // بدون یوزرنیم بات، لینک قابل ساخت نیست
        }
        ?>
        <div style="background:#eef7f0; border:1px solid #b7dfc0; border-radius:8px; padding:16px 20px; margin:20px 0; text-align:center;" dir="rtl">
            <p style="margin:0 0 10px; font-weight:bold; font-size:15px;">📲 برای دریافت آنی اطلاعات سفارش و کد رهگیری در بله عضو شوید</p>
            <a href="<?php echo esc_url( $bale_link ); ?>" target="_blank" style="display:inline-block; background:#00b140; color:#fff; padding:10px 22px; border-radius:6px; text-decoration:none; font-weight:bold;">اتصال به بله ✅</a>
        </div>
        <?php
    }

    /**
     * نمایش همان دکمه اتصال داخل ایمیل تایید سفارش، به‌عنوان یادآوری دوم
     * (برای مواردی که مشتری در صفحه تشکر از خرید کلیک نکرده)
     */
    public function render_connect_box_email( $order, $sent_to_admin, $plain_text ) {
        if ( $sent_to_admin || $plain_text ) {
            return; // فقط برای ایمیل HTML مشتری، نه ایمیل مدیر و نه نسخه متنی
        }
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            return;
        }
        if ( ! bon_get_option( 'show_connect_box_on_thankyou', true ) ) {
            return;
        }
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        // Always allow the order owner to reconnect using a fresh secure link.

        $bale_link = bon_get_option( 'bale_enabled', true ) ? $this->build_connect_link( 'bale', $order->get_id() ) : '';
        if ( empty( $bale_link ) ) {
            return;
        }
        ?>
        <div style="background:#eef7f0; border:1px solid #b7dfc0; border-radius:8px; padding:16px 20px; margin:0 0 20px; text-align:center;" dir="rtl">
            <p style="margin:0 0 10px; font-weight:bold; font-size:15px;">📲 برای دریافت آنی وضعیت سفارش و کد رهگیری در بله عضو شوید</p>
            <a href="<?php echo esc_url( $bale_link ); ?>" style="display:inline-block; background:#00b140; color:#fff; padding:10px 22px; border-radius:6px; text-decoration:none; font-weight:bold;">اتصال به بله ✅</a>
        </div>
        <?php
    }

    /**
     * بعد از ثبت سفارش، در صورت اتصال قبلی حساب مشتری، جزئیات سفارش برایش ارسال شود
     */
    public function on_new_order_notify_customer( $order_id ) {
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_bon_customer_notified' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $this->send_order_confirmation_to_customer( $order );
    }

    /**
     * ساخت و ارسال پیام تایید سفارش به مشتری (برای همه پلتفرم‌هایی که مشتری متصل است)
     */
    public function send_order_confirmation_to_customer( $order ) {
        $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $order->get_billing_phone() );
        if ( empty( $links ) ) {
            return false;
        }

        $message = $this->build_customer_message( 'template_customer_order', $order );
        $sent    = false;
        foreach ( $links as $link ) {
            $api    = new Bale_Order_Notifier_API( $link['platform'] );
            $result = $api->send_message( $link['chat_id'], $message, [], true, $order->get_id(), 'customer_order' );
            if ( $result ) {
                $sent = true;
            }
        }

        if ( $sent ) {
            $order->update_meta_data( '_bon_customer_notified', 1 );
            $order->save_meta_data();
            Bale_Order_Notifier_Logger::log( 'success', "پیام تایید سفارش برای مشتری سفارش #{$order->get_id()} ارسال شد." );
        }
        return $sent;
    }

    /**
     * اطلاع‌رسانی به مشتری هنگام لغو یا مرجوع شدن سفارش (در صورت اتصال حساب)
     */
    public function on_status_changed_notify_customer( $order_id, $old_status, $new_status, $order ) {
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            return;
        }
        if ( ! bon_get_option( 'notify_customer_on_cancel', true ) ) {
            return;
        }
        if ( ! in_array( $new_status, [ 'cancelled', 'refunded' ], true ) ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $order->get_billing_phone() );
        if ( empty( $links ) ) {
            return;
        }

        $message = $this->build_customer_message( 'template_customer_cancelled', $order );
        foreach ( $links as $link ) {
            $api = new Bale_Order_Notifier_API( $link['platform'] );
            $api->send_message( $link['chat_id'], $message, [], true, $order->get_id(), 'customer_order' );
        }
        Bale_Order_Notifier_Logger::log( 'success', "اطلاع‌رسانی {$new_status} سفارش #{$order_id} برای مشتری ارسال شد." );
    }

    /**
     * یادآوری پرداخت برای سفارش‌های «در انتظار پرداخت» که مدتی از ثبت آن‌ها گذشته
     * فقط برای مشتریانی که حساب بله‌شان متصل است ارسال می‌شود.
     */
    public function send_payment_reminders() {
        if ( ! bon_get_option( 'payment_reminder_enabled', false ) ) {
            return;
        }

        $threshold_minutes = (int) bon_get_option( 'payment_reminder_threshold', 60 );
        $cutoff             = strtotime( "-{$threshold_minutes} minutes" );

        $orders = wc_get_orders( [
            'limit'        => 30,
            'status'       => [ 'pending' ],
            'type'         => 'shop_order',
            'page' => max( 1, (int) get_option( 'bon_reminder_page', 1 ) ),
            'orderby' => 'date',
            'order' => 'ASC',
            'date_created' => '<' . $cutoff, // ثبت‌شده قبل از زمان آستانه
        ] );

        $page = max( 1, (int) get_option( 'bon_reminder_page', 1 ) );
        update_option( 'bon_reminder_page', count( $orders ) === 30 ? $page + 1 : 1, false );
        foreach ( $orders as $order ) {
            if ( $order->get_meta( '_bon_payment_reminder_sent' ) ) {
                continue; // فقط یک بار یادآوری ارسال شود
            }

            $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $order->get_billing_phone() );
            if ( empty( $links ) ) {
                continue;
            }

            $message = $this->build_customer_message( 'template_payment_reminder', $order );

            $accepted = true;
            foreach ( $links as $link ) {
                $accepted = Bale_Order_Notifier_Queue::enqueue( $link['platform'], 'sendMessage', [ 'chat_id' => $link['chat_id'], 'text' => $message ], $order->get_id(), 'payment_reminder' ) && $accepted;
            }
            if ( ! $accepted ) continue;

            $order->update_meta_data( '_bon_payment_reminder_sent', 1 );
            $order->save();
            Bale_Order_Notifier_Logger::log( 'success', "یادآوری پرداخت سفارش #{$order->get_id()} در صف قرار گرفت." );
        }
    }

    /**
     * افزودن متاباکس «کد رهگیری پستی» به صفحه ویرایش سفارش
     */
    public function register_tracking_meta_box() {
        $screens = [ 'shop_order' ];
        // سازگاری با HPOS (جدول سفارشات جدید ووکامرس)
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $screens = [ wc_get_page_screen_id( 'shop-order' ) ];
        }

        foreach ( $screens as $screen ) {
            add_meta_box(
                'bon_tracking_meta_box',
                __( '📦 کد رهگیری بله برای مشتری', 'balika' ),
                [ $this, 'render_tracking_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_tracking_meta_box( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) {
            return;
        }
        $tracking_code = $order->get_meta( '_bon_tracking_code' );
        $courier_name  = $order->get_meta( '_bon_courier_name' );
        $tracking_url  = $order->get_meta( '_bon_tracking_url' );

        wp_nonce_field( 'bon_save_tracking', 'bon_tracking_nonce' );
        ?>
        <p>
            <label for="bon_courier_name"><?php esc_html_e( 'نام شرکت پستی/باربری', 'balika' ); ?></label>
            <input type="text" id="bon_courier_name" name="bon_courier_name" class="widefat" value="<?php echo esc_attr( $courier_name ); ?>" placeholder="مثلاً پست پیشتاز">
        </p>
        <p>
            <label for="bon_tracking_code"><?php esc_html_e( 'کد رهگیری', 'balika' ); ?></label>
            <input type="text" id="bon_tracking_code" name="bon_tracking_code" class="widefat" value="<?php echo esc_attr( $tracking_code ); ?>" dir="ltr">
        </p>
        <p>
            <label for="bon_tracking_url"><?php esc_html_e( 'لینک رهگیری (اختیاری)', 'balika' ); ?></label>
            <input type="url" id="bon_tracking_url" name="bon_tracking_url" class="widefat" value="<?php echo esc_attr( $tracking_url ); ?>" dir="ltr" placeholder="https://tracking.post.ir/...">
        </p>
        <p class="description"><?php esc_html_e( 'با ذخیره سفارش، در صورت وارد یا تغییر بودن کد رهگیری، پیام به‌صورت خودکار برای مشتری (در صورت اتصال حساب) ارسال می‌شود.', 'balika' ); ?></p>
        <?php
    }

    /**
     * ذخیره فیلدهای متاباکس و ارسال پیام کد رهگیری در صورت تغییر
     */
    public function save_tracking_meta_box( $order_id ) {
        if ( ! isset( $_POST['bon_tracking_nonce'] ) || ! wp_verify_nonce( $_POST['bon_tracking_nonce'], 'bon_save_tracking' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( !is_scalar($_POST['bon_tracking_code'] ?? null) || trim((string) $_POST['bon_tracking_code']) === '' ) return;
        static $saving = false;
        if ($saving) return;
        $saving = true;
        try {
        $result = Bale_Order_Notifier_Tracking::save( $order_id, [
            'code' => wp_unslash( $_POST['bon_tracking_code'] ?? '' ),
            'courier' => wp_unslash( $_POST['bon_courier_name'] ?? '' ),
            'url' => wp_unslash( $_POST['bon_tracking_url'] ?? '' ),
        ], '', [], '', true );
        if ( is_wp_error( $result ) ) Bale_Order_Notifier_Logger::log( 'error', $result->get_error_message() );
        } finally { $saving = false; }
    }

    /**
     * ارسال پیام کد رهگیری به مشتری
     */
    public function send_tracking_to_customer( $order ) {
        if ( ! bon_get_option( 'customer_notifications_enabled', true ) ) {
            return false;
        }

        $links = Bale_Order_Notifier_Customer_Store::get_links_for_phone( $order->get_billing_phone() );
        if ( empty( $links ) ) {
            Bale_Order_Notifier_Logger::log( 'info', "کد رهگیری سفارش #{$order->get_id()} ثبت شد اما مشتری به بات متصل نیست." );
            return false;
        }

        $message = $this->build_customer_message( 'template_customer_tracking', $order );
        $sent    = false;
        foreach ( $links as $link ) {
            $api    = new Bale_Order_Notifier_API( $link['platform'] );
            $result = $api->send_message( $link['chat_id'], $message, [], true, $order->get_id(), 'customer_order' );
            if ( $result ) {
                $sent = true;
            }
        }

        if ( $sent ) {
            Bale_Order_Notifier_Logger::log( 'success', "کد رهگیری سفارش #{$order->get_id()} برای مشتری ارسال شد." );
        }
        return $sent;
    }

    /**
     * ساخت متن پیام مشتری از روی قالب انتخابی
     */
    public function build_customer_message( $template_key, $order, $preview_template = null ) {
        $defaults = [
            'template_customer_status'    => 'سفارش #{order_id} به وضعیت {order_status} تغییر کرد.',
            'template_customer_order'     => "سلام {customer_name} عزیز 🌸\n\nسفارش شما با موفقیت ثبت شد.\n\n🧾 شماره سفارش: #{order_id}\n💰 مبلغ: {order_total}\n📦 اقلام:\n{order_items}\n\nهمین که سفارش شما ارسال بشه، کد رهگیری همینجا براتون میفرستیم 📬",
            'template_customer_tracking'  => "📦 سفارش #{order_id} شما ارسال شد!\n\n🚚 شرکت پستی: {courier_name}\n🔢 کد رهگیری: {tracking_code}\n{tracking_url_line}\nممنون از خریدتون 🌷",
            'template_customer_cancelled' => "😔 سفارش #{order_id} شما {order_status} شد.\n\nبرای گفت‌وگو با اپراتور /support را بفرستید؛ اگر پشتیبانی غیرفعال بود، از راه ارتباطی فروشگاه استفاده کنید.",
            'template_payment_reminder'   => "سلام {customer_name} عزیز 🌸\n\nسفارش #{order_id} شما به ارزش {order_total} هنوز پرداخت نشده.\nاگه مایلید، می‌تونید الان تکمیلش کنید تا براتون آماده و ارسال بشه.\n{payment_url}",
        ];

        $template = $preview_template !== null ? $preview_template : bon_get_option( $template_key, $defaults[ $template_key ] ?? '' );

        $items_text = '';
        foreach ( $order->get_items() as $item ) {
            $items_text .= '• ' . $item->get_name() . ' × ' . $item->get_quantity() . "\n";
        }

        $tracking_code = $order->get_meta( '_bon_tracking_code' );
        $courier       = $order->get_meta( '_bon_courier_name' ) ?: '-';
        $tracking_url  = $order->get_meta( '_bon_tracking_url' );

        $replacements = [
            '{order_id}'          => $order->get_order_number(),
            '{customer_name}'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: 'مشتری عزیز',
            '{order_total}'       => bon_money( $order->get_total(), $order->get_currency() ),
            '{order_items}'       => trim( $items_text ) ?: '-',
            '{order_status}'      => wc_get_order_status_name( $order->get_status() ),
            '{payment_url}'      => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
            '{tracking_code}'     => $tracking_code ?: '-',
            '{courier_name}'      => $courier,
            '{tracking_url_line}' => $tracking_url ? ( '🔗 لینک رهگیری: ' . $tracking_url . "\n" ) : '',
        ];

        $message = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
        if ( $template_key === 'template_payment_reminder' && $order->needs_payment() && strpos($template, '{payment_url}') === false ) {
            $message .= "\nپرداخت سفارش: " . $order->get_checkout_payment_url();
        }
        return $message;
    }
}
