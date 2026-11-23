<?php
/** READ-ONLY audit reproductions: passing means a defect is observable.
 * Real plugin methods, fake WP/DB/transport. Interleavings model races, not live MySQL concurrency.
 * Kept OUT of tests/run.sh. No credentials, HTTP calls, or production orders.
 */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS',86400); define('MINUTE_IN_SECONDS',60); define('ARRAY_A','ARRAY_A');
function add_action(...$a) {} function add_filter(...$a) {}
function get_option($k,$d=[]) {return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a) {if($GLOBALS['fail_option']??false)return false;$GLOBALS['options'][$k]=$v;return true;}
function bon_get_option($k,$d='') {return $GLOBALS['options']['bon_settings'][$k]??$d;}
function bon_get_enabled_platforms(){return ['bale'];}
function wp_verify_nonce(...$a){return true;} function current_user_can(...$a){return true;}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_textarea_field($s){return trim(strip_tags($s));}
function wp_kses_post($s){return strip_tags($s);}
function __($s,...$a){return $s;}
function add_settings_error(...$a){$GLOBALS['notices'][]=$a;}
function current_time($type){return $type==='mysql'?'2026-11-04 12:00:00':1793793600;}
function wp_json_encode($v){return json_encode($v);}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type($id){return $GLOBALS['posts'][$id]->post_type??false;}
function get_post_meta($id,$key,...$a){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['meta'][$id][$key]=$v;return true;}
function get_the_title($id){return $GLOBALS['posts'][$id]->post_title;}
function get_permalink($id){return 'https://shop.test/post/'.$id;}
function wp_trim_words($s,...$a){return $s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function strip_shortcodes($s){return $s;}
class Bale_Order_Notifier_API {
    public static $calls=[];
    function __construct(...$a){}
    function send_message(...$a){self::$calls[]=$a;return true;}
}
class Bale_Order_Notifier_Commerce {
    static function enabled($k){return !empty($GLOBALS['commerce'][$k]);}
    static function public_product($p){return true;}
    static function bot_message($message){$GLOBALS['catalog_calls'][]=$message;return true;}
}
class Bale_Order_Notifier_Customer_Hooks {
    static function instance(){return new self();}
    function handle_phone_message(...$args){$GLOBALS['phone_calls'][]=$args;}
}
class DeepDB {
    public $prefix='wp_', $queue=[], $links=[], $logs=[], $fail_queue=false, $after_select=null, $after_insert=null, $seq=0;
    function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
    function get_var($q){
        if(strpos($q['sql'],'GET_LOCK')!==false || strpos($q['sql'],'RELEASE_LOCK')!==false)return 1;
        foreach($this->queue as $r)if($r->message_type===($q['args'][0]??null))return $r->id;
        return null;
    }
    function get_results($q,...$a){
        $sql=is_array($q)?$q['sql']:$q;
        if(strpos($sql,'bon_customer_links')!==false)return array_values($this->links);
        $rows=array_values(array_filter($this->queue,function($r)use($sql){return $r->status==='pending' || (strpos($sql,'dead-letter')!==false && $r->status==='dead-letter');}));
        // DB reads are snapshots, not references to later updates/deletes.
        $rows=array_map(function($r){return clone $r;},$rows);
        if(strpos($sql,'SELECT *')!==false && $this->after_select){$f=$this->after_select;$this->after_select=null;$f();}
        return $rows;
    }
    function insert($table,$data){
        if($table==='wp_bon_logs'){$this->logs[]=$data;return 1;}
        if($this->fail_queue)return false;
        $data['id']=++$this->seq;$this->queue[$data['id']]=(object)$data;
        if($this->after_insert){$f=$this->after_insert;$this->after_insert=null;$f();}
        return 1;
    }
    function update($table,$data,$where){
        if(!isset($this->queue[$where['id']]))return 0;
        foreach($data as $k=>$v)$this->queue[$where['id']]->$k=$v;
        return 1;
    }
    function delete($table,$where){
        if($table==='wp_bon_queue'){$had=isset($this->queue[$where['id']]);unset($this->queue[$where['id']]);return $had?1:0;}
        $n=0;foreach($this->links as $k=>$l)if($l['chat_id']===$where['chat_id']){unset($this->links[$k]);$n++;}return $n;
    }
    function query($q){return 1;}
}
$wpdb=new DeepDB();
foreach(['logger','queue','customer-store','settings','channels'] as $file)require __DIR__.'/../balika/includes/class-bon-'.$file.'.php';
$count=0;function reproduced($yes,$name){global $count;if(!$yes)throw new RuntimeException('NOT reproduced: '.$name);$count++;echo "REPRODUCED: $name\n";}
$queue=Bale_Order_Notifier_Queue::instance();
$wpdb->links=[['phone'=>'+989121234567','platform'=>'bale','chat_id'=>'7']];
Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>'7','text'=>'Private fixture'],101,'customer_order');
$wpdb->after_select=function(){Bale_Order_Notifier_Customer_Store::unlink_chat('bale','7');};
$queue->process_queue();
reproduced(!$wpdb->queue && !$wpdb->links && count(Bale_Order_Notifier_API::$calls)===1,'D01: selected message sends even after unlink removes its DB row and binding');
// Dispatcher inserts outbox while job is scheduled; another worker can read before state update.
$wpdb->queue=[];Bale_Order_Notifier_API::$calls=[];
$commerce=['channels'=>true];$options['bon_channels']=['fixture'=>['destination'=>'@fixture','enabled'=>true]];
$posts=[1=>(object)['ID'=>1,'post_type'=>'post','post_status'=>'publish','post_password'=>'','post_title'=>'Fixture','post_excerpt'=>'Body','post_content'=>'Body'],20=>(object)['ID'=>20,'post_type'=>'bon_channel_job']];
$meta[20]=['_bon_state'=>'scheduled','_bon_source'=>1,'_bon_channel'=>'fixture','_bon_destination'=>'@fixture','_bon_publish_at'=>1];
$wpdb->after_insert=function()use($queue){$queue->process_queue();};
(new Bale_Order_Notifier_Channels())->dispatch(20);
reproduced($meta[20]['_bon_state']==='queued' && reset($wpdb->queue)->status==='paused' && !Bale_Order_Notifier_API::$calls,'D02: dispatcher/worker interleaving leaves an authorized new channel job paused');
// A typical token shape is masked standalone but not when immediately prefixed by "bot" in a URL.
$token='123456:'.str_repeat('a',30);$wpdb->logs=[];
Bale_Order_Notifier_Logger::log('error','HTTP error at https://tapi.bale.ai/bot'.$token.'/sendMessage');
reproduced(strpos(end($wpdb->logs)['message'],$token)!==false,'D03: token inside API URL survives logger redaction');
Bale_Order_Notifier_Logger::log('error','Token '.$token);
reproduced(strpos(end($wpdb->logs)['message'],$token)===false,'D03 control: same token IS masked when standalone, isolating URL-prefix problem');
$settings=Bale_Order_Notifier_Settings::instance();
$original="Customer's order";
$_POST=['bon_settings_nonce'=>'fixture','bon_action'=>'save_customers','template_customer_order'=>addslashes($original)];
$settings->handle_form_submit();
reproduced($options['bon_settings']['template_customer_order']!==$original && strpos($options['bon_settings']['template_customer_order'],"\\'")!==false,'D04: WP-style slashed POST text is persisted with extra backslash');
$fail_option=true;$notices=[];
$_POST=['bon_settings_nonce'=>'fixture','bon_action'=>'save_platforms','bale_bot_token'=>'NEW-NONSECRET-FIXTURE'];
$settings->handle_form_submit();
reproduced(($options['bon_settings']['bale_bot_token']??'')!=='NEW-NONSECRET-FIXTURE' && end($notices)[3]==='success','D05: settings report successful save after update_option failure');
$fail_option=false;$wpdb->queue=[];$wpdb->links=[['phone'=>'first','platform'=>'bale','chat_id'=>'7'],['phone'=>'second','platform'=>'bale','chat_id'=>'7']];
$_POST=['bon_settings_nonce'=>'fixture','bon_action'=>'send_mass_message','broadcast_message'=>'Fixture'];
$wpdb->fail_queue=true;$notices=[];$settings->handle_form_submit();
reproduced(!$wpdb->queue && end($notices)[3]==='success' && strpos(end($notices)[2],'2')!==false,'B01 strengthened: broadcast reports 2 queued recipients when every insert fails');
$wpdb->fail_queue=false;$settings->handle_form_submit();
reproduced(count($wpdb->queue)===2 && count(array_unique(array_map(function($r){return json_decode($r->payload,true)['chat_id'];},$wpdb->queue)))===1,'B01 strengthened: two phone bindings queue duplicate broadcast to same chat');
$settings->handle_form_submit();
reproduced(count($wpdb->queue)===4,'B01 strengthened: replaying same broadcast POST queues the same messages again');
require __DIR__.'/../balika/includes/class-bon-webhook.php';
$phone_calls=[];$catalog_calls=[];$commerce['bot']=true;
$method=new ReflectionMethod(Bale_Order_Notifier_Webhook::class,'handle_incoming_message');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',['chat'=>['id'=>7],'from'=>['id'=>7],'text'=>'/search 09121234567']);
reproduced(count($phone_calls)===1 && !$catalog_calls,'D06: explicit catalog search command is intercepted as a phone lookup');
echo "$count audit observations (defect reproductions and diagnostic control, not safety regression checks).\n";
