<?php
// Standalone regression tests for human support: fake WordPress/DB and no network.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60);
$options=['bon_support_contact'=>['mode'=>'inbox','url'=>'']];$posts=[];$meta=[];$transients=[];$allowed=true;$nonce=true;$transport=true;$fail_post=false;
function add_action(...$a){} function add_filter(...$a){}
function register_post_type($type,$args){$GLOBALS['types'][$type]=$args;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;return true;}
function bon_get_enabled_platforms(){return $GLOBALS['transport']?['bale']:[];}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);}
function wp_using_ext_object_cache(){return false;}
function sanitize_textarea_field($s){return trim(strip_tags($s));}
function sanitize_key($s){return preg_replace('/[^a-z_]/','',$s);}
function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):(is_string($v)?stripslashes($v):$v);}
function wp_slash($v){return is_array($v)?array_map('wp_slash',$v):(is_string($v)?addslashes($v):$v);}
function get_current_user_id(){return 42;}
function current_user_can($cap){return $GLOBALS['allowed'];}
class StopRequest extends Exception{}
function wp_die(...$a){throw new StopRequest('forbidden');}
function check_admin_referer($a){if(!$GLOBALS['nonce'])throw new StopRequest('nonce');}
function wp_safe_redirect($url){throw new StopRequest('redirect');}
function admin_url($p){return 'https://shop.test/wp-admin/'.$p;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v){return json_encode($v);}
function current_time($t){return $t==='mysql'?'2026-10-28 12:00:00':time();}
function wp_generate_password($n,...$a){return substr(bin2hex(random_bytes($n)),0,$n);}
class WP_Error{private $message;function __construct($code,$message){$this->message=$message;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type($id){return get_post($id)->post_type??false;}
function get_post_meta($id,$k,...$a){return $GLOBALS['meta'][$id][$k]??'';}
function update_post_meta($id,$k,$v){$GLOBALS['meta'][$id][$k]=$v;return true;}
function wp_delete_post($id,$force){unset($GLOBALS['posts'][$id],$GLOBALS['meta'][$id]);}
function wp_insert_post($data,$error=false){
 if($GLOBALS['fail_post'])return new WP_Error('db','fixture failure');
 $data=wp_unslash($data);$id=++$GLOBALS['seq'];$GLOBALS['meta'][$id]=($GLOBALS['fail_meta']??false)?[]:($data['meta_input']??[]);unset($data['meta_input']);
 $GLOBALS['posts'][$id]=(object)array_merge(['ID'=>$id,'post_parent'=>0,'post_author'=>0,'post_content'=>'','post_date'=>'2026-10-28 12:00:00'],$data);return $id;
}
function get_posts($args){
 $found=array_filter($GLOBALS['posts'],function($p)use($args){
  foreach(['post_type','post_status','post_parent'] as $k)if(isset($args[$k])&&$p->$k!=$args[$k])return false;
  $filters=$args['meta_query']??[];if(isset($args['meta_key']))$filters[]=['key'=>$args['meta_key'],'value'=>$args['meta_value']];
  foreach($filters as $f){$v=get_post_meta($p->ID,$f['key'],true);if(($f['compare']??'=')==='<'){if((int)$v>=(int)$f['value'])return false;}elseif((string)$v!==(string)$f['value'])return false;}
  return true;
 });krsort($found);$found=array_slice(array_values($found),0,$args['posts_per_page']??20);
 return ($args['fields']??'')==='ids'?array_column($found,'ID'):$found;
}
class Bale_Order_Notifier_API{function send_reply(...$a){return $this->send_message(...$a);}static $calls=[];function __construct(...$a){}function send_message(...$a){self::$calls[]=$a;return true;}}
class Bale_Order_Notifier_Logger{static function log(...$a){}}
class SupportDB{
 public $prefix='wp_',$posts='wp_posts',$options='wp_options',$rows=[],$held=[],$deny='',$seq=0,$fail=false;
 function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
 function get_var($q){$k=$q['args'][0]??'';if(strpos($q['sql'],'RELEASE_LOCK')!==false){unset($this->held[$k]);return 1;}if($k===$this->deny||isset($this->held[$k]))return 0;$this->held[$k]=true;return 1;}
 function insert($t,$data){if($this->fail)return false;$data['id']=++$this->seq;$this->rows[$data['id']]=(object)$data;return 1;}
 function update($t,$data,$where){if($this->fail)return false;if(!isset($this->rows[$where['id']]))return 0;foreach($data as $k=>$v)$this->rows[$where['id']]->$k=$v;return 1;}
 function get_row($q){foreach(array_reverse($this->rows) as $row)if($row->message_type===$q['args'][0])return clone $row;return null;}
 function get_results($q){return array_map(function($r){return clone $r;},array_values(array_filter($this->rows,function($r){return $r->status==='pending';})));}
 function delete($t,$where){$key=substr($where['option_name'],11);if(!isset($GLOBALS['transients'][$key]))return 0;unset($GLOBALS['transients'][$key]);return 1;}
 function query($q){if(strpos($q['sql'],'UPDATE')!==0)return 0;foreach($this->rows as $row){$p=get_post((int)substr($row->message_type,strpos($row->message_type,'support_admin_')===0?14:8));if($p&&(int)$p->post_parent===$q['args'][1]&&in_array($row->status,['pending','paused','dead-letter'],true))$row->status='cancelled';}return 1;}
}
$seq=0;$wpdb=new SupportDB();require __DIR__.'/../balika/includes/class-bon-queue.php';require __DIR__.'/../balika/includes/class-bon-support.php';
$s=new Bale_Order_Notifier_Support();$class=Bale_Order_Notifier_Support::class;$n=0;
function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";$n++;}
function incoming($text,$chat=7,$mid=1){return Bale_Order_Notifier_Support::incoming(['chat'=>['id'=>$chat,'type'=>'private'],'from'=>['id'=>$chat],'text'=>$text,'message_id'=>$mid]);}
function submit($input){global $s;$_POST=$input;try{$s->admin_action();}catch(StopRequest $e){return $e->getMessage();}throw new RuntimeException('missing redirect');}
function once($thread){$token=wp_generate_password(32);set_transient('bon_support_once_42_'.hash('sha256',$token),$thread,3600);return $token;}
function rows_of($type){return array_values(array_filter($GLOBALS['posts'],function($p)use($type){return $p->post_type===$type;}));}
check(!$class::enabled(),'support opt-in defaults off');$s->register_types();check(!$types[$class::MESSAGE]['public']&&!$types[$class::MESSAGE]['show_in_rest'],'messages are not public or exposed through default REST');
incoming('/support');check(!$posts,'disabled module creates no conversation');
$options['bon_support_enabled']=true;
check(!$class::private_chat(['chat'=>['id'=>-1],'from'=>['id'=>7]]),'group identity rejected');
check(!$class::private_chat(['chat'=>['id'=>7],'from'=>['id'=>8]]),'actor mismatch rejected');
check(!$class::private_chat(['chat'=>['id'=>[]],'from'=>['id'=>[]]]),'malformed identity rejected');
check($class::text(str_repeat('س',2001))===false && $class::text([])===false,'oversized or malformed text rejected');
check(incoming('ordinary message')===false && !$posts,'no relay before explicit support start');
incoming('/support');$thread=rows_of($class::THREAD)[0]->ID;
check(get_post_meta($thread,'_bon_chat',true)==='7','thread identity bound to private chat, not phone');
incoming('/support');check(count(rows_of($class::THREAD))===1,'repeated start reuses open thread');
incoming("Customer's question",7,2);$in=rows_of($class::MESSAGE);
check(count($in)===1&&$in[0]->post_content==="Customer's question",'incoming text stored with quotes preserved');
incoming("Customer's question",7,2);check(count(rows_of($class::MESSAGE))===1,'duplicate Bale message_id does not duplicate message');
incoming('09121234567',7,3);check(count(rows_of($class::MESSAGE))===2,'numeric text stays in human conversation');
check(incoming('/orders',7,4)===false,'order command remains available outside relay');
$class::incoming(['chat'=>['id'=>7],'from'=>['id'=>7],'message_id'=>5,'photo'=>[['id'=>'fixture']]]);check(count(rows_of($class::MESSAGE))===2,'unsupported attachment is not stored as a fake message');
for($i=0;$i<10;$i++)incoming('rate fixture',7,100+$i);
check(count(rows_of($class::MESSAGE))===10,'per-chat rate bound enforced');
$allowed=false;check(submit(['operation'=>'settings'])==='forbidden','unauthorized admin mutation rejected');$allowed=true;$nonce=false;
check(submit(['operation'=>'settings'])==='nonce','missing or invalid nonce rejected');$nonce=true;
$token=once($thread);$form=['operation'=>'reply','thread'=>$thread,'once'=>$token,'body'=>"Operator's reply"];
submit($form);$out=array_values(array_filter(rows_of($class::MESSAGE),function($p){return get_post_meta($p->ID,'_bon_direction',true)==='out';}))[0];
check(count($wpdb->rows)===1 && json_decode(reset($wpdb->rows)->payload,true)['chat_id']==='7','operator reply queued only to thread chat');
check($out->post_author===42 && $out->post_content==="Operator's reply",'reply preserves text and operator author');
submit($form);check(count($wpdb->rows)===1,'single-use reply form blocks POST replay');
submit(['operation'=>'reply','thread'=>$thread,'once'=>once($thread+999),'body'=>'Cross ticket']);check(count($wpdb->rows)===1,'reply token cannot be moved to another thread');
$transport=false;Bale_Order_Notifier_Queue::instance()->process_queue();check(reset($wpdb->rows)->status==='paused','worker pauses support reply when Bale is disabled');
$transport=true;$options['bon_support_enabled']=false;
check(!$class::can_deliver($out->ID),'support module switch checked at delivery');
$count=count(rows_of($class::MESSAGE));incoming('not stored',7,300);check(count(rows_of($class::MESSAGE))===$count,'disabled active support does not silently store more messages');
$options['bon_support_enabled']=true;
submit(['operation'=>'retry','thread'=>$thread,'message'=>$out->ID]);check(reset($wpdb->rows)->status==='pending','explicit retry reactivates paused reply');
Bale_Order_Notifier_Queue::instance()->process_queue();check(reset($wpdb->rows)->status==='sent','queue processes permitted support reply');
submit(['operation'=>'retry','thread'=>$thread,'message'=>$out->ID]);check(count($wpdb->rows)===1 && reset($wpdb->rows)->status==='sent','successful reply is not resent by retry');
$wpdb->fail=true;submit(['operation'=>'reply','thread'=>$thread,'once'=>once($thread),'body'=>'Recoverable response']);$failed=end($posts);check(get_post_meta($failed->ID,'_bon_direction',true)==='out' && !get_post_meta($failed->ID,'_bon_enqueued',true),'queue failure retains reply for explicit recovery');
$wpdb->fail=false;submit(['operation'=>'retry','thread'=>$thread,'message'=>$failed->ID]);check(count($wpdb->rows)===2,'failed enqueue recovered without creating another message');
$wpdb->deny='bon_queue_'.md5('wp_bon_queue');check(is_wp_error($class::close($thread))&&get_post_meta($thread,'_bon_state',true)==='open','close refuses to claim success while worker is busy');$wpdb->deny='';
check($class::close($thread)===true,'close succeeds with locks');
check(end($wpdb->rows)->status==='cancelled' && reset($wpdb->rows)->status==='sent','close cancels only unsent replies');
check(!$class::can_deliver($failed->ID),'closed conversation cannot deliver');
submit(['operation'=>'reply','thread'=>$thread,'once'=>once($thread),'body'=>'Closed response']);check(count($wpdb->rows)===2,'closed thread rejects new operator reply');
incoming('/support',7,400);$new=end($posts)->ID;check($new!==$thread && get_post_type($new)===$class::THREAD,'new support request after close gets new thread');
$count=count(rows_of($class::MESSAGE));incoming('new',7,2);check(count(rows_of($class::MESSAGE))===$count,'old inbound message replay is deduplicated across threads');
check(incoming('/stop')===false && get_post_meta($new,'_bon_state',true)==='closed','stop closes support then permits existing unlink handler');
check(!$wpdb->held,'all support and queue locks released');
$meta[$thread]['_bon_closed_at']=time()-31*DAY_IN_SECONDS;$s->cleanup();check(!get_post($thread)&&!get_post($out->ID),'closed thread and its messages expire after retention');
check($types[$class::MESSAGE]['capabilities']['read_post']==='manage_woocommerce' && !$types[$class::MESSAGE]['map_meta_cap'],'direct CPT access requires operator permission, not generic editor role');
$fail_meta=true;$before=count($posts);incoming('/support',9);check(count($posts)===$before,'partial metadata write is rejected and incomplete post removed');$fail_meta=false;
$fail_post=true;incoming('/support',9);check(count($posts)===$before,'failed post creation does not claim an open conversation');$fail_post=false;
function bon_get_option($key,$default=''){return $GLOBALS['options']['bon_settings'][$key]??$default;}
class Bale_Order_Notifier_Customer_Store {static $lookups=0;static function looks_like_phone($text){self::$lookups++;return false;}}
require __DIR__.'/../balika/includes/class-bon-webhook.php';
$method=new ReflectionMethod(Bale_Order_Notifier_Webhook::class,'handle_incoming_message');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$webhook=Bale_Order_Notifier_Webhook::instance();
$method->invoke($webhook,'bale',['chat'=>['id'=>8],'from'=>['id'=>8],'text'=>'/support','message_id'=>1000]);
$method->invoke($webhook,'bale',['chat'=>['id'=>8],'from'=>['id'=>8],'text'=>'09121234567','message_id'=>1001]);
check(Bale_Order_Notifier_Customer_Store::$lookups===0 && end($posts)->post_content==='09121234567','actual webhook routes active support BEFORE phone/order lookup');
$before=count($posts);$method->invoke($webhook,'bale',['chat'=>['id'=>-99],'from'=>['id'=>8],'text'=>'/support','message_id'=>1002]);check(count($posts)===$before,'group support command never creates private conversation');
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}
function checked($a,$b,$echo=false){return $a===$b?'checked':'';}
function wp_nonce_field($a){echo '<input name="_wpnonce" value="fixture">';}
function submit_button($label,...$a){echo '<button>'.esc_html($label).'</button>';}
function wp_kses_post($s){return $s;}
function paginate_links($a){return '';}
class WP_Query {public $posts,$max_num_pages=1;function __construct($args){$this->posts=get_posts($args);}}
$active_thread=end($posts)->post_parent;$_GET=['thread'=>$active_thread];ob_start();$s->page();$html=ob_get_clean();
check(strpos($html,'name="operation" value="reply"')!==false && strpos($html,'name="once"')!==false && strpos($html,'name="_wpnonce"')!==false,'inbox reply form has handler, nonce and one-use token');
check(strpos($html,'09121234567')!==false && strpos($html,'name="operation" value="close"')!==false,'inbox renders customer text and close operation');
$allowed=false;try{$s->page();check(false,'unauthorized page should fail');}catch(StopRequest $e){check($e->getMessage()==='forbidden','unauthorized inbox rendering denied');}$allowed=true;
$wpdb->deny='bon_queue_'.md5('wp_bon_queue');submit(['operation'=>'settings']);check($class::enabled(),'module switch refuses change while worker is busy');$wpdb->deny='';
submit(['operation'=>'settings']);check(!$class::enabled()&&!$wpdb->held,'module switch saves off and releases worker lock');
$options['bon_support_enabled']=true;$options['bon_support_contact']['enabled']=true;
$options['bon_settings']=['bale_admin_user_ids'=>['42',' 42','-22','@channel',['bad'],'0042','0','43'],'bale_chat_ids'=>['42','-99','44']];
check($class::admin_recipients()===['42','43','44'],'private admin destinations validated, deduplicated and group/channel IDs excluded');
function admin_rows(){global $wpdb;return array_values(array_filter($wpdb->rows,function($r){return strpos($r->message_type,'support_admin_')===0;}));}
incoming('/support',88,2000);$admin_thread=end($posts)->ID;
incoming('Private customer question',88,2001);$admin_mid=end($posts)->ID;$alerts=admin_rows();
check(count($alerts)===3,'inbound support text automatically queued to private administrators');
$payload=json_decode($alerts[0]->payload,true);
check(strpos($payload['text'],'Private customer question')!==false && strpos($payload['text'],'#'.$admin_thread)!==false,'administrator receives actual text and thread identifier');
$markup=json_decode($payload['reply_markup'],true);
check(strpos($markup['inline_keyboard'][0][0]['url'],'thread='.$admin_thread)!==false,'admin notification links to correct protected inbox thread');
incoming('Private customer question',88,2001);check(count(admin_rows())===3,'repeated inbound update cannot duplicate administrator notification');
check(!$class::can_deliver_admin($alerts[0]->message_type,['chat_id'=>'999']),'delivery rejects mismatched payload recipient');
$options['bon_settings']['bale_admin_user_ids']=['42'];
Bale_Order_Notifier_Queue::instance()->process_queue();$states=[];foreach(admin_rows() as $r)$states[json_decode($r->payload,true)['chat_id']]=$r->status;
check($states['42']==='sent'&&$states['44']==='sent'&&$states['43']==='paused','worker rechecks current recipients and pauses removed admin');
$wpdb->fail=true;incoming('Queue failure fixture',88,2002);$failed_admin_mid=end($posts)->ID;
check(count(admin_rows())===3 && strpos(get_post_meta($failed_admin_mid,'_bon_admin_notice',true),'ناموفق')!==false,'notification enqueue failure preserves inbox text and records actionable notice');
$wpdb->fail=false;submit(['operation'=>'notify_admin','thread'=>$admin_thread,'message'=>$failed_admin_mid]);
check(count(admin_rows())===5,'operator can recover failed administrator notification');
submit(['operation'=>'notify_admin','thread'=>$admin_thread,'message'=>$failed_admin_mid]);check(count(admin_rows())===5,'repeated administrator notification retry does not create duplicates');
$class::close($admin_thread);$pending=array_filter(admin_rows(),function($r){return $r->status==='pending';});
check(!$pending && !$class::can_deliver_admin($alerts[0]->message_type,$payload),'closing conversation cancels unsent admin copies and blocks further delivery');
incoming('/support',42,3000);$before=count(admin_rows());incoming('Manager testing as customer',42,3001);
$latest=end($posts)->ID;$new_alerts=array_filter(admin_rows(),function($r)use($latest){return strpos($r->message_type,'support_admin_'.$latest.'_')===0;});
check(count($new_alerts)===1 && json_decode(reset($new_alerts)->payload,true)['chat_id']==='44','sender is excluded from their own administrator notification');
check(!$wpdb->held,'notification and conversation locks released');
check($class::contact_url('@ShopSupport')==='https://ble.ir/ShopSupport','admin username normalizes to canonical Bale URL');
check($class::contact_url('https://ble.ir/ShopSupport/')==='https://ble.ir/ShopSupport','full Bale link normalized');
foreach(['https://evil.test/user','https://ble.ir.evil.test/user','https://ble.ir/user?redirect=evil','javascript:alert(1)',[], 'https://name@ble.ir/user'] as $bad)check($class::contact_url($bad)===false,'unsafe or malformed support destination rejected');
$previous=$class::contact();submit(['operation'=>'settings','enabled'=>1,'mode'=>'direct','contact_url'=>'https://evil.test/user']);check($class::contact()===$previous,'invalid destination does not overwrite configuration');
submit(['operation'=>'settings','enabled'=>1,'mode'=>'direct','contact_url'=>'']);check($class::contact()===$previous,'enabled direct mode requires configured account');
submit(['operation'=>'settings','enabled'=>1,'mode'=>'direct','contact_url'=>'@ShopSupport']);
check($class::contact()===['enabled'=>true,'mode'=>'direct','url'=>'https://ble.ir/ShopSupport'],'panel saves direct contact atomically');
$before=count($posts);$queued=count($wpdb->rows);$calls=count(Bale_Order_Notifier_API::$calls);incoming('/support',99,5000);
$call=end(Bale_Order_Notifier_API::$calls);
check(count($posts)===$before && count($wpdb->rows)===$queued,'direct support creates no ticket, stored text or forwarding queue');
check(count(Bale_Order_Notifier_API::$calls)===$calls+1 && $call[2][0][0]['url']==='https://ble.ir/ShopSupport','support command returns direct chat button to saved account');
check(!$class::ready(),'direct mode disables old inbox delivery paths');
Bale_Order_Notifier_Queue::instance()->process_queue();$pending=array_filter($wpdb->rows,function($r){return $r->status==='pending';});check(!$pending,'old pending support copies pause after switching to direct mode');
$transport=false;$calls=count(Bale_Order_Notifier_API::$calls);incoming('/support',99,5001);check(count(Bale_Order_Notifier_API::$calls)===$calls,'global Bale switch respected for direct contact');$transport=true;
$_GET=[];ob_start();$s->page();$html=ob_get_clean();check(strpos($html,'name="contact_url"')!==false && strpos($html,'https://ble.ir/ShopSupport')!==false,'saved direct account and editable field visible in admin panel');
unset($options['bon_support_contact']);check($class::contact()['mode']==='direct','unconfigured installations default to direct-contact mode');
echo "$n checks passed\n";
