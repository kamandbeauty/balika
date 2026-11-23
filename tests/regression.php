<?php
// Standalone unit regression tests; no WordPress installation or network required.
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function plugin_dir_path($p) { return dirname($p) . '/'; }
function plugin_dir_url($p) { return ''; }
function plugin_basename($p) { return basename($p); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_timezone() { return new DateTimeZone('Asia/Tehran'); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function wp_generate_password($length, ...$args) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function wc_get_order($id) { $GLOBALS['order_lookups']++; return false; }
class Bale_Order_Notifier_API {
    public static $messages = [];
    function __construct(...$args) {}
    function send_message(...$args) { self::$messages[] = $args; return true; }
    function answer_callback_query(...$args) { self::$messages[] = $args; return true; }
}
class Bale_Order_Notifier_Logger { static function log(...$args) {} }
function bon_get_option($key, $default = '') { return $GLOBALS['options']['bon_settings'][$key] ?? $default; }
require __DIR__ . '/../balika/balika.php';
require __DIR__ . '/../balika/includes/class-bon-customer-store.php';
require __DIR__ . '/../balika/includes/class-bon-customer-hooks.php';
require __DIR__ . '/../balika/includes/class-bon-webhook.php';
$GLOBALS['order_lookups'] = 0;
$count = 0;
function check($condition, $name) {
    global $count;
    if (!$condition) throw new RuntimeException($name);
    echo 'PASS: ' . $name . "\n"; $count++;
}
foreach (['09121234567','+989121234567','00989121234567','9121234567','۰۹۱۲۱۲۳۴۵۶۷','٠٩١٢١٢٣٤٥٦٧'] as $phone) {
    check(Bale_Order_Notifier_Customer_Store::normalize_phone($phone) === '+989121234567', 'normalize ' . $phone);
}
foreach (['123','', '+46123456789', '091212345678'] as $phone) {
    check(!Bale_Order_Notifier_Customer_Store::looks_like_phone($phone), 'reject malformed phone ' . $phone);
}
$GLOBALS['options']['bon_settings'] = ['report_time' => 'bad'];
$next = bon_next_report_timestamp();
check($next > time(), 'next daily report is in the future');
check(wp_timezone()->getName() === 'Asia/Tehran' && (new DateTimeImmutable('@' . $next))->setTimezone(wp_timezone())->format('H:i') === '23:55', 'report uses site timezone and validates clock');
$weekly = (new DateTimeImmutable('@' . bon_next_report_timestamp(true)))->setTimezone(wp_timezone());
check($weekly->format('w') === '6', 'weekly report on Saturday');
$GLOBALS['options']['start_of_week'] = 6;
check((new DateTimeImmutable('@' . bon_week_start_timestamp()))->setTimezone(wp_timezone())->format('w H:i') === '6 00:00', 'week follows WordPress setting');
$GLOBALS['options']['bon_settings'] = ['bale_bot_username' => 'test', 'bale_chat_ids' => ['-123'], 'bale_admin_user_ids' => ['42']];
$hooks = Bale_Order_Notifier_Customer_Hooks::instance();
$build = new ReflectionMethod($hooks, 'build_connect_link'); if (PHP_VERSION_ID < 80100) $build->setAccessible(true);
$link = $build->invoke($hooks, 'bale', 123);
check((bool) preg_match('/start=connect_([A-Za-z0-9]{40})$/', $link, $match), 'connection token fits messenger payload');
check(get_transient('bon_connect_' . hash('sha256', $match[1])) === 123, 'connection token maps to correct order');
check($link !== $build->invoke($hooks, 'bale', 123), 'connection tokens are unique');
$webhook = Bale_Order_Notifier_Webhook::instance();
$handle = new ReflectionMethod($webhook, 'handle_incoming_message'); if (PHP_VERSION_ID < 80100) $handle->setAccessible(true);
$handle->invoke($webhook, 'bale', ['chat' => ['id' => 7], 'from' => ['id' => 7], 'text' => '/start order123']);
check($GLOBALS['order_lookups'] === 0, 'legacy predictable link cannot access orders');
$handle->invoke($webhook, 'bale', ['chat' => ['id' => 7], 'from' => ['id' => 7], 'text' => '/start connect_' . str_repeat('x', 40)]);
check($GLOBALS['order_lookups'] === 0, 'expired or invalid token cannot access orders');
$callback = new ReflectionMethod($webhook, 'handle_callback_query'); if (PHP_VERSION_ID < 80100) $callback->setAccessible(true);
$callback->invoke($webhook, 'bale', ['message' => ['chat' => ['id' => -123]], 'from' => ['id' => 7], 'data' => 'bon_status:completed:123', 'id' => 'cb']);
check($GLOBALS['order_lookups'] === 0, 'group membership alone cannot change orders');
function wp_using_ext_object_cache() { return false; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
class TokenDB {
    public $options = 'wp_options';
    function delete($table, $where) {
        $key = substr($where['option_name'], strlen('_transient_'));
        if (!isset($GLOBALS['transients'][$key])) return 0;
        unset($GLOBALS['transients'][$key]); return 1;
    }
}
$wpdb = new TokenDB();
$message = ['chat' => ['id' => 7], 'from' => ['id' => 7], 'text' => '/start connect_' . $match[1]];
$handle->invoke($webhook, 'bale', $message);
check($GLOBALS['order_lookups'] === 1, 'valid token resolves an order');
$handle->invoke($webhook, 'bale', $message);
check($GLOBALS['order_lookups'] === 1, 'consumed token cannot be reused');
echo "{$count} checks passed\n";
