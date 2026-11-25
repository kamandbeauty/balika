<?php
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
function add_action(...$args) {}
function bon_get_option($key,$default=''){return $GLOBALS['options']['bon_settings'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;}
function current_time($type) { return $type === 'mysql' ? '2026-10-28 12:00:00' : 1793188800; }
function wp_json_encode($value) { return json_encode($value); }
class Bale_Order_Notifier_Logger { static function log(...$args) {} }
class Bale_Order_Notifier_API {
    static $success = true;
    function __construct(...$args) {}
    function send_message(...$args) { return self::$success; }
}
class FakeDB {
    public $prefix = 'wp_';
    public $insert_ok = 1;
    public $lock = 1;
    public $released = 0;
    public $items = [];
    public $updates = [];
    function insert(...$args) { return $this->insert_ok; }
    function prepare($sql, ...$args) { return $sql; }
    function get_var($sql) { if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->released++; return 1; } return $this->lock; }
    function query($sql) { return 1; }
    function get_results($sql) { return $this->items; }
    function update($table, $data, $where) { $this->updates[] = $data; return 1; }
}
$options=['bon_settings'=>['bale_chat_ids'=>['1']]];
$wpdb = new FakeDB();
require __DIR__ . '/../balika/includes/class-bon-queue.php';
$count = 0;
function check($value, $name) { global $count; if (!$value) throw new RuntimeException($name); $count++; echo "PASS: $name\n"; }
check(Bale_Order_Notifier_Queue::enqueue('bale', 'sendMessage', []) === true, 'enqueue reports database success');
$wpdb->insert_ok = false;
check(Bale_Order_Notifier_Queue::enqueue('bale', 'sendMessage', []) === false, 'enqueue reports database failure');
$queue = Bale_Order_Notifier_Queue::instance();
$queue->process_queue();
check($wpdb->released === 1, 'empty queue releases lock');
$wpdb->items = [(object) ['message_type'=>'admin_order', 'id' => 1, 'platform' => 'bale', 'method' => 'sendMessage', 'payload' => '{"chat_id":"1","text":"hello"}', 'attempts' => 0]];
$wpdb->lock = 0;
$queue->process_queue();
check(count($wpdb->updates) === 0, 'concurrent worker cannot process locked queue');
$wpdb->lock = 1;
$queue->process_queue();
check(end($wpdb->updates)['status'] === 'sent', 'successful send marked sent');
Bale_Order_Notifier_API::$success = false;
$queue->process_queue();
check(end($wpdb->updates)['attempts'] === 1 && isset(end($wpdb->updates)['next_attempt']), 'temporary failure schedules retry');
$wpdb->items[0]->attempts = 4;
$queue->process_queue();
check(end($wpdb->updates)['status'] === 'dead-letter', 'fifth failure stops retrying');
Bale_Order_Notifier_Queue::retry_failed();
check(end($wpdb->updates)['status'] === 'pending' && end($wpdb->updates)['attempts'] === 0, 'manual retry resets failed messages');
$chunks = Bale_Order_Notifier_Queue::text_chunks(str_repeat('س', 6100));
check(count($chunks) === 3 && implode('', $chunks) === str_repeat('س', 6100), 'long Persian messages split without losing Unicode content');
Bale_Order_Notifier_API::$success = true;
$wpdb->items[0]->payload = json_encode(['chat_id' => '1', 'text' => str_repeat('a', 3100)]);
$queue->process_queue();
$last = end($wpdb->updates);
check(isset($last['payload']) && json_decode($last['payload'], true)['_bon_chunk'] === 1 && $last['status']==='pending', 'successful first chunk records progress instead of marking whole message sent');
$wpdb->items[0]->payload = $last['payload'];
$queue->process_queue();
check(end($wpdb->updates)['status'] === 'sent', 'message marked sent only after final chunk');
class Bale_Order_Notifier_Channels { static $allowed=false; static function can_deliver($id){return self::$allowed;} }
$wpdb->items[0]->message_type='channel_20';
$wpdb->items[0]->payload=json_encode(['chat_id'=>'1','text'=>'Scheduled']);
$queue->process_queue();
check(end($wpdb->updates)['status']==='paused','worker pauses channel outbox if destination or module was disabled after enqueue');
Bale_Order_Notifier_Channels::$allowed=true;
$queue->process_queue();
check(end($wpdb->updates)['status']==='sent','worker permits revalidated active channel publication');
echo "$count checks passed\n";
