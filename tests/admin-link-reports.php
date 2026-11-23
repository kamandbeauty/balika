<?php
define('ABSPATH',__DIR__);define('MINUTE_IN_SECONDS',60);
function add_action(...$a){}function add_filter(...$a){}
function get_current_user_id(){return 1;}function user_can(...$a){return $GLOBALS['allowed'];}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){if($GLOBALS['fail_save'])return false;$GLOBALS['options'][$k]=$v;return true;}
function bon_get_option($k,$d=''){return $GLOBALS['options']['bon_settings'][$k]??$d;}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;return true;}function delete_transient($k){unset($GLOBALS['transients'][$k]);return true;}
function get_user_meta($u,$k,...$a){return $GLOBALS['meta'][$u][$k]??'';}function update_user_meta($u,$k,$v){$GLOBALS['meta'][$u][$k]=$v;}function delete_user_meta($u,$k){unset($GLOBALS['meta'][$u][$k]);}
function sanitize_text_field($s){return trim(strip_tags($s));}function wp_generate_password($len,...$a){return str_repeat('a',$len);}
function add_settings_error(...$a){$GLOBALS['notices'][]=$a;}
function wp_timezone(){return new DateTimeZone($GLOBALS['tz']);}
function wp_next_scheduled($k){return $GLOBALS['events'][$k]??false;}function wp_clear_scheduled_hook($k){unset($GLOBALS['events'][$k]);}function wp_schedule_single_event($time,$k){$GLOBALS['events'][$k]=$time;return true;}
function bon_get_enabled_platforms(){return ['bale'];}
function admin_url($s){return 'https://example.test/'.$s;}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class WP_Error{}function is_wp_error($v){return $v instanceof WP_Error;}
class DB {public $prefix='test_', $busy=false;function prepare($s,...$a){return $s;}function get_var($s){return $this->busy?0:1;}}
class Bale_Order_Notifier_API {static $messages=[];function __construct(...$a){}function answer_callback_query(...$a){}function send_message(...$a){self::$messages[]=$a;return true;}}
class Bale_Order_Notifier_Dispatcher {static $messages=[];static function broadcast(...$a){self::$messages[]=$a;return ['bale:42'=>1];}}
require __DIR__.'/../balika/includes/class-bon-admin-link.php';
require __DIR__.'/../balika/includes/class-bon-reports.php';
require __DIR__.'/../balika/includes/class-bon-cron.php';
function wc_get_orders($args){$GLOBALS['queries'][]=$args;return [];}
function wc_price($v){return (string)$v;}function wp_strip_all_tags($v){return strip_tags($v);}function wp_date($f,$t=null){return date($f,$t??time());}function get_woocommerce_currency(){return 'IRR';}function number_format_i18n($n,...$a){return (string)$n;}
$n=0;function check($ok,$s){global $n;if(!$ok){fwrite(STDERR,"FAIL: $s\n");exit(1);}echo "PASS: $s\n";$n++;}
$options=[];$transients=[];$meta=[];$events=[];$notices=[];$allowed=true;$fail_save=false;$wpdb=new DB;$tz='Asia/Tehran';
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'begin_admin_link']);$token=$meta[1]['bon_admin_pair_1'];
check(strlen($token)===32,'pairing issues short lived random-token request');
$message=['text'=>'/start admin_'.$token,'chat'=>['id'=>'42','type'=>'private'],'from'=>['id'=>'42','first_name'=>'Manager']];
Bale_Order_Notifier_Admin_Link::incoming($message);
check(!isset($options['bon_settings']) && get_transient(Bale_Order_Notifier_Admin_Link::key($token))['chat']==='42','Bale claim alone grants no authority');
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'approve_admin_link','pair_chat'=>'43','confirm_pair'=>1]);
check(!isset($options['bon_settings']),'approval must match displayed candidate');
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'approve_admin_link','pair_chat'=>'42','confirm_pair'=>1]);
check($options['bon_settings']['bale_admin_user_ids']===['42'] && $options['bon_settings']['bale_chat_ids']===['42'],'approved account becomes authorized recipient');
check(!get_transient(Bale_Order_Notifier_Admin_Link::key($token)),'approved request consumed');
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'begin_admin_link']);$allowed=false;Bale_Order_Notifier_Admin_Link::incoming($message);
check(empty(get_transient(Bale_Order_Notifier_Admin_Link::key($token))['chat']),'revoked WordPress permission rejects claim');$allowed=true;
$message['chat']['id']='-123';Bale_Order_Notifier_Admin_Link::incoming($message);
check(empty(get_transient(Bale_Order_Notifier_Admin_Link::key($token))['chat']),'group cannot claim admin identity');
$bounds=Bale_Order_Notifier_Cron::closed_bounds('2026-10-27');
check((new DateTimeImmutable('@'.$bounds[0]))->setTimezone(wp_timezone())->format('H:i:s')==='00:00:00' && (new DateTimeImmutable('@'.$bounds[1]))->setTimezone(wp_timezone())->format('H:i:s')==='23:59:59','full day covers final second in site timezone');
$tz='Europe/Stockholm';$bounds=Bale_Order_Notifier_Cron::closed_bounds('2026-10-25');check($bounds[1]-$bounds[0]+1===25*3600,'DST day covers 25 hours instead of fixed seconds');$tz='Asia/Tehran';
$events['bon_daily_report_event']=123;Bale_Order_Notifier_Cron::ensure_closed_day();check(!isset($events['bon_daily_report_event']) && isset($events['bon_closed_day_report_event']),'old recurring schedule retired');
$options['bon_closed_day_pending']='2026-01-02';$options['bon_settings']['daily_report_enabled']=true;
Bale_Order_Notifier_Cron::instance()->send_daily_report();
check(strpos(Bale_Order_Notifier_Dispatcher::$messages[0][0],'2026-01-02')!==false,'late cron reports anchored day not execution day');
check($options['bon_closed_day_pending']==='2026-01-03','daily cursor advances one day for delayed catch-up');
$before=count(Bale_Order_Notifier_Dispatcher::$messages);$fail_save=true;Bale_Order_Notifier_Cron::instance()->send_daily_report();check(count(Bale_Order_Notifier_Dispatcher::$messages)===$before,'cursor write failure prevents duplicate-risk dispatch');$fail_save=false;
$options['bon_settings']['daily_report_enabled']=false;Bale_Order_Notifier_Cron::instance()->send_daily_report();check(count(Bale_Order_Notifier_Dispatcher::$messages)===$before,'disabled automatic daily report does not dispatch');
require __DIR__.'/../balika/includes/class-bon-webhook.php';
$method=new ReflectionMethod(Bale_Order_Notifier_Webhook::class,'handle_callback_query');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$callback=['data'=>'bon_toggle:daily_report_enabled:1','id'=>'cb','from'=>['id'=>'42'],'message'=>['chat'=>['id'=>'42']]];
$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',$callback);
check($options['bon_settings']['daily_report_enabled']===true,'authorized private callback enables report');
$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',$callback);
check($options['bon_settings']['daily_report_enabled']===true,'replayed enable callback does not toggle back');
$callback['data']='bon_toggle:daily_report_enabled:0';$callback['from']['id']='43';
$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',$callback);
check($options['bon_settings']['daily_report_enabled']===true,'unauthorized sender cannot change reports');
$callback['from']['id']='42';$callback['message']['chat']['id']='-123';
$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',$callback);
check($options['bon_settings']['daily_report_enabled']===true,'group callback cannot change reports');
$callback['message']['chat']['id']='42';$fail_save=true;$method->invoke(Bale_Order_Notifier_Webhook::instance(),'bale',$callback);
check($options['bon_settings']['daily_report_enabled']===true,'failed option write leaves report enabled');
echo "$n checks passed\n";
