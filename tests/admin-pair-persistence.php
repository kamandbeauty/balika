<?php
define('ABSPATH',__DIR__);
$meta=[];$transients=[];$notices=[];$options=[];$fail_meta=false;$fail_transient=false;$fail_delete=false;$seq=0;$allowed=true;
function get_current_user_id(){return 1;}
function get_user_meta($u,$k,...$a){return $GLOBALS['meta'][$k]??'';}
function update_user_meta($u,$k,$v){if($GLOBALS['fail_meta'])return false;$GLOBALS['meta'][$k]=$v;return false; /* readback, not return value, determines success */}
function delete_user_meta($u,$k){unset($GLOBALS['meta'][$k]);}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl){if($GLOBALS['fail_transient'])return false;$GLOBALS['transients'][$k]=$v;return true;}
function delete_transient($k){if($GLOBALS['fail_delete'])return false;unset($GLOBALS['transients'][$k]);return true;}
function wp_generate_password($n,...$a){return str_pad((string)++$GLOBALS['seq'],$n,'a');}
function add_settings_error(...$a){$GLOBALS['notices'][]=$a;}
function user_can(...$a){return $GLOBALS['allowed'];}
function sanitize_text_field($s){return $s;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v){$GLOBALS['options'][$k]=$v;}
class DB {public $prefix='test_';function prepare($q,...$a){return $q;}function get_var($q){return 1;}}
$wpdb=new DB;
class Bale_Order_Notifier_API {static $messages=[];function __construct(...$a){}function send_reply(...$a){self::$messages[]=$a;}}
require __DIR__.'/../balika/includes/class-bon-admin-link.php';
$n=0;function check($ok,$s){global $n;if(!$ok){echo "FAIL: $s\n";exit(1);}echo "PASS: $s\n";$n++;}
function begin_pair(){Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'begin_admin_link']);}
function claim($token){Bale_Order_Notifier_Admin_Link::incoming(['text'=>'/start admin_'.$token,'chat'=>['id'=>'42','type'=>'private'],'from'=>['id'=>'42','first_name'=>'Manager']]);}
function notice(){return end($GLOBALS['notices'])[3];}
$fail_transient=true;begin_pair();check(notice()==='error' && !$meta && !$transients,'transient failure produces error without pointer');
$fail_transient=false;$fail_meta=true;begin_pair();check(notice()==='error' && !$meta && !$transients,'meta failure cleans new request and reports error');
$fail_meta=false;begin_pair();$old=$meta['bon_admin_pair_1'];$oldkey=Bale_Order_Notifier_Admin_Link::key($old);
check(notice()==='info' && isset($transients[$oldkey]),'verified persistence reports success even if update returns false');
$fail_transient=true;begin_pair();check($meta['bon_admin_pair_1']===$old && isset($transients[$oldkey]),'failed replacement transient preserves old request');
$fail_transient=false;$fail_meta=true;begin_pair();check($meta['bon_admin_pair_1']===$old && count($transients)===1,'failed replacement pointer preserves old usable request');
$fail_delete=true;begin_pair();$orphan=array_values(array_diff(array_keys($transients),[$oldkey]))[0];$orphanToken=str_pad((string)$seq,32,'a');claim($orphanToken);
check(empty($transients[$orphan]['chat']) && !$options,'orphan cannot be claimed even if cleanup fails');
$fail_meta=false;begin_pair();$new=$meta['bon_admin_pair_1'];claim($old);
check(empty($transients[$oldkey]['chat']),'superseded request rejected even when old deletion fails');
claim($new);$key=Bale_Order_Notifier_Admin_Link::key($new);
check($transients[$key]['chat']==='42' && !$options,'current claim still requires separate approval');
$transients[$key]['expires']=time()-1;
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'approve_admin_link','pair_chat'=>'42','confirm_pair'=>1]);
check(notice()==='error' && !$options,'expired stored request cannot be approved');
$transients[$key]['expires']=time()+600;
Bale_Order_Notifier_Admin_Link::handle(['bon_action'=>'approve_admin_link','pair_chat'=>'42','confirm_pair'=>1]);
check(notice()==='success' && $options['bon_settings']['bale_admin_user_ids']===['42'],'current verified request completes two-step approval');
claim($new);check(!isset($meta['bon_admin_pair_1']),'consumed pointer stays absent despite failed transient deletion');
echo "$n checks passed\n";
