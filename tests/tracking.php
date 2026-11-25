<?php
// Operational regression tests without WordPress, network or real customer data.
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_enqueue_script(...$args) {}
define('BON_PLUGIN_URL','https://example.test/plugin/');
define('BON_VERSION','test');
function wp_json_encode($v) { return json_encode($v); }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int)$v); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function esc_url_raw($s, $protocols = []) { return preg_match('~^https?://~', $s) ? $s : ''; }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL); }
function current_user_can(...$args) { return $GLOBALS['can']; }
function get_current_user_id() { return 10; }
function get_option($key, $default = []) { return $GLOBALS['options'][$key] ?? $default; }
function bon_get_option($key, $default = '') { return $GLOBALS['options']['bon_settings'][$key] ?? $default; }
function bon_get_enabled_platforms() { return $GLOBALS['enabled'] ? ['bale'] : []; }
function wc_get_order_statuses() { return ['wc-processing' => 'Processing', 'wc-completed' => 'Completed', 'wc-custom' => 'Custom']; }
class WP_Error { private $code, $message; function __construct($code, $message) { $this->code=$code; $this->message=$message; } function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wc_get_order($id) { return isset($GLOBALS['orders'][$id]) ? clone $GLOBALS['orders'][$id] : false; }
class TestOrder {
    public $id, $status = 'processing', $meta = [], $notes = [], $saves = 0;
    function __construct($id) { $this->id=$id; }
    function get_id(){return $this->id;}
    function get_order_number(){return 'WEB-' . $this->id;}
    function get_edit_order_url(){return 'https://example.test/order/' . $this->id;}
    function get_formatted_billing_full_name(){return 'Sample Customer';}
    function get_type(){return 'shop_order';}
    function get_status(){return $this->status;}
    function get_meta($key){return $this->meta[$key] ?? '';}
    function update_meta_data($key, $value){$this->meta[$key]=$value;}
    function get_billing_phone(){return '09121234567';}
    function save(){ $this->saves++; $GLOBALS['orders'][$this->id] = clone $this; return $this->id; }
    function save_meta_data(){ $GLOBALS['orders'][$this->id] = clone $this; }
    function update_status($status, $note){$this->status=$status; $this->save(); return true;}
    function add_order_note($note){$this->notes[]=$note; $this->save();}
}
class FakeDB {
    public $prefix='test_', $locked=false, $released=0;
    function prepare($query,...$args){return $query;}
    function get_var($query){if(strpos($query,'RELEASE_LOCK')!==false){$this->released++;return 1;}return $this->locked ? 0 : 1;}
}
class Bale_Order_Notifier_Logger { static function log(...$args){} }
class Bale_Order_Notifier_Queue {
    static $messages=[], $fail=false;
    static function enqueue($platform,$method,$params,$order_id=0,$type=''){
        if(self::$fail)return false;
        self::$messages[]=compact('platform','method','params','order_id','type');return true;
    }
}
class Bale_Order_Notifier_Customer_Store { static $linked=true; static function is_linked($phone){return self::$linked;} static function get_links_for_order($phone){return self::$linked ? [['chat_id'=>'17']] : []; } }
class Bale_Order_Notifier_Customer_Hooks { static function instance(){return new self;} function build_customer_message($key,$order){return 'Tracking '.$order->get_meta('_bon_tracking_code');} }
require __DIR__.'/../balika/includes/class-bon-tracking.php';
require __DIR__.'/../balika/includes/class-bon-operations.php';
$can=true; $enabled=true; $options=[]; $wpdb=new FakeDB(); $orders=[]; $n=0;
function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";$n++;}
function reset_order($id=1){$GLOBALS['orders'][$id]=new TestOrder($id);return $GLOBALS['orders'][$id];}
function save_code($code,$replace=false,$target='completed',$expected='') { return Bale_Order_Notifier_Tracking::save(1,['code'=>$code,'courier'=>'Post','url'=>''], $target, ['processing','completed'], $expected, $replace); }
reset_order();
$can=false;check(save_code('123')->get_error_code()==='forbidden','unauthorised user cannot update tracking');$can=true;
check(save_code('')->get_error_code()==='invalid_code' && $orders[1]->saves===0,'empty code cannot change status or data');
check(save_code('<script>')->get_error_code()==='invalid_code','invalid tracking characters rejected');
check(save_code('123',false,'bad')->get_error_code()==='invalid_status','unknown destination rejected');
check(save_code('123',false,'completed','stale')->get_error_code()==='stale','stale browser snapshot rejected');
$orders[1]->status='custom';check(save_code('123')->get_error_code()==='status_changed','order no longer in source statuses is skipped');
reset_order();$wpdb->locked=true;check(save_code('123')->get_error_code()==='busy','concurrent edit cannot proceed');$wpdb->locked=false;
$result=save_code('۰۰۱۲۳۴۵');
check($result['state']==='queued','valid tracking creates queue entry');
check($orders[1]->get_meta('_bon_tracking_code')==='0012345','leading zeros preserved and Persian digits converted');
check($orders[1]->status==='completed','tracking transitions order to selected destination');
check(count(Bale_Order_Notifier_Queue::$messages)===1 && Bale_Order_Notifier_Queue::$messages[0]['order_id']===1,'queue records order association');
check(save_code('0012345')['state']==='unchanged' && count(Bale_Order_Notifier_Queue::$messages)===1,'repeated submission does not enqueue duplicate');
check(save_code('999')->get_error_code()==='exists','existing code requires explicit replacement consent');
check(save_code('999',true)['state']==='queued','explicit replacement accepted');
reset_order();Bale_Order_Notifier_Customer_Store::$linked=false;
check(save_code('123')['state']==='unlinked' && $orders[1]->status==='completed','unlinked customer does not falsely report sending; tracking still saved');
Bale_Order_Notifier_Customer_Store::$linked=true;
check(save_code('123')['state']==='queued','unchanged tracking can notify after customer connects');
reset_order();Bale_Order_Notifier_Queue::$fail=true;
check(save_code('123')['state']==='queue_failed','database failure surfaced independently from saved tracking');
Bale_Order_Notifier_Queue::$fail=false;check(save_code('123')['state']==='queued','failed insertion can be retried without changing code');
reset_order();$enabled=false;check(save_code('123')['state']==='disabled','disabled Bale reports no delivery');$enabled=true;
reset_order();check(save_code('ABC-123',false,'')['state']==='queued' && $orders[1]->status==='processing','optional destination preserves status');
reset_order();$x=Bale_Order_Notifier_Tracking::save(1,['code'=>'123','url'=>'javascript:alert(1)']);check($x->get_error_code()==='invalid_url' && !$orders[1]->saves,'unsafe tracking URL makes no changes');
check($wpdb->released>0,'tracking lock released after operations');

// Exercise actual POST controller and PRG without exiting the test process.
class Redirected extends Exception {}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;$GLOBALS['results']=$value;}
function admin_url($path){return 'https://example.test/'.$path;}
function wp_safe_redirect($url){throw new Redirected($url);}
function check_admin_referer($key){if(!$GLOBALS['nonce_ok'])throw new RuntimeException('nonce');}
function wp_die(...$args){throw new RuntimeException('forbidden');}
$nonce_ok=false;$ops=Bale_Order_Notifier_Operations::instance();
try{$ops->bulk_submit();check(false,'CSRF');}catch(RuntimeException $e){check($e->getMessage()==='nonce','bulk controller rejects invalid nonce');}
$nonce_ok=true;$config=Bale_Order_Notifier_Operations::config();
$_POST=['config_hash'=>'wrong'];
try{$ops->bulk_submit();}catch(Redirected $e){check(strpos($results[0],'تنظیمات')!==false,'changed settings invalidate open bulk form');}
reset_order(1);reset_order(2);
$_POST=['config_hash'=>hash('sha256',wp_json_encode($config)), 'rows'=>[
 1=>['input_hash'=>Bale_Order_Notifier_Operations::tracking_input_hash(['code'=>'','courier'=>'Post']),'snapshot'=>Bale_Order_Notifier_Tracking::fingerprint($orders[1]),'code'=>'123','courier'=>'Post'],
 2=>['input_hash'=>Bale_Order_Notifier_Operations::tracking_input_hash(['code'=>'','courier'=>'Post']),'snapshot'=>Bale_Order_Notifier_Tracking::fingerprint($orders[2]),'code'=>'','courier'=>'Post']
]];
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->status==='completed' && $orders[2]->status==='processing' && count($results)===1,'bulk saves entered code without selection and ignores blank row');}
reset_order(1);unset($_POST['rows'][1]['selected']);unset($_POST['rows'][2]);
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->status==='completed','entered row saves without selection checkbox');}
reset_order(1);$orders[1]->meta['_bon_tracking_code']='123';$orders[1]->meta['_bon_courier_name']='Post';
$_POST['rows']=[1=>['code'=>'123','courier'=>'Post','snapshot'=>Bale_Order_Notifier_Tracking::fingerprint($orders[1]),'input_hash'=>Bale_Order_Notifier_Operations::tracking_input_hash(['code'=>'123','courier'=>'Post'])]];
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->saves===0 && $orders[1]->status==='processing','untouched prefilled code does not save or change order status');}
$_POST['rows'][1]['code']='456';
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->get_meta('_bon_tracking_code')==='123','changed existing code still requires replacement confirmation');}
$_POST['rows'][1]['replace']=1;
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->get_meta('_bon_tracking_code')==='456','confirmed replacement saves without row selection');}
reset_order(1);$_POST['rows'][1]['code']='789';unset($_POST['rows'][1]['input_hash']);
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->saves===0,'old form without input snapshot cannot accidentally change prefilled orders');}
$_POST['rows']=array_fill(0,21,[]);
try{$ops->bulk_submit();}catch(Redirected $e){check(strpos($results[0],'۲۰')!==false,'oversized bulk request rejected');}
// Render the actual bulk screen with controlled order data; no browser or WP installation required.
function get_transient($key){return strpos($key,'bon_tracking_failed_')===0 ? ($GLOBALS['transients'][$key]??false) : false;}
function esc_html($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return $s;}
function wp_kses_post($s){return $s;}
function wc_get_order_status_name($s){return $s;}
function wc_get_orders($args){return (object)['orders'=>array_values($GLOBALS['orders']), 'total'=>count($GLOBALS['orders']), 'max_num_pages'=>1];}
function checked($a,$b=true,$echo=true){$s=$a==$b?'checked':'';if($echo)echo $s;return $s;}
function selected($a,$b=true,$echo=true){return checked($a,$b,$echo)==='checked'?'selected':'';}
function wp_nonce_field($action){echo '<input name="_wpnonce" value="mock">';}
function submit_button($text,...$args){echo '<button>'.esc_html($text).'</button>';}
function add_query_arg(...$args){return 'https://example.test/?paged=1';}
function paginate_links($args){return '';}
$_GET=[];
ob_start();$ops->tracking_page();$html=ob_get_clean();
check(strpos($html,'rows[1][code]')!==false && strpos($html,'rows[2][code]')!==false, 'bulk screen renders independent tracking inputs for orders');
check(strpos($html,'config_hash')!==false && strpos($html,'[snapshot]')!==false && strpos($html,'_wpnonce')!==false, 'bulk screen includes nonce and concurrency snapshots');
check(substr_count($html,'<form ')===3 && substr_count($html,'</form>')===3, 'bulk settings, filters and update forms are separate and balanced');
check(strpos($html,'[selected]')===false && strpos($html,'[input_hash]')!==false,'bulk form has dirty detection and no selection checkboxes');
$filters=Bale_Order_Notifier_Operations::tracking_filters(['presence'=>'missing','search_by'=>'id','term'=>'۱۲']);
$args=Bale_Order_Notifier_Operations::tracking_query($config,$filters,2);
check($args['include']===[12] && $args['page']===2,'numeric Persian order search and pagination');
check($args['bon_tracking_meta']['relation']==='OR','missing tracking includes absent and empty metadata');
$legacy=Bale_Order_Notifier_Operations::legacy_tracking_query(['meta_query'=>[['key'=>'other']]],$args);
check(count($legacy['meta_query'])===2,'legacy storage filter preserves existing meta queries');
$filters=Bale_Order_Notifier_Operations::tracking_filters(['presence'=>'present','search_by'=>'billing_phone','term'=>'09121234567']);
$args=Bale_Order_Notifier_Operations::tracking_query($config,$filters,1);
check($args['billing_phone']==='09121234567' && $args['bon_tracking_meta']['compare']==='!=','phone and existing tracking filters');
$filters=Bale_Order_Notifier_Operations::tracking_filters(['presence'=>[],'search_by'=>'unsafe','term'=>[]]);
check($filters===['presence'=>'','search_by'=>'id','term'=>'','order_status'=>''],'malformed filter input rejected');
$args=Bale_Order_Notifier_Operations::tracking_query($config,['presence'=>'','search_by'=>'id','term'=>'not-number'],1);
check($args['include']===[0],'invalid order ID cannot broaden results');
check(strpos($html,'bon-tracking-form')!==false && strpos($html,'name="presence"')!==false,'filter and unsaved warning UI rendered');
reset_order(1);
$transients['bon_tracking_failed_10']=[1=>['row'=>['code'=>'BAD!','courier'=>'Post','url'=>'https://example.test/track','snapshot'=>Bale_Order_Notifier_Tracking::fingerprint($orders[1])],'error'=>'invalid draft']];
ob_start();$ops->tracking_page();$draft_html=ob_get_clean();
check(strpos($draft_html,'value="BAD!"')!==false && strpos($draft_html,'invalid draft')!==false,'unchanged order restores failed input and inline error');
$orders[1]->meta['_bon_tracking_code']='NEW123';
ob_start();$ops->tracking_page();$stale_html=ob_get_clean();
check(strpos($stale_html,'value="BAD!"')===false && strpos($stale_html,'value="NEW123"')!==false,'stale draft never replaces fresh order snapshot');
$filters=Bale_Order_Notifier_Operations::tracking_filters(['order_status'=>'processing','presence'=>'missing']);
$args=Bale_Order_Notifier_Operations::tracking_query(['sources'=>['completed']],$filters,1);
check($args['status']===['processing'] && isset($args['bon_tracking_meta']),'processing shortcut overrides display sources and combines with tracking filter');
check(Bale_Order_Notifier_Operations::tracking_filters(['order_status'=>'unsafe'])['order_status']==='','unrecognized status filter rejected');
check(strpos($html,'سفارش‌های در حال انجام')!==false,'processing shortcut visible');
reset_order(1);$options['bon_tracking_settings']=['sources'=>['completed'],'target'=>'completed'];$config=Bale_Order_Notifier_Operations::config();
$_POST=['config_hash'=>hash('sha256',wp_json_encode($config)),'return_filters'=>['order_status'=>'processing'],'rows'=>[1=>['code'=>'123','courier'=>'Post','snapshot'=>Bale_Order_Notifier_Tracking::fingerprint($orders[1]),'input_hash'=>Bale_Order_Notifier_Operations::tracking_input_hash(['code'=>'','courier'=>'Post'])]]];
try{$ops->bulk_submit();}catch(Redirected $e){check($orders[1]->status==='completed','processing shortcut rows can save without changing configured source statuses');}
echo "$n checks passed\n";
