<?php
/** Fault-injection audit, deliberately outside the normal regression runner. */
define('ABSPATH',__DIR__);
function add_action(...$a){}function add_filter(...$a){}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;return true;}
function bon_week_start_timestamp(){return (new DateTimeImmutable('monday this week',wp_timezone()))->getTimestamp();}
function wp_timezone(){return new DateTimeZone('Asia/Tehran');}
function wp_next_scheduled($k){return false;}
function wp_clear_scheduled_hook($k){}
function wp_schedule_single_event(...$a){$GLOBALS['scheduled'][]=$a;return true;}
function get_current_user_id(){return 1;}
function wp_generate_password($n,...$a){return str_repeat('a',$n);}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;return true;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);return true;}
function get_user_meta(...$a){return '';}
function update_user_meta(...$a){return false;}
function add_settings_error(...$a){$GLOBALS['notices'][]=$a;}
function current_time($kind){return '2026-10-28 12:00:00';}
require __DIR__.'/fixtures/owner-order.php';
function wc_get_order($id){if($id!==10)return false;$order=new OwnerOrderFixture;$order->email=$GLOBALS['owner'].'@example.test';return $order;}
class Bale_Order_Notifier_Queue {static function table_name(){return 'test_queue';}}
class DB {public $prefix='test_',$rows=[];function prepare($q,...$a){return [$q,$a];}function get_row($q){return isset($this->rows[$q[1][0]])?(object)$this->rows[$q[1][0]]:null;}function replace($t,$row){$this->rows[$row['order_id']]=$row;return 1;}function get_var($q){if(strpos($q[0],'LOCK')!==false)return 1;return $this->rows[$q[1][0]]['chat_id']??null;}}
require __DIR__.'/../balika/includes/class-bon-cron.php';
require __DIR__.'/../balika/includes/class-bon-admin-link.php';
require __DIR__.'/../balika/includes/class-bon-customer-store.php';
$n=0;function observed($ok,$name){global $n;if(!$ok)throw new RuntimeException('NOT REPRODUCED: '.$name);echo "OBSERVED: $name\n";$n++;}
$options=['bon_closed_day_pending'=>'2026-99-99'];$crashed=false;
try { Bale_Order_Notifier_Cron::ensure_closed_day(); } catch (Throwable $e) {$crashed=true;}
if($crashed || !Bale_Order_Notifier_Cron::valid_report_day($options['bon_closed_day_pending']))throw new RuntimeException('RC04 regression');
echo "FIXED: RC04 invalid daily date recovered\n";
$options=['bon_closed_week_pending'=>'2026-99-99'];$crashed=false;
try { Bale_Order_Notifier_Cron::ensure_closed_week(); } catch (Throwable $e) {$crashed=true;}
if($crashed || !Bale_Order_Notifier_Cron::valid_report_day($options['bon_closed_week_pending']))throw new RuntimeException('RC04b regression');
echo "FIXED: RC04b invalid weekly date recovered\n";
$options=[];$notices=[];$transients=[];
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'begin_admin_link']);
if(empty($notices) || $notices[0][3]!=='error' || count($transients)!==0)throw new RuntimeException('RC05 regression');
echo "FIXED: RC05 failed pairing persistence reports error and removes orphan\n";
$wpdb=new DB;$owner='alice';
Bale_Order_Notifier_Customer_Store::link_order(10,'42');$owner='bob';
if(Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'))throw new RuntimeException('RC06 regression');
echo "FIXED: RC06 previous owner grant rejected\n";
echo "$n audit observations reproduced (not fixes)\n";
