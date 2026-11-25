<?php
define('ABSPATH',__DIR__);
function add_action(...$a){}
function get_transient($key){return false;}function set_transient(...$a){}
function bon_get_option($key,$default=''){return $key==='bale_admin_user_ids'?['42']:$default;}
function bon_get_enabled_platforms(){return ['bale'];}
function admin_url($p){return 'https://example.test/'.$p;}
function wc_get_order_status_name($s){return $s;}
class Bale_Order_Notifier_API {
 static $calls=[];
 function __construct(...$a){}
 function send_reply(...$a){self::$calls[]=['reply',$a];return true;}
 function send_message(...$a){self::$calls[]=['direct',$a];return true;}
 function answer_callback_query(...$a){}
}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class ReplyOrder {
 function get_id(){return 123;}function get_order_number(){return 'ORDER123';}function get_status(){return 'processing';}function get_meta($key){return 'TRACK123';}
}
class Bale_Order_Notifier_Customer_Store {
 static $orders=[];
 static function orders_for_chat($chat){return self::$orders;}
 static function owner_hash($o){return str_repeat('a',64);}
}
class Bale_Order_Notifier_Reports {
 static function get_report_types(){return ['today'=>'Today'];}
 static function generate($type){return 'Private report';}
}
require __DIR__.'/../balika/includes/class-bon-webhook.php';
$n=0;function check($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";$n++;}
function invoke($name,...$args){$r=new ReflectionMethod(Bale_Order_Notifier_Webhook::class,$name);if(PHP_VERSION_ID<80100)$r->setAccessible(true);return $r->invoke(Bale_Order_Notifier_Webhook::instance(),...$args);}
Bale_Order_Notifier_Customer_Store::$orders=[new ReplyOrder];invoke('customer_orders','42');$call=end(Bale_Order_Notifier_API::$calls);
check($call[0]==='reply' && $call[1][3]===['scope'=>'orders','orders'=>[123=>str_repeat('a',64)]],'order-list producer supplies exact order-owner scope');
Bale_Order_Notifier_Customer_Store::$orders=[];invoke('customer_orders','42');$call=end(Bale_Order_Notifier_API::$calls);
check($call[1][3]===['scope'=>'public'] && strpos($call[1][1],'ORDER123')===false,'empty order-list help has no personal content');
invoke('send_report_menu','bale','42');$call=end(Bale_Order_Notifier_API::$calls);
check($call[0]==='reply' && $call[1][3]===['scope'=>'admin'],'private report menu is administrator-scoped');
invoke('send_report_menu','bale','-123');$call=end(Bale_Order_Notifier_API::$calls);
check($call[0]==='direct' && $call[1][3]===false,'group menu does not create private retry job');
$callback=['data'=>'bon_report:today','from'=>['id'=>'42'],'id'=>'cb','message'=>['chat'=>['id'=>'42']]];
invoke('handle_callback_query','bale',$callback);$call=end(Bale_Order_Notifier_API::$calls);
check($call[0]==='reply' && $call[1][3]===['scope'=>'admin'],'requested private financial report carries admin scope');
$callback['message']['chat']['id']='-123';invoke('handle_callback_query','bale',$callback);$call=end(Bale_Order_Notifier_API::$calls);
check($call[0]==='direct' && $call[1][3]===false,'group financial reply remains direct-only');
$before=count(Bale_Order_Notifier_API::$calls);$callback['from']['id']='99';invoke('handle_callback_query','bale',$callback);
check(count(Bale_Order_Notifier_API::$calls)===$before,'unauthorized actor creates no financial reply');
echo "$n checks passed\n";
