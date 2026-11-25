<?php
// Integration contract: real API + real queue, fake WP transport and DB only.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
function add_action(...$a){}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$a){$GLOBALS['options'][$key]=$value;return true;}
function wp_json_encode($v){return json_encode($v);}
function current_time($type){return $type==='mysql'?'2026-10-28 12:00:00':1793188800;}
function doing_action($hook){return ($GLOBALS['hook']??'')===$hook;}
class WP_Error {function get_error_message(){return 'Synthetic network failure';}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_post($url,$args){$GLOBALS['requests'][]=$args['body'];return $GLOBALS['response']==='network'?new WP_Error:$GLOBALS['response'];}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class ContractDB {
 public $prefix='test_', $rows=[], $seq=0, $fail_insert=false;
 function prepare($sql,...$a){return $sql;}
 function get_var($sql){return 1;}
 function query($sql){return 0;}
 function insert($t,$data){if($this->fail_insert)return false;$data['id']=++$this->seq;$this->rows[$this->seq]=(object)$data;return 1;}
 function get_results($sql){return array_map(function($r){return clone $r;},array_values(array_filter($this->rows,function($r){return $r->status==='pending';})));}
 function update($t,$data,$where){$count=0;foreach($this->rows as $r){foreach($where as $k=>$v)if(($r->$k??null)!=$v)continue 2;foreach($data as $k=>$v)$r->$k=$v;$count++;}return $count;}
}
require __DIR__.'/../balika/includes/class-bon-api.php';
require __DIR__.'/../balika/includes/class-bon-queue.php';
$options=['bon_settings'=>['bale_bot_token'=>'synthetic','bale_enabled'=>true,'bale_chat_ids'=>['42']]];
$wpdb=new ContractDB;$requests=[];$hook='';$response='network';$api=new Bale_Order_Notifier_API('bale');$worker=Bale_Order_Notifier_Queue::instance();$n=0;
function check($ok,$text){global $n;if(!$ok){fwrite(STDERR,"FAIL: $text\n");exit(1);}echo "PASS: $text\n";$n++;}
function success_response(){return ['code'=>200,'body'=>json_encode(['ok'=>true,'result'=>['message_id'=>77]])];}
$buttons=[[['text'=>'Panel','url'=>'https://example.test/panel']]];
$r=$api->send_message('42','Manager report',$buttons,true,0,'admin_order');
check(!empty($r['queued']) && $wpdb->rows[1]->message_type==='admin_order','network failure retains explicit type in actual queue');
check($wpdb->rows[1]->order_id===0 && json_decode($wpdb->rows[1]->payload,true)['text']==='Manager report','fallback preserves order ID and payload');
check(!isset($requests[0]['message_type']) && !isset($requests[0]['order_id']),'internal queue metadata never leaks into Bale API body');
$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[1]->status==='sent' && count($requests)===$before+1,'network fallback survives actual worker authorization and sends');
check($requests[count($requests)-1]['reply_markup']===$requests[0]['reply_markup'],'inline buttons survive fallback and worker round-trip');
foreach ([['code'=>503,'body'=>'service unavailable'],['code'=>200,'body'=>'{"ok":false,"description":"failure"}'],['code'=>200,'body'=>'not-json']] as $failure) {
 $response=$failure;$r=$api->send_message('42','HTTP fallback',[],true,0,'admin_order');$id=$wpdb->seq;
 check(!empty($r['queued']) && $wpdb->rows[$id]->message_type==='admin_order','HTTP/API/malformed response preserves queue type');
 $response=success_response();$worker->process_queue();check($wpdb->rows[$id]->status==='sent','HTTP/API fallback can recover through actual worker');
}
foreach (['woocommerce_checkout_order_processed','woocommerce_store_api_checkout_order_processed','woocommerce_new_order','woocommerce_order_status_changed'] as $hook) {
 $before=count($requests);$r=$api->send_message('42','Transactional notice',[],true,0,'admin_order');$id=$wpdb->seq;
 check(!empty($r['queued']) && count($requests)===$before && $wpdb->rows[$id]->message_type==='admin_order','transaction hook enqueues explicit type without network');
 $hook='';$worker->process_queue();check($wpdb->rows[$id]->status==='sent','transactional notice reaches worker without quarantine');
}
$api->send_message('42','Order-specific',[],true,321,'customer_status');$id=$wpdb->seq;
check($wpdb->rows[$id]->order_id===321 && $wpdb->rows[$id]->message_type==='customer_status','positive order ID retains customer authorization domain');
$wpdb->rows[$id]->status='paused';
$api->send_message('42',str_repeat('x',3100),[],true,0,'admin_order');$id=$wpdb->seq;$worker->process_queue();
check(json_decode($wpdb->rows[$id]->payload,true)['_bon_chunk']===1,'long typed message preserves chunk progress');
$worker->process_queue();check($wpdb->rows[$id]->status==='sent','long typed message completes after final chunk');
$response='network';$before=$wpdb->seq;$r=$api->send_message('42','worker attempt',[],false,0,'admin_order');
check($r===false && $wpdb->seq===$before,'queue_on_fail false cannot recursively enqueue');
$wpdb->fail_insert=true;$r=$api->send_message('42','failed persistence',[],true,0,'admin_order');
check($r===false,'failed fallback insertion is not reported as queued');$wpdb->fail_insert=false;
$r=$api->send_message('42','No declared authority',[],true);$id=$wpdb->seq;$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'untyped messages remain quarantined rather than silently promoted');
$response='network';$api->send_message('42','Former manager',[],true,0,'admin_order');$id=$wpdb->seq;$options['bon_settings']['bale_chat_ids']=[];$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'preserving type does not bypass removed-destination protection');
$options['bon_settings']['bale_chat_ids']=['42'];$response='network';
$api->send_message('42','Repeat network failure',[],true,0,'admin_order');$id=$wpdb->seq;$size=count($wpdb->rows);$worker->process_queue();
check(count($wpdb->rows)===$size && $wpdb->rows[$id]->attempts===1 && $wpdb->rows[$id]->message_type==='admin_order','worker transport failure updates same typed row without recursive enqueue');
$response=success_response();$worker->process_queue();check($wpdb->rows[$id]->status==='sent','same row completes after subsequent network recovery');
// Explicit bot replies use real API -> queue -> worker, with scoped authorization collaborators.
class Bale_Order_Notifier_Customer_Store {
 static $allowed=true,$hash;
 static function owner_hash($order){return self::$hash;}
 static function owns_order($id,$platform,$chat){return self::$allowed && $id===123 && $chat==='42';}
}
class Bale_Order_Notifier_Commerce {
 static $enabled=true,$public=true;
 static function enabled($feature){return self::$enabled;}
 static function public_product($p){return self::$public && (bool)$p;}
}
function wc_get_order($id){return $id===123?(object)['id'=>123]:false;}
function wc_get_product($id){return $id===10?(object)['id'=>10]:false;}
$options['bon_settings']['bale_chat_ids']=['42'];$response='network';
$r=$api->send_reply('42','Public help',$buttons);$id=$wpdb->seq;$p=json_decode($wpdb->rows[$id]->payload,true);
check(!empty($r['queued']) && $wpdb->rows[$id]->message_type==='bot_reply','fresh public reply enters typed queue on network failure');
check($p['_bon_reply']['chat']==='42' && $p['_bon_reply']['expires']<=time()+900,'reply stores destination-bound fifteen-minute deadline');
$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='sent' && count($requests)===$before+1,'fresh typed reply recovers through actual worker');
check(!isset($requests[count($requests)-1]['_bon_reply']),'reply authorization context is not sent to Bale');
$response='network';$api->send_reply('42','Expiring');$id=$wpdb->seq;$p=json_decode($wpdb->rows[$id]->payload,true);$p['_bon_reply']['expires']=time()-1;$wpdb->rows[$id]->payload=json_encode($p);$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'expired reply cannot deliver');
Bale_Order_Notifier_Queue::retry_failed();$wpdb->rows[$id]->status='pending';$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'resetting queue status does not bypass reply expiry');
$response='network';$options['bon_settings']['bale_admin_user_ids']=['42'];$options['bon_settings']['bale_chat_ids']=[];
$api->send_reply('42','Private admin report',[],['scope'=>'admin']);$id=$wpdb->seq;
check($wpdb->rows[$id]->message_type==='bot_reply','authorized command actor can receive private requested report without broadcast membership');
$options['bon_settings']['bale_admin_user_ids']=[];$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'revoked admin cannot receive delayed requested report');
Bale_Order_Notifier_Customer_Store::$hash=str_repeat('a',64);$response='network';
$api->send_reply('42','Order list',[],['scope'=>'orders','orders'=>[123=>str_repeat('a',64)]]);$id=$wpdb->seq;
Bale_Order_Notifier_Customer_Store::$allowed=false;$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'disconnected customer cannot receive delayed order list');
Bale_Order_Notifier_Customer_Store::$allowed=true;$response='network';
$api->send_reply('42','Old owner list',[],['scope'=>'orders','orders'=>[123=>str_repeat('a',64)]]);$id=$wpdb->seq;
Bale_Order_Notifier_Customer_Store::$hash=str_repeat('b',64);$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'owner change invalidates delayed order reply even if chat reconnects');
$response='network';$api->send_reply('42','Catalog',[],['scope'=>'catalog','products'=>[10]]);$id=$wpdb->seq;
Bale_Order_Notifier_Commerce::$public=false;$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'newly private product blocks stale catalog reply');
Bale_Order_Notifier_Commerce::$public=true;$response='network';$api->send_reply('42','Catalog',[],['scope'=>'catalog','products'=>[10]]);$id=$wpdb->seq;
Bale_Order_Notifier_Commerce::$enabled=false;$response=success_response();$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'disabled bot catalog blocks delayed catalog reply');
$before=$wpdb->seq;check($api->send_reply('-123','Private reply')===false && $wpdb->seq===$before,'group destination cannot use private retry context');
check($api->send_reply('42','Unknown',[],['scope'=>'unknown'])===false,'unknown reply scope fails closed');
check($api->send_reply('42','No owner snapshot',[],['scope'=>'orders'])===false,'empty order authorization fails closed');
$response=success_response();$before=$wpdb->seq;$api->send_reply('42','Immediate public reply');check($wpdb->seq===$before,'successful short reply is not queued again');
$api->send_reply('42',str_repeat('x',3100));$id=$wpdb->seq;$worker->process_queue();
check($wpdb->rows[$id]->status==='pending' && json_decode($wpdb->rows[$id]->payload,true)['_bon_chunk']===1,'long reply uses normal chunk checkpoints');
$p=json_decode($wpdb->rows[$id]->payload,true);$p['_bon_reply']['expires']=time()-1;$wpdb->rows[$id]->payload=json_encode($p);$before=count($requests);$worker->process_queue();
check($wpdb->rows[$id]->status==='paused' && count($requests)===$before,'expiry between chunks prevents remaining text delivery');
$p['_bon_reply']['expires']=time()+900;$p['chat_id']='43';check(!Bale_Order_Notifier_API::reply_allowed($p),'changed destination cannot reuse original reply context');
$p['chat_id']='42';$p['_bon_reply']['expires']=time()+1800;check(!Bale_Order_Notifier_API::reply_allowed($p),'unbounded future deadline rejected');
echo "$n checks passed\n";
