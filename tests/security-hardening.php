<?php
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');define('DAY_IN_SECONDS',86400);
function add_action(...$a){}function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function bon_get_option($k,$d=''){return $GLOBALS['options']['bon_settings'][$k]??$d;}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;return true;}
function sanitize_text_field($s){return $s;}function sanitize_textarea_field($s){return $s;}
function current_time($s){return $s==='mysql'?'2026-10-28 12:00:00':1793188800;}
function wp_json_encode($v){return json_encode($v);}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
require __DIR__.'/fixtures/owner-order.php';
class Order extends OwnerOrderFixture {public $phone='09121234567',$paid=false;function get_billing_phone(){return $this->phone;}function needs_payment(){return !$this->paid;}}
class DB {
 public $scoped=['42'];
 public $prefix='test_', $lock=true,$released=0,$links=[['platform'=>'bale','chat_id'=>'42']],$rows=[],$logs=[],$fail_delete=false,$fail_cancel=false;
 function get_row($q){return $this->scoped?(object)['chat_id'=>$this->scoped[0],'owner_hash'=>$this->scope_hash]:null;}
 public $scope_hash='';
 function prepare($sql,...$a){return [$sql,$a];}
 function get_var($q){if(strpos($q[0],'SELECT chat_id FROM')!==false)return $this->scoped[0]??null;if(strpos($q[0],'RELEASE_LOCK')!==false){$this->released++;return 1;}return $this->lock?1:0;}
 function get_results($q,...$a){if(strpos($q[0],'bon_logs')!==false)return array_map(function($m){return (object)['message'=>$m];},$this->logs);if(strpos($q[0],'bon_customer_links')!==false)return $this->links;return array_values($this->rows);}
 function delete($table,$where){if($this->fail_delete)return false;if(strpos($table,'bon_order_links')!==false){$n=count($this->scoped);$this->scoped=[];return $n;}$n=count($this->links);$this->links=[];return $n;}
 function update($t,$data,$where){if($this->fail_cancel)return false;foreach($this->rows as $r)if($r->id===$where['id']){foreach($data as $k=>$v)$r->$k=$v;return 1;}return 0;}
 function insert($t,$data){$this->logs[]=$data['message']??'';return 1;}
 function query($q){return 1;}
}
class Bale_Order_Notifier_API {static $calls=0;function __construct(...$a){}function send_message(...$a){self::$calls++;return true;}}
require __DIR__.'/../balika/includes/class-bon-logger.php';
require __DIR__.'/../balika/includes/class-bon-customer-store.php';
require __DIR__.'/../balika/includes/class-bon-queue.php';
$wpdb=new DB;$options=['bon_settings'=>['bale_bot_token'=>'unusual:SECRET','webhook_secret'=>'privateWebhook','payment_reminder_enabled'=>true]];$orders=[1=>new Order];$wpdb->scope_hash=Bale_Order_Notifier_Customer_Store::owner_hash($orders[1]);$n=0;
function check($ok,$s){global $n;if(!$ok){fwrite(STDERR,"FAIL: $s\n");exit(1);}echo "PASS: $s\n";$n++;}
Bale_Order_Notifier_Logger::log('error','https://tapi.bale.ai/bot123456:abcdefghijklmnopqrstuvwxyz/sendMessage unusual:SECRET unusual%3ASECRET ?secret=privateWebhook');
$line=end($wpdb->logs);check(strpos($line,'abcdefghijklmnopqrstuvwxyz')===false,'API URL token masked without word boundary');
check(strpos($line,'unusual')===false && strpos($line,'privateWebhook')===false,'configured secrets and URL-encoded variants removed');
$wpdb->logs=['old unusual:SECRET'];check(strpos(Bale_Order_Notifier_Logger::get_logs()[0]->message,'unusual')===false,'old log output redacted on read');
$item=(object)['id'=>1,'platform'=>'bale','order_id'=>1,'message_type'=>'payment_reminder','status'=>'pending','attempts'=>0,'payload'=>json_encode(['chat_id'=>'42','text'=>'private'])];$params=json_decode($item->payload,true);
check(Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'current mapping and unpaid order permit reminder');
$orders[1]->paid=true;check(!Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'paid order blocks stale payment reminder');$orders[1]->paid=false;
$wpdb->scoped=[];check(!Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'removed mapping blocks queued customer payload');$wpdb->scoped=['99'];
check(!Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'reassigned mapping blocks old chat');$wpdb->scoped=['42'];
$options['bon_settings']['customer_notifications_enabled']=false;check(!Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'customer notification switch respected');$options['bon_settings']['customer_notifications_enabled']=true;
$wpdb->rows=[1=>clone $item,2=>(object)['id'=>2,'message_type'=>'daily_2026-10-27_x','payload'=>$item->payload,'status'=>'pending']];
$wpdb->lock=false;check(Bale_Order_Notifier_Customer_Store::unlink_chat('bale','42')===false && count($wpdb->links)===1,'busy worker prevents false successful unlink');$wpdb->lock=true;
$wpdb->fail_delete=true;check(Bale_Order_Notifier_Customer_Store::unlink_chat('bale','42')===false && $wpdb->released===1,'failed delete releases worker lock');$wpdb->fail_delete=false;
check(Bale_Order_Notifier_Customer_Store::unlink_chat('bale','42')===2 && !$wpdb->links,'successful unlink removes customer mapping');
check($wpdb->rows[1]->status==='cancelled' && $wpdb->rows[2]->status==='pending','unlink cancels customer message without deleting manager daily history');
$wpdb->rows=[1=>clone $item];Bale_Order_Notifier_Queue::instance()->process_queue();check(Bale_Order_Notifier_API::$calls===0 && $wpdb->rows[1]->status==='paused','worker rechecks mapping before API call');
$options['bon_settings']['bale_enabled']=false;$wpdb->rows[1]->status='pending';Bale_Order_Notifier_Queue::instance()->process_queue();check(Bale_Order_Notifier_API::$calls===0 && $wpdb->rows[1]->status==='pending','master switch leaves pending queue unsent');
$options['bon_settings']['bale_enabled']=true;$wpdb->scoped=['42'];$wpdb->rows=[1=>clone $item];$orders[1]->customer_id=2;
check(!Bale_Order_Notifier_Queue::customer_delivery_allowed($item,$params),'queued customer message fails after ownership transfer');
Bale_Order_Notifier_Queue::instance()->process_queue();check(Bale_Order_Notifier_API::$calls===0 && $wpdb->rows[1]->status==='paused','ownership transfer blocks actual worker transport');
echo "$n checks passed\n";
