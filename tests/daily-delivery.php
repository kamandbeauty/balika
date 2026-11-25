<?php
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
function add_action(...$a){} function add_filter(...$a){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$a){if($GLOBALS['fail_option'])return false;$GLOBALS['options'][$key]=$value;return true;}
function bon_get_option($key,$default=''){return $GLOBALS['options']['bon_settings'][$key]??$default;}
function bon_get_enabled_platforms(){return $GLOBALS['bale']?['bale']:[];}
function current_time($type){return $type==='mysql'?'2026-10-28 12:00:00':1793188800;}
function wp_json_encode($value){return json_encode($value);}
class Bale_Order_Notifier_Reports {static $calls=0;static function range_report($bounds){self::$calls++;return 'Frozen sales '.self::$calls;}}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class Bale_Order_Notifier_API {static $calls=[],$fail=[],$crash=false;function __construct(...$a){}function send_message($chat,$text,...$a){self::$calls[]=$chat;if(self::$crash)throw new RuntimeException('transport interrupted');return !in_array((string)$chat,self::$fail,true);}function get_last_error(){return 'network failed';}}
class DB {
    public $last_error='', $fail_read=false;
    public $prefix='test_', $rows=[], $seq=0, $fail_chat='', $busy=false, $fail_checkpoint=false, $queries=[];
    function prepare($sql,...$args){return [$sql,$args];}
    function get_var($query){[$sql,$args]=$query;if(strpos($sql,'GET_LOCK')!==false)return $this->busy?0:1;if(strpos($sql,'RELEASE_LOCK')!==false)return 1;if($this->fail_read){$this->last_error='read failed';return null;}$this->last_error='';foreach($this->rows as $r)if($r->message_type===($args[0]??''))return $r->id;return null;}
    function get_row($query){[$sql,$args]=$query;if(strpos($sql,'WHERE id=')!==false)return isset($this->rows[$args[0]])?clone $this->rows[$args[0]]:null;foreach($this->rows as $r)if($r->message_type===$args[0])return clone $r;return null;}
    function get_results($query){return array_map(function($r){return clone $r;},array_values(array_filter($this->rows,function($r){return $r->status==='pending';})));}
    function query($q){$this->queries[]=$q;return 1;}
    function insert($table,$data){if((json_decode($data['payload'],true)['chat_id']??'')===$this->fail_chat)return false;$data['id']=++$this->seq;$data['last_error']='';$data['sent_at']=null;$this->rows[$this->seq]=(object)$data;return 1;}
    function update($table,$data,$where){if($this->fail_checkpoint && isset($data['status']) && $data['status']==='sent')return false;$count=0;foreach($this->rows as $r){foreach($where as $k=>$v)if($r->$k!=$v)continue 2;foreach($data as $k=>$v)$r->$k=$v;$count++;}return $count;}
}
require __DIR__.'/../balika/includes/class-bon-queue.php';
require __DIR__.'/../balika/includes/class-bon-daily-delivery.php';
$options=['bon_settings'=>['daily_report_enabled'=>true,'bale_chat_ids'=>['42','42','43']]];$bale=true;$fail_option=false;$wpdb=new DB;$n=0;
function check($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";$n++;}
$day='2026-10-27';$bounds=[1,2];
$fail_option=true;check(!Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds) && !$wpdb->rows,'snapshot save failure queues nothing');$fail_option=false;
$wpdb->fail_chat='43';check(!Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds) && count($wpdb->rows)===1,'partial enqueue retains snapshot and first recipient');
$text=Bale_Order_Notifier_Daily_Delivery::record($day)['text'];$calls=Bale_Order_Notifier_Reports::$calls;
$wpdb->fail_chat='';check(Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds) && count($wpdb->rows)===2,'recovery only inserts missing unique recipient');
check(Bale_Order_Notifier_Reports::$calls===$calls && Bale_Order_Notifier_Daily_Delivery::record($day)['text']===$text,'recovery uses frozen report instead of recalculating sales');
Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds);check(count($wpdb->rows)===2,'repeated producer cannot duplicate queued report');
Bale_Order_Notifier_API::$fail=['43'];$worker=Bale_Order_Notifier_Queue::instance();$worker->process_queue();
check($wpdb->rows[1]->status==='sent' && $wpdb->rows[2]->status==='pending','recipient delivery states tracked separately');
for($i=0;$i<4;$i++)$worker->process_queue();
check($wpdb->rows[2]->status==='dead-letter' && count(array_filter(Bale_Order_Notifier_API::$calls,function($c){return $c==='42';}))===1,'retry failures do not resend successful recipient');
Bale_Order_Notifier_Daily_Delivery::retry_one(1);check($wpdb->rows[1]->status==='sent','manual retry refuses sent message');
Bale_Order_Notifier_Daily_Delivery::retry_one(2);check($wpdb->rows[2]->status==='pending' && $wpdb->rows[2]->attempts===0,'manual retry resets only failed destination');
Bale_Order_Notifier_API::$fail=[];$worker->process_queue();check($wpdb->rows[2]->status==='sent','failed recipient can recover');
$options['bon_settings']['bale_chat_ids']=['42'];$params=json_decode($wpdb->rows[2]->payload,true);
check(!Bale_Order_Notifier_Daily_Delivery::can_deliver($wpdb->rows[2]->message_type,$params),'removed destination rejected at delivery');
$options['bon_settings']['bale_chat_ids']=['42','43'];$options['bon_settings']['daily_report_enabled']=false;$wpdb->rows[2]->status='pending';$worker->process_queue();check($wpdb->rows[2]->status==='paused','disabled daily reports pause queue delivery');
Bale_Order_Notifier_Daily_Delivery::retry_one(2);check($wpdb->rows[2]->status==='paused','manual retry cannot bypass disabled report');
$options['bon_settings']['daily_report_enabled']=true;Bale_Order_Notifier_Daily_Delivery::retry_one(2);check($wpdb->rows[2]->status==='pending','re-enabled report can explicitly resume');
$params['text']='tampered';check(!Bale_Order_Notifier_Daily_Delivery::can_deliver($wpdb->rows[2]->message_type,$params),'payload must match durable snapshot');
$wpdb->busy=true;check(!Bale_Order_Notifier_Daily_Delivery::prepare('2026-10-26',$bounds),'queue lock contention fails closed');$wpdb->busy=false;
check(strpos($wpdb->queries[0][0],"message_type NOT LIKE")!==false,'queue retention excludes daily report history');
$wpdb->fail_read=true;$count=count($wpdb->rows);check(!Bale_Order_Notifier_Daily_Delivery::prepare($day,$bounds) && count($wpdb->rows)===$count,'failed existence query cannot enqueue duplicates');$wpdb->fail_read=false;$wpdb->last_error='';
$options['bon_settings']['bale_chat_ids']=[];
check(!Bale_Order_Notifier_Daily_Delivery::prepare('2026-10-24',$bounds) && !Bale_Order_Notifier_Daily_Delivery::record('2026-10-24'),'no destinations leaves day recoverable instead of creating empty completed snapshot');
$options['bon_settings']['bale_chat_ids']=['42'];$options['bon_settings']['weekly_report_enabled']=true;
check(Bale_Order_Notifier_Daily_Delivery::prepare('2026-10-23_weekly',$bounds,'weekly snapshot'),'weekly report uses durable per-destination outbox');
$type=Bale_Order_Notifier_Daily_Delivery::type('2026-10-23_weekly','42');$payload=['chat_id'=>'42','text'=>'weekly snapshot'];
check(strlen($type)<=64 && Bale_Order_Notifier_Daily_Delivery::can_deliver($type,$payload),'weekly identity fits queue field and respects weekly permission');
$options['bon_settings']['weekly_report_enabled']=false;check(!Bale_Order_Notifier_Daily_Delivery::can_deliver($type,$payload),'disabled weekly report pauses delivery');
$options['bon_settings']['auto_stock_reports']=true;
Bale_Order_Notifier_Daily_Delivery::prepare('2026-10-23_low_stock',$bounds,'stock snapshot');
$type=Bale_Order_Notifier_Daily_Delivery::type('2026-10-23_low_stock','42');$payload=['chat_id'=>'42','text'=>'stock snapshot'];
check(Bale_Order_Notifier_Daily_Delivery::can_deliver($type,$payload),'stock attachment shares history and retry guard');
$options['bon_settings']['auto_stock_reports']=false;check(!Bale_Order_Notifier_Daily_Delivery::can_deliver($type,$payload),'disabled stock attachment cannot deliver');
class CheckpointDB extends DB {
 public $failure='';
 function update($table,$data,$where){
  if (($this->failure==='claim' && ($data['status']??'')==='uncertain') || ($this->failure==='chunk' && isset($data['payload'])) || ($this->failure==='final' && ($data['status']??'')==='sent')) return false;
  return parent::update($table,$data,$where);
 }
}
$options['bon_settings']['bale_chat_ids']=['42'];
foreach (['claim','final','chunk','crash'] as $failure) {
 $wpdb=new CheckpointDB;$wpdb->failure=$failure;Bale_Order_Notifier_API::$calls=[];
 Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>'42','text'=>$failure==='chunk'?str_repeat('x',3100):'Once'],0,'admin_order');
 Bale_Order_Notifier_API::$crash=$failure==='crash';
 try {$worker->process_queue();}catch(RuntimeException $e){if($e->getMessage()!=='transport interrupted')throw $e;}
 Bale_Order_Notifier_API::$crash=false;
 if ($failure==='claim') {
  check(!Bale_Order_Notifier_API::$calls && $wpdb->rows[1]->status==='pending','failed pre-send persistence performs no transport');
  $wpdb->failure='';$worker->process_queue();check($wpdb->rows[1]->status==='sent','unsent claim failure can recover safely');
 } else {
  check(count(Bale_Order_Notifier_API::$calls)===1 && $wpdb->rows[1]->status==='uncertain','durable uncertain marker survives '.$failure.' failure');
  $wpdb->failure='';$worker->process_queue();Bale_Order_Notifier_Queue::retry_failed();Bale_Order_Notifier_Daily_Delivery::retry_one(1);$worker->process_queue();
  check(count(Bale_Order_Notifier_API::$calls)===1 && $wpdb->rows[1]->status==='uncertain','automatic and generic retries cannot replay '.$failure.' failure');
 }
}
echo "$n checks passed\n";
