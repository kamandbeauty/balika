<?php
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function get_option($key, $default = []) { return $GLOBALS['options'][$key] ?? $default; }
function wp_timezone(){return new DateTimeZone('Asia/Tehran');}
function get_woocommerce_currency(){return 'IRR';}
function wp_strip_all_tags($s){return strip_tags($s);}
function wc_price($amount,$args){return '<span>'.$amount.' '.$args['currency'].'</span>';}
function wc_get_order_status_name($s){return $s;}
class WP_Error { public $message; function __construct($c,$m){$this->message=$m;} }
function is_wp_error($o){return $o instanceof WP_Error;}
require __DIR__.'/../balika/includes/class-bon-api.php';
require __DIR__.'/../balika/includes/class-bon-form-hooks.php';
require __DIR__.'/../balika/includes/class-bon-reports.php';
require __DIR__.'/../balika/includes/class-bon-customer-hooks.php';
require __DIR__.'/../balika/includes/class-bon-operations.php';
function sanitize_textarea_field($v){return trim(strip_tags($v));}
function sanitize_key($v){return preg_replace('/[^a-z0-9_]/','',$v);}
function wc_get_order_statuses(){return ['wc-processing'=>'Processing', 'wc-completed'=>'Completed'];}
$n=0;$options=[];
function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";$n++;}
check(bon_money(1000)==='1000 IRR','currency follows actual shop currency, not cosmetic label');
check(bon_money(1000,'USD')==='1000 USD','order currency overrides shop currency without fake conversion');
check(Bale_Order_Notifier_Form_Hooks::allowed('cf7:1','email')===true,'normal form fields remain enabled before opt-in restrictions');
check(!Bale_Order_Notifier_Form_Hooks::allowed('cf7:1','password'),'password field never exported');
$options['bon_settings']=['form_rules_enabled'=>true, 'form_rules'=>"cf7:1=name,email\nwpforms:2=1,3\nelementor:Contact=name"];
check(Bale_Order_Notifier_Form_Hooks::allowed('cf7:1','email'),'allowlisted field accepted');
check(!Bale_Order_Notifier_Form_Hooks::allowed('cf7:1','address'),'unlisted field rejected');
check(!Bale_Order_Notifier_Form_Hooks::allowed('cf7:9','email'),'unlisted form rejected');
check(Bale_Order_Notifier_Form_Hooks::allowed('wpforms:2',3),'numeric field IDs supported');
check(!Bale_Order_Notifier_Form_Hooks::allowed('wpforms:2',1,'password'),'sensitive field type blocked even if ID allowlisted');
check(Bale_Order_Notifier_Form_Hooks::allowed('elementor:Contact','name'),'Elementor named form supported');
check(is_wp_error(Bale_Order_Notifier_Reports::range_bounds('2026-02-30','2026-03-01')),'invalid calendar date rejected');
check(is_wp_error(Bale_Order_Notifier_Reports::range_bounds('2026-10-28','2026-10-01')),'reversed range rejected');
check(is_wp_error(Bale_Order_Notifier_Reports::range_bounds('2020-01-01','2026-10-28')),'unbounded expensive report rejected');
$bounds=Bale_Order_Notifier_Reports::range_bounds('2026-10-28','2026-10-28');
check($bounds[1]-$bounds[0]===86399,'same-day report includes end of day');
check((new DateTimeImmutable('@'.$bounds[0]))->setTimezone(wp_timezone())->format('H:i')==='00:00','range uses local midnight');
check(Bale_Order_Notifier_Reports::csv_cell('=SUM(A1)')==="'=SUM(A1)",'CSV formula injection escaped');
check(Bale_Order_Notifier_Reports::csv_cell("\t+malicious")==="'\t+malicious",'CSV leading-whitespace formula escaped');
class FeatureOrder {
    public $pay=true;
    function get_items(){return [];}
    function get_meta($key){return '';}
    function get_order_number(){return '100';}
    function get_billing_first_name(){return 'Test';}
    function get_billing_last_name(){return 'Customer';}
    function get_total(){return 100;}
    function get_currency(){return 'IRR';}
    function get_status(){return 'pending';}
    function needs_payment(){return $this->pay;}
    function get_checkout_payment_url(){return 'https://shop.test/pay?key=private';}
}
$hooks=Bale_Order_Notifier_Customer_Hooks::instance();$order=new FeatureOrder();
$text=$hooks->build_customer_message('template_payment_reminder',$order);
check(strpos($text,$order->get_checkout_payment_url())!==false,'payment reminder includes order payment link');
$options['bon_settings']['template_payment_reminder']='Custom reminder';
check(strpos($hooks->build_customer_message('template_payment_reminder',$order),$order->get_checkout_payment_url())!==false,'legacy custom template also gets payment link');
$order->pay=false;
check(strpos($hooks->build_customer_message('template_payment_reminder',$order),'https://')===false,'paid order does not expose active payment prompt');
check($hooks->build_customer_message('template_customer_tracking',$order,'Preview #{order_id}')==='Preview #100','template preview substitutes data without saving template');
check(!isset($options['bon_settings']['template_customer_tracking']),'preview never persists configuration');
$options['bon_settings']['bale_bot_token']='SECRET';
$options['bon_settings']['bale_chat_ids']=['123'];
check(!isset(Bale_Order_Notifier_Operations::portable_settings()['bale_bot_token']) && !isset(Bale_Order_Notifier_Operations::portable_settings()['bale_chat_ids']), 'settings export excludes connection credentials and IDs');
check(is_wp_error(Bale_Order_Notifier_Operations::validate_import('{bad')), 'malformed JSON import rejected');
check(is_wp_error(Bale_Order_Notifier_Operations::validate_import(json_encode(['format'=>1,'settings'=>['bale_bot_token'=>'LEAK']]))), 'import refuses connection credentials');
check(is_wp_error(Bale_Order_Notifier_Operations::validate_import(json_encode(['format'=>1,'settings'=>['notify_new_order'=>'true']]))), 'import enforces actual boolean types');
$import=Bale_Order_Notifier_Operations::validate_import(json_encode(['format'=>1,'settings'=>['template_customer_tracking'=>'Hello {order_id}', 'notify_new_order'=>true]]));
check(!is_wp_error($import) && $import['notify_new_order']===true, 'valid portable settings accepted');
echo "$n checks passed\n";
