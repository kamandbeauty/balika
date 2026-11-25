<?php
define('ABSPATH',__DIR__);
function add_action(...$a){}
function __($s,...$a){return $s;}
function wp_verify_nonce(...$a){return $GLOBALS['nonce'];}
function current_user_can(...$a){return $GLOBALS['cap'];}
function get_current_user_id(){return 7;}
function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):stripslashes($v);}
function sanitize_text_field($v){return trim(strip_tags($v));}
function sanitize_textarea_field($v){return trim(strip_tags($v));}
function wp_kses_post($v){return $v;}
function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v){if($GLOBALS['fail_save'])return false;$GLOBALS['options'][$k]=$v;return true;}
function add_settings_error($a,$b,$text,$type){$GLOBALS['notices'][]=[$text,$type];}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function delete_transient($k){if($GLOBALS['fail_delete'])return false;unset($GLOBALS['transients'][$k]);return true;}
class DB {public $prefix='test_', $busy=false,$released=0;function prepare($s,...$a){return $s;}function get_var($q){if(strpos($q,'RELEASE_LOCK')!==false){$this->released++;return 1;}return $this->busy?0:1;}}
class Bale_Order_Notifier_Customer_Store {static function get_all_links(){return [['platform'=>'bale','chat_id'=>'12'],['platform'=>'bale','chat_id'=>'12'],['platform'=>'bale','chat_id'=>'13']];}}
class Bale_Order_Notifier_Queue {static $calls=[], $fail=[];static function enqueue($p,$m,$payload,...$a){self::$calls[]=$payload;return !in_array($payload['chat_id'],self::$fail,true);}}
require __DIR__.'/../balika/includes/class-bon-settings.php';
$n=0;function check($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";$n++;}
$options=[];$notices=[];$transients=[];$fail_save=false;$fail_delete=false;$nonce=true;$cap=true;$wpdb=new DB;$settings=Bale_Order_Notifier_Settings::instance();
$_POST=['bon_settings_nonce'=>'ok','bon_action'=>'save_customers','template_customer_order'=>"Customer\\'s order"];
$settings->handle_form_submit();check($options['bon_settings']['template_customer_order']==="Customer's order",'settings unslash exactly once');check(end($notices)[1]==='success','verified write reports success');
$settings->handle_form_submit();check(strpos(end($notices)[0],'بدون تغییر')!==false,'unchanged settings reported correctly');
$fail_save=true;$_POST['template_customer_order']='changed';$settings->handle_form_submit();check(end($notices)[1]==='error' && $options['bon_settings']['template_customer_order']==="Customer's order",'failed write never reports success');$fail_save=false;
$_POST['template_customer_order']=[];$settings->handle_form_submit();check(end($notices)[1]==='error','array input rejected safely');
function form(){global $transients; $token=str_repeat('a',32);$transients['bon_broadcast_7_'.hash('sha256',$token)]=true;$_POST=['bon_settings_nonce'=>'ok','bon_action'=>'send_mass_message','broadcast_message'=>'Hello','broadcast_once'=>$token];}
form();$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===2,'duplicate links enqueue once per destination');check(strpos(end($notices)[0],'2 پیام در صف')!==false,'counts successful enqueues');
$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===2 && end($notices)[1]==='warning','same form cannot be replayed');
form();Bale_Order_Notifier_Queue::$fail=['13'];$settings->handle_form_submit();check(end($notices)[1]==='warning' && strpos(end($notices)[0],'1 پیام در صف')!==false,'partial enqueue failure counted accurately');
form();Bale_Order_Notifier_Queue::$fail=['12','13'];$settings->handle_form_submit();check(end($notices)[1]==='error' && strpos(end($notices)[0],'0 پیام در صف')!==false,'total enqueue failure is not success');
form();$before=count(Bale_Order_Notifier_Queue::$calls);$wpdb->busy=true;$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===$before,'concurrent operation fails closed on lock contention');$wpdb->busy=false;
$fail_delete=true;$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===$before && end($notices)[1]==='error','failed token consumption prevents sending');$fail_delete=false;
form();$_POST['broadcast_once']=str_repeat('b',32);$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===$before,'unknown token rejected');
form();$_POST['broadcast_message']=' ';$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===$before,'empty message rejected');
form();$nonce=false;$settings->handle_form_submit();$nonce=true;$cap=false;$settings->handle_form_submit();check(count(Bale_Order_Notifier_Queue::$calls)===$before,'nonce and capability checks retained');
echo "$n checks passed\n";
