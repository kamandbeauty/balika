<?php
/** Audit reproductions for 2.4.0, NOT safety regression tests.
 * Most assertions intentionally confirm existing defects; FIX VERIFIED assertions now
 * check A05/A06/A10 corrections. After further fixes, replace
 * them with assertions of the desired behavior. No live DB, WP or Bale calls.
 * Run separately: PHP_BIN tests/audit-known-issues.php
 */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400); define('HOUR_IN_SECONDS', 3600); define('ARRAY_A', 'ARRAY_A');
function add_action(...$a) {} function add_filter(...$a) {}
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, ...$a) { $GLOBALS['options'][$k] = $v; }
function current_time($t) { return $t === 'mysql' ? '2026-10-29 12:00:00' : time(); }
function wp_json_encode($v) { return json_encode($v); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function wp_generate_password($n, ...$a) { return substr(bin2hex(random_bytes($n)), 0, $n); }
function wp_using_ext_object_cache() { return false; }
function current_user_can(...$a) { return true; }
function wp_verify_nonce(...$a) { return true; }
function admin_url($path) { return 'https://shop.example/wp-admin/' . $path; }
function doing_action(...$a) { return false; }
function wc_price($v, $a = []) { return (string) $v; }
function get_woocommerce_currency() { return 'IRR'; }
function wp_strip_all_tags($v) { return strip_tags($v); }
function wc_get_order_status_name($s) { return $s; }
function wc_get_order_statuses() { return ['wc-processing'=>'Processing', 'wc-completed'=>'Completed']; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function wc_get_orders($args) {
    $GLOBALS['order_queries'][] = $args;
    return array_values(array_filter($GLOBALS['orders'], function($o) use ($args) {
        return (!isset($args['billing_phone']) || $o->get_billing_phone() === $args['billing_phone'])
            && (!isset($args['status']) || in_array($o->status, $args['status'], true));
    }));
}
function wp_remote_post($url, $args) { $GLOBALS['http'][] = ['url'=>$url, 'body'=>$args['body']]; return ['ok'=>true]; }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return json_encode(['ok'=>true, 'result'=>['message_id'=>1]]); }
function is_wp_error($v) { return false; }
class Bale_Order_Notifier_Logger { static function log(...$a) {} }
class AuditOrder {
    public $id, $status = 'pending', $items = [], $meta = [], $status_ok = true;
    function __construct($id) { $this->id = $id; }
    function get_id() { return $this->id; }
    function get_order_number() { return (string) $this->id; }
    function get_billing_phone() { return '09121234567'; }
    function get_billing_first_name() { return 'Test'; }
    function get_billing_last_name() { return 'Customer'; }
    function get_billing_address_1() { return 'Test'; }
    function get_formatted_shipping_address() { return ''; }
    function get_payment_method_title() { return 'Test'; }
    function get_items() { return $this->items; }
    function get_status() { return $this->status; }
    function get_total() { return 100; }
    function get_currency() { return 'IRR'; }
    function get_date_created() { return new DateTimeImmutable('2026-10-28'); }
    function needs_payment() { return $this->status === 'pending'; }
    function get_checkout_payment_url() { return 'https://shop.example/pay/' . $this->id; }
    function get_meta($k) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function save_meta_data() {} function save() {}
    function update_status($s, ...$a) { if ($this->status_ok) $this->status = $s; return $this->status_ok; }
}
class AuditDB {
    public $prefix = 'wp_', $options = 'wp_options', $links = [], $queue = [], $write_ok = true;
    function prepare($sql, ...$args) { return ['sql'=>$sql, 'args'=>$args]; }
    function get_var($q) {
        if (strpos($q['sql'], 'GET_LOCK') !== false || strpos($q['sql'], 'RELEASE_LOCK') !== false) return 1;
        if (strpos($q['sql'], 'SELECT id FROM wp_bon_customer_links') !== false) return isset($this->links[$q['args'][0]]) ? 1 : null;
        return null;
    }
    function get_results($q, $output = null) {
        if (strpos($q['sql'], 'bon_queue') !== false) return array_values($this->queue);
        return isset($this->links[$q['args'][0]]) ? [$this->links[$q['args'][0]]] : [];
    }
    function get_col($q) {
        return array_keys(array_filter($this->links, function($l) use ($q) { return (string) $l['chat_id'] === (string) $q['args'][0]; }));
    }
    function insert($table, $data) {
        if (!$this->write_ok) return false;
        if ($table === 'wp_bon_customer_links') $this->links[$data['phone']] = $data;
        else { $data['id'] = count($this->queue) + 1; $this->queue[$data['id']] = (object) $data; }
        return 1;
    }
    function update($table, $data, $where) {
        if (!$this->write_ok) return false;
        if ($table === 'wp_bon_customer_links') $this->links['+989121234567'] = array_merge($this->links['+989121234567'], $data);
        elseif (isset($where['id'], $this->queue[$where['id']])) foreach ($data as $k=>$v) $this->queue[$where['id']]->$k = $v;
        return 1;
    }
    function delete($table, $where) {
        if ($table === $this->options) {
            $key = substr($where['option_name'], strlen('_transient_'));
            $exists = isset($GLOBALS['transients'][$key]); unset($GLOBALS['transients'][$key]); return $exists ? 1 : 0;
        }
        return 1;
    }
    function query($q) { return 1; }
}
$wpdb = new AuditDB();
foreach (['api', 'queue', 'dispatcher', 'customer-store', 'customer-hooks', 'webhook', 'order-hooks'] as $file) require __DIR__ . '/../balika/includes/class-bon-' . $file . '.php';
function invoke_private($object, $method, ...$args) { $m = new ReflectionMethod($object, $method); if (PHP_VERSION_ID < 80100) $m->setAccessible(true); return $m->invoke($object, ...$args); }
$count = 0;
function verified($v, $label) { global $count; if (!$v) throw new RuntimeException($label); echo "FIX VERIFIED: $label\n"; $count++; }
function reproduced($v, $label) { global $count; if (!$v) throw new RuntimeException('NOT REPRODUCED: ' . $label); echo "REPRODUCED: $label\n"; $count++; }
$options['bon_settings'] = ['bale_bot_username'=>'fixture', 'bale_bot_token'=>'not-a-real-token', 'bale_enabled'=>true, 'bale_chat_ids'=>['42'], 'bale_admin_user_ids'=>['42']];
$orders = [101=>new AuditOrder(101), 202=>new AuditOrder(202)];
$orders[101]->meta['_bon_tracking_code'] = 'OLD-PRIVATE-TRACKING';
Bale_Order_Notifier_Customer_Store::link('09121234567', 'bale', '7');
$hooks = Bale_Order_Notifier_Customer_Hooks::instance(); $webhook = Bale_Order_Notifier_Webhook::instance();
$link = invoke_private($hooks, 'build_connect_link', 'bale', 202);
preg_match('/connect_([A-Za-z0-9]{40})/', $link, $match);
invoke_private($webhook, 'handle_incoming_message', 'bale', ['chat'=>['id'=>8], 'from'=>['id'=>8], 'text'=>'/start connect_' . $match[1]]);
reproduced(Bale_Order_Notifier_Customer_Store::owns_phone('09121234567', 'bale', 8), 'A01: own new-order token replaces phone-wide prior customer binding');
$http = [];
invoke_private($webhook, 'handle_incoming_message', 'bale', ['chat'=>['id'=>8], 'from'=>['id'=>8], 'text'=>'/orders']);
reproduced(strpos(end($http)['body']['text'], 'OLD-PRIVATE-TRACKING') !== false, 'A01: new binding exposes a different order with the same unverified phone');
$options['bon_settings']['bale_enabled'] = false; $http = [];
Bale_Order_Notifier_Queue::instance()->process_queue();
reproduced(count($http) > 0, 'A02: ordinary queued customer message sends with global Bale switch off');
$wpdb->queue = []; $options['bon_settings']['customer_notifications_enabled'] = false;
$options['bon_settings']['payment_reminder_enabled'] = true;
$hooks->send_payment_reminders();
reproduced(count($wpdb->queue) === 2, 'A02: payment reminders enqueue with both notification master switches off');
$options['bon_settings']['bale_enabled'] = true;
foreach ($orders as $order) $order->status = 'completed';
$http = []; $order_queries = []; Bale_Order_Notifier_Queue::instance()->process_queue();
reproduced(count($http) === 2 && !$order_queries, 'A04: reminders send after payment without current-order revalidation');
$wpdb->queue = []; $options['bon_settings']['customer_notifications_enabled'] = true;
$orders[303] = new AuditOrder(303); $orders[303]->meta['_bon_tracking_code'] = 'KEEP-OLD';
$_POST = ['bon_tracking_nonce'=>'fixture', 'bon_tracking_code'=>''];
$hooks->save_tracking_meta_box(303);
reproduced($orders[303]->get_meta('_bon_tracking_code') === 'KEEP-OLD', 'A07: clearing tracking input silently retains the old code');
// Date formatting isn't relevant here; use a fixture with no creation date.
class EarlyAuditOrder extends AuditOrder { function get_date_created() { return null; } }
$orders[404] = new EarlyAuditOrder(404);
$admin = Bale_Order_Notifier_Order_Hooks::instance();
$admin->on_new_order(404);
$orders[404]->items = [new class { function get_name() { return 'FINAL-LINE-ITEM'; } function get_quantity() { return 1; } }];
$admin->on_new_order(404);
reproduced(count($wpdb->queue) === 1 && strpos(reset($wpdb->queue)->payload, 'FINAL-LINE-ITEM') === false, 'A03: early new-order hook suppresses the later complete checkout notification');
$orders[303]->status_ok = false; $http = [];
invoke_private($webhook, 'handle_callback_query', 'bale', ['from'=>['id'=>42], 'message'=>['chat'=>['id'=>42], 'message_id'=>9], 'id'=>'fixture-callback', 'data'=>'bon_status:completed:303']);
verified($orders[303]->status === 'pending' && count($http) === 1 && strpos($http[0]['body']['text'], 'ناموفق') !== false, 'A05: failed status change reports failure and does not edit message');
$orders[303]->status_ok = true; $options['bon_settings']['bale_enabled'] = false;
invoke_private($webhook, 'handle_callback_query', 'bale', ['from'=>['id'=>42], 'message'=>['chat'=>['id'=>42], 'message_id'=>9], 'id'=>'disabled-callback', 'data'=>'bon_status:processing:303']);
reproduced($orders[303]->status === 'processing', 'A02: administrative webhook button still changes order while Bale is disabled');
$wpdb->queue = []; unset($options['bon_settings']['enabled_statuses']);
$options['bon_settings']['bale_enabled'] = true;
$admin->on_status_changed(404, 'pending', 'completed', $orders[404]);
verified(count($wpdb->queue) === 1, 'A10: runtime status defaults match checked UI defaults');
$wpdb->write_ok = false;
verified(Bale_Order_Notifier_Customer_Store::link('09121234567', 'bale', '9') === false && !Bale_Order_Notifier_Customer_Store::owns_phone('09121234567', 'bale', '9'), 'A06: binding reports failure on database write failure');
echo "$count audit observations checked (FIX VERIFIED lines are corrected cases).\n";
