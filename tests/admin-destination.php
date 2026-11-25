<?php
// Actual worker with a transport spy and deterministic DB snapshots.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
function add_action(...$a){}
function bon_get_option($key,$default=''){return $GLOBALS['options'][$key]??$default;}
function update_option(...$a){return true;}
function current_time($type){return $type==='mysql'?'2026-10-28 12:00:00':1793188800;}
function wp_json_encode($value){return json_encode($value);}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class Bale_Order_Notifier_API {
 static $calls=[];
 function __construct(...$a){}
 function send_message($chat,$text,...$a){self::$calls[]=[$chat,$text];return true;}
}
class DestinationDB {
 public $prefix='test_', $rows=[], $after_select=null, $fail_write=false;
 function prepare($sql,...$a){return $sql;}
 function get_var($sql){return 1;}
 function query($sql){return 0;}
 function get_results($sql){$rows=array_map(function($r){return clone $r;},array_values(array_filter($this->rows,function($r){return $r->status==='pending';})));if($this->after_select){$f=$this->after_select;$this->after_select=null;$f();}return $rows;}
 function update($table,$data,$where){if($this->fail_write)return false;$count=0;foreach($this->rows as $r){foreach($where as $key=>$value)if($r->$key!=$value)continue 2;foreach($data as $key=>$value)$r->$key=$value;$count++;}return $count;}
}
require __DIR__.'/../balika/includes/class-bon-queue.php';
$n=0;function check($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";$n++;}
function row($chat,$text='private'){return (object)['id'=>1,'platform'=>'bale','method'=>'sendMessage','order_id'=>123,'message_type'=>'admin_order','payload'=>json_encode(['chat_id'=>$chat,'text'=>$text]),'status'=>'pending','attempts'=>0];}
$options=['bale_chat_ids'=>['99'],'bale_admin_user_ids'=>['42']];$wpdb=new DestinationDB;$worker=Bale_Order_Notifier_Queue::instance();
$wpdb->rows=[row('42')];$worker->process_queue();
check(!Bale_Order_Notifier_API::$calls && $wpdb->rows[0]->status==='paused','removed receiving destination is paused without sending');
check(strpos($wpdb->rows[0]->last_error,'گیرندگان')!==false,'paused history explains revoked receiving permission');
check(!Bale_Order_Notifier_Queue::admin_delivery_allowed(row('42'),['chat_id'=>'42']),'bot command authority alone is not permission to receive queued notifications');
$options['bale_chat_ids']=['42'];$wpdb->rows=[row('42')];$worker->process_queue();
check(count(Bale_Order_Notifier_API::$calls)===1 && $wpdb->rows[0]->status==='sent','current destination still receives manager notification');
check(Bale_Order_Notifier_Queue::admin_delivery_allowed(row(42),['chat_id'=>42]),'integer payload ID matches string configuration');
$options['bale_chat_ids']=[' -123 ','@manager_channel'];
check(Bale_Order_Notifier_Queue::admin_delivery_allowed(row('-123'),['chat_id'=>'-123']),'explicit group destination remains supported');
check(Bale_Order_Notifier_Queue::admin_delivery_allowed(row('@manager_channel'),['chat_id'=>'@manager_channel']),'explicit named destination remains supported');
foreach ([null,[],['chat_id'=>[]],['chat_id'=>true],['chat_id'=>'']] as $payload) check(!Bale_Order_Notifier_Queue::admin_delivery_allowed(row('42'),$payload),'malformed or empty destination fails closed');
$options['bale_chat_ids']=['42'];$wpdb->rows=[row('42')];$wpdb->after_select=function(){ $GLOBALS['options']['bale_chat_ids']=[]; };$before=count(Bale_Order_Notifier_API::$calls);$worker->process_queue();
check(count(Bale_Order_Notifier_API::$calls)===$before && $wpdb->rows[0]->status==='paused','revocation after DB snapshot but before delivery is rechecked');
$options['bale_chat_ids']=['42'];$wpdb->rows=[row('42',str_repeat('x',3100))];$worker->process_queue();
check($wpdb->rows[0]->status==='pending' && json_decode($wpdb->rows[0]->payload,true)['_bon_chunk']===1,'first chunk records progress normally');
$options['bale_chat_ids']=[];$before=count(Bale_Order_Notifier_API::$calls);$worker->process_queue();
check(count(Bale_Order_Notifier_API::$calls)===$before && $wpdb->rows[0]->status==='paused','revocation prevents subsequent chunks');
$wpdb->rows=[row('42')];$wpdb->fail_write=true;$worker->process_queue();
check(count(Bale_Order_Notifier_API::$calls)===$before,'pause-write failure cannot fall through to API');$wpdb->fail_write=false;
$wpdb->rows=[row('42')];$wpdb->rows[0]->status='dead-letter';Bale_Order_Notifier_Queue::retry_failed();$worker->process_queue();
check(count(Bale_Order_Notifier_API::$calls)===$before && $wpdb->rows[0]->status==='paused','general retry cannot bypass current destination authorization');
$other=row('42');$other->message_type='tracking';check(Bale_Order_Notifier_Queue::admin_delivery_allowed($other,['chat_id'=>'42']),'customer messages remain governed by their separate order guard');
echo "$n checks passed\n";
