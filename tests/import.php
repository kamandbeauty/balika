<?php
define('ABSPATH', __DIR__);
class WP_Error {
    private $code, $message;
    function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    function get_error_code(){return $this->code;}
    function get_error_message(){return $this->message;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($v){return strip_tags($v);}
function absint($v){return abs((int)$v);}
function current_user_can(...$args){return $GLOBALS['allowed'];}
function wp_http_validate_url($v){return preg_match('~^https?://[^/]+~', $v);}
function apply_filters($hook, $ids, $value){return $GLOBALS['candidates'][$value] ?? $ids;}
function wc_get_order($id){return $GLOBALS['orders'][$id] ?? false;}
class ImportOrder {
    public $id, $number, $status='processing';
    function __construct($id,$number){$this->id=$id;$this->number=$number;}
    function get_id(){return $this->id;}
    function get_type(){return 'shop_order';}
    function get_order_number(){return $this->number;}
    function get_formatted_billing_full_name(){return 'Test Customer';}
    function get_status(){return $this->status;}
    function get_meta($key){return '';}
}
require __DIR__.'/../balika/includes/class-bon-tracking.php';
require __DIR__.'/../balika/includes/class-bon-tracking-import.php';
$n=0;$allowed=true;$candidates=[];
$orders=[1=>new ImportOrder(1,'1'),2=>new ImportOrder(2,'WEB-2'),3=>new ImportOrder(3,'WEB-2')];
function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";$n++;}
$c=Bale_Order_Notifier_Tracking_Import::class;
$d=$c::parse("\xEF\xBB\xBForder,code\r\n1,0012345\r\n");
check(!is_wp_error($d)&&$d['rows'][0]['cells'][1]==='0012345','UTF-8 BOM and leading zeros preserved');
check($c::parse("order;code\n1;123")['rows'][0]['cells'][1]==='123','semicolon delimiter supported');
check($c::parse("order\tcode\n1\t123")['rows'][0]['cells'][1]==='123','tab delimiter supported');
check($c::parse("order,code,name\n1,123,\"A, B\"")['rows'][0]['cells'][2]==='A, B','quoted comma preserved');
check(is_wp_error($c::parse("a,b\n1,2,3")),'uneven columns reject entire file');
check(is_wp_error($c::parse('')),'empty file rejected');
check(is_wp_error($c::parse("a,b\n\0,1")),'UTF-16 and binary content rejected');
check(is_wp_error($c::parse("a,b\n\xFF,1")),'invalid UTF-8 rejected');
check(is_wp_error($c::parse(str_repeat('x',1048577))),'oversized file rejected');
check(is_wp_error($c::parse("a,b\n".str_repeat("1,123\n",201))),'201-row file rejected rather than truncated');
check(count($c::parse("a,b\n".str_repeat("1,123\n",200))['rows'])===200,'200 rows accepted');
check(is_wp_error($c::parse("a,b\n1,".str_repeat('x',2001))),'oversized cell rejected');
check($c::resolve('1','id')->get_id()===1,'explicit internal ID matches');
check(is_wp_error($c::resolve('1E3','id')),'Excel scientific order identifier rejected');
check(is_wp_error($c::resolve('2','number')),'internal ID is not mistaken for custom display number');
check(is_wp_error($c::resolve('WEB-2','number')),'unknown custom numbering is not guessed');
$candidates['WEB-2']=[2];
check($c::resolve('WEB-2','number')->get_id()===2,'exact custom-number adapter match accepted');
$candidates['WEB-2']=[2,3];
check(is_wp_error($c::resolve('WEB-2','number')),'ambiguous custom number rejected');
$map=['order'=>0,'code'=>1,'courier'=>-1,'url'=>-1];$config=['sources'=>['processing'],'target'=>'completed','courier'=>'Post'];
$rows=$c::preview($d,$map,'id',$config);
check($rows[0]['error']===''&&$rows[0]['snapshot']!==''&&$rows[0]['done']===false,'preview snapshots valid orders without saving');
check(is_wp_error($c::preview($d,['order'=>0,'code'=>0,'courier'=>-1,'url'=>-1],'id',$config)),'same column cannot map to two fields');
check(is_wp_error($c::preview($d,['order'=>0,'code'=>99,'courier'=>-1,'url'=>-1],'id',$config)),'out-of-range column rejected');
$dups=$c::preview($c::parse("order,code\n1,123\n1,456"),$map,'id',$config);
check($dups[0]['error']!==''&&$dups[1]['error']!=='','both duplicate order rows blocked');
$dups=$c::preview($c::parse("order,code\n1,123\n2,123"),$map,'id',$config);
check($dups[0]['error']!==''&&$dups[1]['error']!=='','both duplicate tracking code rows blocked');
$dups=$c::preview($c::parse("order,code\n1,123\n2,123\n3,123"),$map,'id',$config);
check(count(array_filter($dups,function($r){return $r['error']!=='';}))===3,'all repeated occurrences blocked');
$allowed=false;$rows=$c::preview($d,$map,'id',$config);
check($rows[0]['error']!==''&&!isset($rows[0]['name']),'inaccessible order details not exposed');$allowed=true;
$orders[1]->status='cancelled';$rows=$c::preview($d,$map,'id',$config);
check($rows[0]['error']!=='','cancelled order outside source statuses blocked');
echo "$n checks passed\n";
