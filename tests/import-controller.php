<?php
define('ABSPATH', __DIR__);define('MINUTE_IN_SECONDS',60);
class WP_Error {private $message;function __construct($code,$message){$this->message=$message;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$args){}
function current_user_can(...$args){return $GLOBALS['can'];}
function get_current_user_id(){return $GLOBALS['user'];}
function get_transient($k){return $GLOBALS['cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['cache'][$k]=$v;return true;}
function wp_unslash($s){return $s;}
function sanitize_key($s){return $s;}
function absint($s){return abs((int)$s);}
function wp_die(...$args){throw new RuntimeException('forbidden');}
function check_admin_referer($action){if(!$GLOBALS['nonce'])throw new RuntimeException('csrf');}
function admin_url($s){return 'https://shop.test/'.$s;}
function add_query_arg($args,$base){return $base.'?'.http_build_query($args);}
class Redirected extends RuntimeException {}
function wp_safe_redirect($url){throw new Redirected($url);}
class Bale_Order_Notifier_Operations {static function config(){return $GLOBALS['config'];}}
class Bale_Order_Notifier_Tracking {
    static $calls=[],$fail=false;
    static function save(...$args){self::$calls[]=$args;return self::$fail?new WP_Error('fail','failed'):['message'=>'queued'];}
}
class FakeDB {
    public $lock=true,$releases=0;
    function prepare($q,...$a){return $q;}
    function get_var($q){if(strpos($q,'RELEASE_LOCK')!==false){$this->releases++;return 1;}return $this->lock?1:0;}
}
require __DIR__.'/../balika/includes/class-bon-tracking-import.php';
$user=10;$can=true;$nonce=true;$cache=[];$wpdb=new FakeDB();$config=['sources'=>['processing'],'target'=>'completed'];
$token=str_repeat('a',32);$key='bon_csv_10_'.hash('sha256',$token);$n=0;
function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";$n++;}
function seed(){global $cache,$key,$config,$token;$cache[$key]=['phase'=>'preview','config'=>$config,'rows'=>[['order_id'=>1,'done'=>false,'error'=>'','snapshot'=>'old','code'=>'123']]];$_POST=['operation'=>'apply','batch'=>$token,'selected'=>[0],'confirm'=>1];Bale_Order_Notifier_Tracking::$calls=[];}
$controller=new Bale_Order_Notifier_Tracking_Import();
function run_request($controller){try{$controller->handle();}catch(Redirected $e){return;}throw new RuntimeException('Expected redirect');}
seed();$can=false;try{$controller->handle();}catch(RuntimeException $e){check($e->getMessage()==='forbidden','import endpoint rejects unauthorised user');}$can=true;
$nonce=false;try{$controller->handle();}catch(RuntimeException $e){check($e->getMessage()==='csrf','import endpoint rejects invalid nonce');}$nonce=true;
seed();$user=11;run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'another user cannot apply a stolen batch token');$user=10;
seed();unset($cache[$key]);run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'expired batch does not modify orders');
seed();unset($_POST['confirm']);run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'explicit final confirmation required');
seed();$_POST['selected']=range(0,20);run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'more than 20 selected rows rejected');
seed();$wpdb->lock=false;run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'simultaneous apply blocked by batch lock');$wpdb->lock=true;
seed();$cache[$key]['config']['target']='pending';run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls&&$wpdb->releases>0,'changed source or target invalidates preview and releases lock');
seed();$cache[$key]['rows'][0]['error']='invalid code';run_request($controller);check(!Bale_Order_Notifier_Tracking::$calls,'forged selection cannot apply an invalid preview row');
seed();$_POST['replace']=[0=>1];run_request($controller);check(count(Bale_Order_Notifier_Tracking::$calls)===1&&Bale_Order_Notifier_Tracking::$calls[0][4]==='old'&&Bale_Order_Notifier_Tracking::$calls[0][5]===true,'stored snapshot and explicit replacement passed to shared service');
check($cache[$key]['rows'][0]['done']===true&&$cache[$key]['rows'][0]['result']==='queued','row result checkpointed before redirect');
run_request($controller);check(count(Bale_Order_Notifier_Tracking::$calls)===1,'replayed POST cannot apply a completed row twice');
seed();Bale_Order_Notifier_Tracking::$fail=true;run_request($controller);check($cache[$key]['rows'][0]['done']&&$cache[$key]['rows'][0]['result']==='failed','failed row requires a new preview rather than blind retries');
echo "$n checks passed\n";
