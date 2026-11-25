<?php
define('ABSPATH',__DIR__);
function add_action(...$a){}function add_filter(...$a){}
function wp_timezone(){return new DateTimeZone($GLOBALS['zone']);}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){if($GLOBALS['fail'])return false;$GLOBALS['options'][$k]=$v;return true;}
function wp_next_scheduled($k){return $GLOBALS['events'][$k]??false;}
function wp_clear_scheduled_hook($k){unset($GLOBALS['events'][$k]);}
function wp_schedule_single_event($ts,$k){$GLOBALS['events'][$k]=$ts;return true;}
function bon_week_start_timestamp(){return (new DateTimeImmutable('monday this week',wp_timezone()))->getTimestamp();}
class WP_Error {function __construct(...$a){}}
function is_wp_error($v){return $v instanceof WP_Error;}
require __DIR__.'/../balika/includes/class-bon-cron.php';
require __DIR__.'/../balika/includes/class-bon-reports.php';
$n=0;$zone='Asia/Tehran';$options=[];$events=[];$fail=false;
function check($ok,$label){global $n;if(!$ok){echo "FAIL: $label\n";exit(1);}echo "PASS: $label\n";$n++;}
foreach (['2026-99-99','2026-02-30','2025-02-29','2026-04-31','0000-01-01','2026-00-10','2026-01-00','2026-1-01',"2026-01-01\0",'2026-01-01 trailing','',null,[],123] as $bad) {
 check(!Bale_Order_Notifier_Cron::valid_report_day($bad),'invalid cursor rejected: '.json_encode($bad));
 check(is_wp_error(Bale_Order_Notifier_Reports::range_bounds($bad,$bad)),'range parser rejects invalid date without throwing');
 foreach (['day','week'] as $kind) {
  $key='bon_closed_'.$kind.'_pending';$hook='bon_closed_'.$kind.'_report_event';
  $options=[$key=>$bad];$events=[$hook=>123];
  call_user_func([Bale_Order_Notifier_Cron::class,'ensure_closed_'.$kind]);
  $expected=$kind==='day'?(new DateTimeImmutable('today',wp_timezone()))->format('Y-m-d'):(new DateTimeImmutable('@'.bon_week_start_timestamp()))->setTimezone(wp_timezone())->modify('+6 days')->format('Y-m-d');
  check($options[$key]===$expected && $events[$hook]===(new DateTimeImmutable($expected.' 00:00:00',wp_timezone()))->modify('+1 day')->getTimestamp(),'repair '.$kind.' cursor even with existing event, schedule next local midnight');
 }
}
foreach (['2024-02-29','2000-02-29','2026-10-28'] as $day) {
 check(Bale_Order_Notifier_Cron::valid_report_day($day) && !is_wp_error(Bale_Order_Notifier_Reports::range_bounds($day,$day)),'valid calendar date preserved '.$day);
}
foreach (['day','week'] as $kind) {
 $key='bon_closed_'.$kind.'_pending';$hook='bon_closed_'.$kind.'_report_event';
 $options=[$key=>'2024-02-29'];$events=[$hook=>123];call_user_func([Bale_Order_Notifier_Cron::class,'ensure_closed_'.$kind]);
 check($options[$key]==='2024-02-29' && $events[$hook]===123,'valid backlog and existing '.$kind.' event preserved');
 $events=[];call_user_func([Bale_Order_Notifier_Cron::class,'ensure_closed_'.$kind]);
 check($options[$key]==='2024-02-29' && abs($events[$hook]-time())<5,'valid overdue '.$kind.' cursor scheduled for catch-up');
 $options=[$key=>'2026-99-99'];$events=[];$fail=true;call_user_func([Bale_Order_Notifier_Cron::class,'ensure_closed_'.$kind]);
 check(!$events && $options[$key]==='2026-99-99','failed '.$kind.' repair persistence does not schedule invented cursor');
 $events=[$hook=>123];call_user_func([Bale_Order_Notifier_Cron::class,'ensure_closed_'.$kind]);
 check($events[$hook]===123,'failed repair leaves existing '.$kind.' event intact');$fail=false;
}
$zone='Europe/Stockholm';
foreach (['2026-03-29'=>23,'2026-10-25'=>25] as $day=>$hours) {
 $bounds=Bale_Order_Notifier_Reports::range_bounds($day,$day);
 check($bounds[1]-$bounds[0]+1===$hours*3600,'closed-day bounds respect DST '.$hours.' hours');
}
echo "$n checks passed\n";
