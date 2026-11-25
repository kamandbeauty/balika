<?php
/** Audit reproductions: OBSERVED means a defect was demonstrated, not fixed. */
define('ABSPATH',__DIR__);
function get_option($k,$d=[]){return ['bale_enabled'=>true,'bale_bot_token'=>'synthetic-token'];}
function wp_json_encode($v){return json_encode($v);}
function doing_action($hook){return !empty($GLOBALS['transaction']);}
class WP_Error {function get_error_message(){return 'synthetic transport failure';}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_post(...$a){return new WP_Error;}
class Bale_Order_Notifier_Logger {static function log(...$a){}}
class Bale_Order_Notifier_Queue {
 static $rows=[];
 static function enqueue($platform,$method,$params,$order=0,$type=''){self::$rows[]=compact('platform','method','params','order','type');return true;}
}
require __DIR__.'/../balika/includes/class-bon-api.php';
$n=0;function observed($ok,$name){global $n;if(!$ok)throw new RuntimeException('NOT REPRODUCED: '.$name);echo "OBSERVED: $name\n";$n++;}
$api=new Bale_Order_Notifier_API('bale');
$result=$api->send_message('42','Short manager report',[],true,0,'admin_order');
$row=end(Bale_Order_Notifier_Queue::$rows);
if(empty($result['queued']) || $row['type']!=='admin_order')throw new RuntimeException('RC01 regression');
echo "FIXED: RC01 fallback retains explicit message type\n";
$transaction=true;$api->send_message('42','Manager notification inside checkout',[],true,0,'admin_order');$row=end(Bale_Order_Notifier_Queue::$rows);
if($row['type']!=='admin_order')throw new RuntimeException('RC02 regression');
echo "FIXED: RC02 checkout enqueue retains explicit message type\n";
$transaction=false;$api->send_reply('42','Private reply');$row=end(Bale_Order_Notifier_Queue::$rows);
if($row['type']!=='bot_reply' || empty($row['params']['_bon_reply']['expires']))throw new RuntimeException('RC03 regression');
echo "FIXED: RC03 explicit fresh reply preserves bounded delivery context\n";
echo "$n audit observations reproduced; fixed API contracts verified\n";
