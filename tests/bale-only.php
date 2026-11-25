<?php
define('ABSPATH', __DIR__);
function get_option($key, $default = []) {
    return ['bale_enabled' => !empty($GLOBALS['bale_on']), 'bale_bot_token' => 'bale-token', 'telegram_enabled' => true, 'telegram_bot_token' => 'legacy-token'];
}
function wp_remote_post(...$args) { throw new RuntimeException('Unexpected external request'); }
$bale_on=true;
require __DIR__ . '/../balika/includes/class-bon-api.php';
require __DIR__ . '/../balika/includes/class-bon-queue.php';
require __DIR__ . '/../balika/includes/class-bon-customer-store.php';
$n = 0;
function check($ok, $name) { global $n; if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; $n++; }
check(bon_get_enabled_platforms() === ['bale'], 'legacy enabled Telegram option cannot enable sending');
$old = new Bale_Order_Notifier_API('telegram');
check($old->send_message('123', 'test') === false, 'old platform cannot send or be routed to Bale');
check($old->get_me() === false, 'old platform cannot call external API');
check($old->set_webhook('https://example.com') === false, 'old platform cannot register webhook');
check(Bale_Order_Notifier_Queue::enqueue('telegram', 'sendMessage', []) === false, 'queue rejects unsupported platform');
check(Bale_Order_Notifier_Customer_Store::link('09121234567', 'telegram', '123') === false, 'account store rejects unsupported connection');
check(array_keys(Bale_Order_Notifier_API::BASE_URLS) === ['bale'], 'only Bale API endpoint remains');
$bale_on=false;
$api=new Bale_Order_Notifier_API('bale');
check($api->send_message('123','private payload',[],false)===false,'master switch blocks direct send before network request');
echo "$n checks passed\n";
