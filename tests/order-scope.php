<?php
define('ABSPATH',__DIR__);
function get_option($k,$d=[]){return $d;}function current_time($s){return '2026-10-28 10:00:00';}
require __DIR__.'/fixtures/owner-order.php';
$orders=[10=>new OwnerOrderFixture,11=>new OwnerOrderFixture];
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
function wp_generate_password($n,...$args){return substr(bin2hex(random_bytes($n)),0,$n);}
class Bale_Order_Notifier_Queue {static function table_name(){return 'test_queue';}}
class DB {
 public $prefix='test_', $rows=[], $busy=false,$fail=false;
 function prepare($sql,...$a){return [$sql,$a];}
 function get_var($q){if(strpos($q[0],'GET_LOCK')!==false)return $this->busy?0:1;if(strpos($q[0],'RELEASE_LOCK')!==false)return 1;return $this->rows[$q[1][0]]['chat_id']??null;}
 function get_row($q){return isset($this->rows[$q[1][0]])?(object)$this->rows[$q[1][0]]:null;}
 function replace($t,$row){if($this->fail)return false;$this->rows[$row['order_id']]=$row;return 1;}
 function get_col($q){$ids=[];foreach($this->rows as $id=>$row)if($row['chat_id']===$q[1][0])$ids[]=$id;return $ids;}
}
require __DIR__.'/../balika/includes/class-bon-customer-store.php';
$wpdb=new DB;$n=0;function check($ok,$text){global $n;if(!$ok){fwrite(STDERR,"FAIL: $text\n");exit(1);}echo "PASS: $text\n";$n++;}
check(Bale_Order_Notifier_Customer_Store::link_order(10,'42'),'valid order token can bind one order');
check(Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'explicit order grant works');
check(!Bale_Order_Notifier_Customer_Store::owns_order(11,'bale','42'),'same phone on another order grants no access');
check(count(Bale_Order_Notifier_Customer_Store::orders_for_chat('42'))===1,'orders command lists only explicit grants');
check(Bale_Order_Notifier_Customer_Store::get_links_for_phone('09121234567')===[],'legacy phone mapping cannot authorize sends');
check(Bale_Order_Notifier_Customer_Store::get_all_links()===[],'legacy audience not automatically migrated to marketing consent');
check(!Bale_Order_Notifier_Customer_Store::link_order(10,'-123'),'group cannot receive private order grant');
$wpdb->busy=true;check(!Bale_Order_Notifier_Customer_Store::link_order(10,'43'),'relink respects queue lock');$wpdb->busy=false;
$wpdb->fail=true;check(!Bale_Order_Notifier_Customer_Store::link_order(10,'43'),'failed write never claims binding success');$wpdb->fail=false;
Bale_Order_Notifier_Customer_Store::link_order(10,'43');check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42') && Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','43'),'new secure link revokes previous chat for only that order');
$owner=Bale_Order_Notifier_Customer_Store::owner_hash($orders[10]);
$orders[10]->customer_id=2;
check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','43'),'changed customer account revokes old grant');
check(Bale_Order_Notifier_Customer_Store::orders_for_chat('43')===[],'orders listing excludes ownership-mismatched grant');
check(!Bale_Order_Notifier_Customer_Store::link_order(10,'42',$owner),'old token cannot relink after customer transfer');
$orders[10]->changes=['customer_id'=>2];Bale_Order_Notifier_Customer_Store::owner_will_save($orders[10]);
$orders[10]->customer_id=1;$orders[10]->changes=['customer_id'=>1];Bale_Order_Notifier_Customer_Store::owner_will_save($orders[10]);
check(!Bale_Order_Notifier_Customer_Store::link_order(10,'42',$owner),'owner revert does not revive pre-transfer token');
check(Bale_Order_Notifier_Customer_Store::link_order(10,'42',Bale_Order_Notifier_Customer_Store::owner_hash($orders[10])),'new owner-version token reconnects successfully');
$orders[10]->customer_id=0;Bale_Order_Notifier_Customer_Store::link_order(10,'42');$orders[10]->email='bob@example.test';
check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'guest email transfer revokes grant');
Bale_Order_Notifier_Customer_Store::link_order(10,'42');$orders[10]->key='wc_order_replaced';
check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'rotated Woo order key invalidates grant');
Bale_Order_Notifier_Customer_Store::link_order(10,'42');$epoch=$orders[10]->epoch;$orders[10]->changes=['status'=>'completed','billing'=>['address_1'=>'new address']];
Bale_Order_Notifier_Customer_Store::owner_will_save($orders[10]);
check($orders[10]->epoch===$epoch && Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'ordinary address/status changes preserve binding');
$orders[10]->changes=['billing'=>['email'=>'new@example.test']];Bale_Order_Notifier_Customer_Store::owner_will_save($orders[10]);
check($orders[10]->epoch!==$epoch,'email edit rotates ownership epoch');
$wpdb->rows[10]['owner_hash']='';check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'legacy grant without owner hash fails closed');
$wpdb->rows[10]['owner_hash']=[];check(!Bale_Order_Notifier_Customer_Store::owns_order(10,'bale','42'),'malformed stored owner hash fails closed');
$wpdb->rows[11]=['order_id'=>11,'chat_id'=>'99','owner_hash'=>Bale_Order_Notifier_Customer_Store::owner_hash($orders[11])];
check(Bale_Order_Notifier_Customer_Store::owns_order(11,'bale','99'),'other order binding remains independent');
$valid=Bale_Order_Notifier_Customer_Store::owner_hash($orders[11]);$orders[11]->email=' ALICE@EXAMPLE.TEST ';
check(Bale_Order_Notifier_Customer_Store::owner_hash($orders[11])===$valid,'email casing and boundary whitespace normalized in identity hash');
$orders[11]->email="\xFF";check(Bale_Order_Notifier_Customer_Store::owner_hash($orders[11])==='','unencodable identity fails closed');
echo "$n checks passed\n";
