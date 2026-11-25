<?php
define('ABSPATH',__DIR__);define('MINUTE_IN_SECONDS',60);
function add_action(...$a){}
function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function bon_get_option($k,$d=''){return $GLOBALS['options']['bon_settings'][$k]??$d;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s){return esc_html($s);}
function admin_url($s){return 'https://example.test/'.$s;}
function wp_date($f,$t){return date($f,$t);}
function wp_next_scheduled($h){return $GLOBALS['next'];}
class Bale_Order_Notifier_Queue {static function get_queue_stats(){return ['pending'=>2,'dead_letter'=>1,'sent'=>3];}}
class Bale_Order_Notifier_Support {static function contact(){return ['enabled'=>true,'mode'=>'direct','url'=>'https://ble.ir/example'];}}
require __DIR__.'/../balika/includes/class-bon-settings.php';
$n=0;function check($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";$n++;}
$options=[];$next=false;
check(Bale_Order_Notifier_Settings::connection_test_state()['time']===0,'no recorded test never implies healthy connection');
$options['bon_settings']['bale_bot_token']='fake-first';
$options['bon_connection_test']=['time'=>100,'ok'=>true,'fingerprint'=>Bale_Order_Notifier_Settings::connection_fingerprint()];
check(Bale_Order_Notifier_Settings::connection_test_state()['time']===100,'matching credential test recognized');
$options['bon_settings']['bale_bot_token']='fake-second';
check(Bale_Order_Notifier_Settings::connection_test_state()['time']===0,'changing credential invalidates prior connection result');
$options['bon_connection_test']['fingerprint']=Bale_Order_Notifier_Settings::connection_fingerprint();$options['bon_connection_test']['ok']=false;
check(strpos(Bale_Order_Notifier_Settings::connection_test_state()['label'],'ناموفق')!==false,'failed connection test shown truthfully');
$method=new ReflectionMethod(Bale_Order_Notifier_Settings::class,'render_overview_tab');if (PHP_VERSION_ID<80100) $method->setAccessible(true);
ob_start();$method->invoke(Bale_Order_Notifier_Settings::instance());$html=ob_get_clean();
check(strpos($html,'صف نیاز به بررسی دارد')!==false,'missing cron produces actionable warning');
check(strpos($html,'order_status=processing')!==false && strpos($html,'presence=missing')!==false,'daily shortcut uses processing and missing-code filters');
check(strpos($html,'fake-second')===false,'dashboard does not expose bot credential');
check(strpos($html,'https://ble.ir/example')!==false && strpos($html,'گفت‌وگوهای مستقیم')!==false,'direct support destination and limitation displayed');
$next=time()+300;$options['bon_queue_last_started']=time();
ob_start();$method->invoke(Bale_Order_Notifier_Settings::instance());$html=ob_get_clean();
check(strpos($html,'صف نیاز به بررسی دارد')===false && strpos($html,'شروع پردازش به معنی تکمیل موفق')!==false,'recent heartbeat not confused with completed processing');
echo "$n checks passed\n";
