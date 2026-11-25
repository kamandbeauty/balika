<?php
if (!defined('ABSPATH')) exit;

/** Optional storefront. It never replaces the site's theme, checkout or authentication. */
class Bale_Order_Notifier_Commerce {
    public function __construct() {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('template_redirect', [$this, 'render'], 3);
        add_action('wc_ajax_bon_commerce_cart', [$this, 'cart_endpoint']);
    }
    public static function settings() { return (array) get_option('bon_commerce_settings', []); }
    public static function enabled($feature) { return !empty(self::settings()[$feature]); }
    public static function url($product_id = 0) {
        $args = ['bon_shop' => '1'];
        if ($product_id) $args['product'] = (int) $product_id;
        return add_query_arg($args, home_url('/'));
    }
    public static function public_product($product) {
        return $product && $product->get_status() === 'publish' && !$product->is_type('variation')
            && $product->is_visible() && get_post_field('post_password', $product->get_id()) === '';
    }
    public static function direct_purchase($product) {
        return self::public_product($product) && $product->is_type('simple')
            && $product->is_purchasable() && $product->is_in_stock()
            && !empty(self::settings()['direct_cart'])
            && apply_filters('bon_commerce_direct_purchase', true, $product);
    }
    public static function product_data($product) {
        return [
            'id' => $product->get_id(), 'name' => $product->get_name(),
            'description' => wp_trim_words(wp_strip_all_tags(strip_shortcodes($product->get_short_description())), 65, '…'),
            'price' => html_entity_decode(wp_strip_all_tags($product->get_price_html()), ENT_QUOTES, 'UTF-8'),
            'image' => esc_url_raw(wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail')),
            'url' => get_permalink($product->get_id()), 'type' => $product->get_type(),
            'available' => $product->is_in_stock(), 'direct' => (bool) self::direct_purchase($product),
            'on_sale' => $product->is_on_sale(), 'sold_individually' => $product->is_sold_individually(),
        ];
    }
    public function routes() {
        register_rest_route('balika/v1', '/catalog', [
            'methods' => 'GET', 'permission_callback' => [$this, 'catalog_permission'], 'callback' => [$this, 'catalog'],
            'args' => [
                'page' => ['default'=>1, 'sanitize_callback'=>'absint'],
                'category' => ['default'=>0, 'sanitize_callback'=>'absint'],
                'search' => ['default'=>'', 'sanitize_callback'=>'sanitize_text_field'],
            ],
        ]);
        register_rest_route('balika/v1', '/product/(?P<id>\d+)', [
            'methods'=>'GET', 'permission_callback'=>[$this, 'catalog_permission'], 'callback'=>[$this, 'detail'],
        ]);
    }
    public function catalog_permission() {
        return self::enabled('storefront') ? true : new WP_Error('disabled', 'فروشگاه مستقل فعال نیست.', ['status'=>404]);
    }
    public function catalog($request) {
        $page = max(1, min(500, (int) $request['page']));
        $search = (string) $request['search'];
        if (strlen($search) > 200) return new WP_Error('search', 'عبارت جست‌وجو طولانی است.', ['status'=>400]);
        $args = ['status'=>'publish', 'visibility'=>'catalog', 'limit'=>12, 'page'=>$page, 'paginate'=>true, 'orderby'=>'date', 'order'=>'DESC'];
        if ($search !== '') $args['s'] = $search;
        if (!empty($request['category'])) {
            $term = get_term((int) $request['category'], 'product_cat');
            if (!$term || is_wp_error($term)) return new WP_Error('category', 'دسته پیدا نشد.', ['status'=>400]);
            $args['category'] = [$term->slug];
        }
        $result = wc_get_products($args);
        $products = [];
        foreach ($result->products as $product) if (self::public_product($product)) $products[] = self::product_data($product);
        $categories = [];
        $terms = get_terms(['taxonomy'=>'product_cat', 'hide_empty'=>true, 'number'=>100]);
        if (!is_wp_error($terms)) foreach ($terms as $term) $categories[] = ['id'=>$term->term_id, 'name'=>$term->name];
        $response = new WP_REST_Response(['products'=>$products, 'categories'=>$categories, 'page'=>$page, 'pages'=>(int) $result->max_num_pages]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
    public function detail($request) {
        $product = wc_get_product(absint($request['id']));
        if (!self::public_product($product)) return new WP_Error('not_found', 'محصول در دسترس نیست.', ['status'=>404]);
        $response = new WP_REST_Response(self::product_data($product));
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
    public function render() {
        if (!isset($_GET['bon_shop']) || $_GET['bon_shop'] !== '1') return;
        if (!self::enabled('storefront')) wp_die('این فروشگاه فعال نیست.', '', ['response'=>404]);
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) wp_die('روش نامعتبر است.', '', ['response'=>405]);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Content-Type: text/html; charset=UTF-8');
        $name = self::settings()['name'] ?? get_bloginfo('name');
        $config = [
            'name'=>$name, 'catalog'=>rest_url('balika/v1/catalog'), 'product'=>rest_url('balika/v1/product/'),
            'cart'=>WC_AJAX::get_endpoint('bon_commerce_cart'), 'checkout'=>wc_get_checkout_url(),
            'account'=>wc_get_page_permalink('myaccount'), 'home'=>home_url('/'),
            'support'=>esc_url_raw(bon_get_option('support_url'), ['https']),
            'productId'=>absint($_GET['product'] ?? 0), 'restNonce'=>wp_create_nonce('wp_rest'),
        ];
        // Deliberately no wp_head/wp_footer or theme template: assets load only on this isolated route.
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#155e4c"><title><?php echo esc_html($name); ?></title><link rel="stylesheet" href="<?php echo esc_url(BON_PLUGIN_URL . 'assets/commerce/storefront.css?ver=' . BON_VERSION); ?>"></head><body><div id="bon-storefront"></div><noscript>برای خرید از این رابط جاوااسکریپت لازم است. <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">فروشگاه اصلی</a></noscript><script id="bon-commerce-config" type="application/json"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script><script defer src="<?php echo esc_url(BON_PLUGIN_URL . 'assets/commerce/storefront.js?ver=' . BON_VERSION); ?>"></script></body></html><?php
        exit;
    }
    public static function same_origin($origin, $home) {
        if ($origin === '') return true; // Same-origin requests need not send Origin; mutations still require a secret.
        $a = wp_parse_url($origin); $b = wp_parse_url($home);
        if (!$a || !$b || empty($a['host']) || empty($a['scheme'])) return false;
        return strtolower($a['host']) === strtolower($b['host'] ?? '') && strtolower($a['scheme']) === strtolower($b['scheme'] ?? '')
            && ($a['port'] ?? ($a['scheme'] === 'https' ? 443 : 80)) === ($b['port'] ?? (($b['scheme'] ?? '') === 'https' ? 443 : 80));
    }
    public static function valid_cart_token($stored, $provided) {
        return is_string($stored) && strlen($stored) === 40 && is_string($provided) && hash_equals($stored, $provided);
    }
    private function cart_data() {
        $cart = WC()->cart; $items = [];
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'];
            $items[] = [
                'key'=>$key, 'name'=>$product->get_name(), 'quantity'=>(int) $item['quantity'],
                'total'=>html_entity_decode(wp_strip_all_tags($cart->get_product_subtotal($product, $item['quantity'])), ENT_QUOTES, 'UTF-8'),
                'image'=>esc_url_raw(wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src()),
                'url'=>$product->is_visible() ? $product->get_permalink($item) : '',
            ];
        }
        return ['items'=>$items, 'count'=>$cart->get_cart_contents_count(), 'total'=>bon_money($cart->get_total('edit')), 'coupons'=>array_values($cart->get_applied_coupons())];
    }
    private function cart_error($message, $status = 400) {
        wp_send_json_error(['message'=>$message], $status);
    }
    public function cart_endpoint() {
        nocache_headers();
        if (!self::enabled('storefront')) { $this->cart_error('فروشگاه مستقل غیرفعال است.', 404); return; }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!self::same_origin($origin, home_url('/'))) { $this->cart_error('مبدأ درخواست مجاز نیست.', 403); return; }
        if (!WC()->session || !WC()->cart) { $this->cart_error('سبد ووکامرس آماده نیست.', 503); return; }
        $op = sanitize_key($_REQUEST['op'] ?? 'bootstrap');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($op !== 'bootstrap' && $method !== 'POST') { $this->cart_error('درخواست باید POST باشد.', 405); return; }
        if ($op === 'bootstrap' && $method !== 'GET') { $this->cart_error('روش نامعتبر است.', 405); return; }
        if ($op === 'bootstrap') {
            WC()->session->set_customer_session_cookie(true);
            $token = WC()->session->get('bon_cart_token');
            if (!is_string($token) || strlen($token) !== 40) {
                $token = wp_generate_password(40, false, false);
                WC()->session->set('bon_cart_token', $token);
            }
            WC()->cart->calculate_totals();
            WC()->session->save_data();
            wp_send_json_success(['token'=>$token, 'cart'=>$this->cart_data()]); return;
        }
        if (!self::valid_cart_token(WC()->session->get('bon_cart_token'), $_SERVER['HTTP_X_BALIKA_CART'] ?? '')) {
            $this->cart_error('نشست سبد منقضی شده؛ صفحه را دوباره باز کنید.', 403); return;
        }
        $cart = WC()->cart;
        try {
            if ($op === 'add') {
                $id = absint($_POST['product_id'] ?? 0);
                $product = wc_get_product($id);
                $quantity = self::quantity($_POST['quantity'] ?? 1);
                if ($quantity === false || $quantity < 1 || !self::direct_purchase($product)) throw new RuntimeException('برای این محصول از صفحه اصلی محصول خرید کنید.');
                if (!apply_filters('woocommerce_add_to_cart_validation', true, $id, $quantity)) throw new RuntimeException('اطلاعات بیشتری لازم است؛ صفحه اصلی محصول را باز کنید.');
                if (!$cart->add_to_cart($id, $quantity)) throw new RuntimeException('افزودن محصول ممکن نیست؛ موجودی یا شرایط خرید را بررسی کنید.');
            } elseif ($op === 'quantity' || $op === 'remove') {
                $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
                $item = $cart->get_cart_item($key);
                if (!$item) throw new RuntimeException('این ردیف در سبد شما نیست.');
                $quantity = $op === 'remove' ? 0 : self::quantity($_POST['quantity'] ?? '');
                if ($quantity === false) throw new RuntimeException('تعداد باید عدد صحیح بین صفر و ۹۹ باشد.');
                $product = $item['data'];
                if ($quantity > 0) {
                    if ($product->is_sold_individually() && $quantity > 1) throw new RuntimeException('این محصول فقط تکی قابل خرید است.');
                    $max = $product->get_max_purchase_quantity();
                    if ($max >= 0 && $quantity > $max) throw new RuntimeException('تعداد بیشتر از موجودی مجاز است.');
                    if ($product->managing_stock() && !$product->backorders_allowed()) {
                        $quantities = $cart->get_cart_item_quantities();
                        $total = ($quantities[$product->get_stock_managed_by_id()] ?? $item['quantity']) - $item['quantity'] + $quantity;
                        if (!$product->has_enough_stock($total)) throw new RuntimeException('موجودی برای مجموع این محصول در سبد کافی نیست.');
                    }
                    if (!apply_filters('woocommerce_update_cart_validation', true, $key, $item, $quantity)) throw new RuntimeException('تغییر تعداد پذیرفته نشد.');
                }
                if (!$cart->set_quantity($key, $quantity, true)) throw new RuntimeException('تغییر سبد ذخیره نشد.');
            } elseif ($op === 'coupon') {
                if (!wc_coupons_enabled()) throw new RuntimeException('کد تخفیف غیرفعال است.');
                $code = wc_format_coupon_code(wp_unslash($_POST['coupon'] ?? ''));
                if (!$code || strlen($code) > 100 || !$cart->apply_coupon($code)) throw new RuntimeException('کد تخفیف معتبر یا قابل استفاده نیست.');
            } elseif ($op === 'remove_coupon') {
                $cart->remove_coupon(wc_format_coupon_code(wp_unslash($_POST['coupon'] ?? '')));
            } else { throw new RuntimeException('عملیات نامعتبر است.'); }
            $cart->calculate_totals();
            WC()->session->save_data();
            $result = $this->cart_data();
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? wp_strip_all_tags($e->getMessage()) : 'پردازش سبد ناموفق بود؛ از صفحه اصلی فروشگاه ادامه دهید.';
            $this->cart_error($message); return;
        }
        wp_send_json_success(['cart'=>$result]);
    }
    public static function quantity($value) {
        if (!is_scalar($value) || !preg_match('/^\d{1,2}$/D', (string) $value)) return false;
        return (int) $value;
    }
    public static function bot_message($message) {
        if (!self::enabled('bot') || !in_array('bale', bon_get_enabled_platforms(), true)) return false;
        $text = trim($message['text'] ?? '');
        $chat = $message['chat']['id'] ?? '';
        if ((string) ($message['from']['id'] ?? '') !== (string) $chat) return false;
        $match = preg_match('/^\/(shop|products|search|product|cart)(?:@\S+)?(?:\s+(.*))?$/u', $text, $m);
        if (!$match && !preg_match('/^\/start\s+(shop|product_\d+)$/', $text, $start)) return false;
        if (!$match) $m = [0, strpos($start[1], 'product_') === 0 ? 'product' : 'shop', str_replace('product_', '', $start[1])];
        $rate = 'bon_catalog_' . md5((string) $chat);
        if (get_transient($rate)) return true;
        set_transient($rate, 1, 3);
        $api = new Bale_Order_Notifier_API('bale');
        if ($m[1] === 'cart') {
            $api->send_reply($chat, 'سبد و پرداخت در مرورگر همان دستگاه شماست؛ برای ادامه خرید باز کنید:', [[['text'=>'بازکردن فروشگاه', 'url'=>self::enabled('storefront') ? self::url() : wc_get_page_permalink('shop')]]],['scope'=>'catalog','products'=>[]]); return true;
        }
        $products = [];
        if ($m[1] === 'product') {
            $product = wc_get_product(absint($m[2] ?? 0));
            if (self::public_product($product)) $products[] = $product;
        } else {
            $args = ['status'=>'publish', 'visibility'=>'catalog', 'limit'=>6, 'orderby'=>'date', 'order'=>'DESC'];
            if ($m[1] === 'search') $args['s'] = sanitize_text_field(substr($m[2] ?? '', 0, 150));
            foreach (wc_get_products($args) as $product) if (self::public_product($product)) $products[] = $product;
        }
        $body = $products ? "فروشگاه بله 🛍\nانتخاب محصول اینجا؛ تکمیل خرید در پرداخت امن سایت.\n\n" : 'محصولی پیدا نشد.';
        $buttons = [];
        foreach ($products as $product) {
            $data = self::product_data($product);
            $body .= $data['name'] . ' — ' . $data['price'] . ($data['available'] ? '' : ' (ناموجود)') . "\n";
            $buttons[] = [['text'=>'مشاهده و خرید ' . wp_html_excerpt($data['name'], 32, '…'), 'url'=>self::enabled('storefront') ? self::url($data['id']) : $data['url']]];
        }
        $body .= "\n/shop — محصولات جدید\n/search عبارت — جست‌وجو\n/orders — پیگیری سفارش\n/support — پشتیبانی";
        $api->send_reply($chat,$body,$buttons,['scope'=>'catalog','products'=>array_map(function($product){return (int)$product->get_id();},$products)]);
        return true;
    }
}
