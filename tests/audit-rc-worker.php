<?php
/** Reuses the queue transport/DB fixture; baseline assertions run first. */
require __DIR__.'/daily-delivery.php';
$observations=0;
function observed_worker($ok,$name){global $observations;if(!$ok)throw new RuntimeException('NOT REPRODUCED: '.$name);echo "OBSERVED: $name\n";$observations++;}
$wpdb=new DB;$bale=true;$fail_option=false;
$options=['bon_settings'=>['bale_enabled'=>true,'bale_chat_ids'=>['99'],'daily_report_enabled'=>true]];
Bale_Order_Notifier_API::$calls=[];Bale_Order_Notifier_API::$fail=[];
Bale_Order_Notifier_Queue::enqueue('bale','sendMessage',['chat_id'=>'42','text'=>'Order details for former manager'],123,'admin_order');
Bale_Order_Notifier_Queue::instance()->process_queue();
check(Bale_Order_Notifier_API::$calls===[] && $wpdb->rows[1]->status==='paused','RC07 fixed: removed manager destination is paused before transport');
$wpdb=new DB;Bale_Order_Notifier_API::$calls=[];$options['bon_settings']['bale_chat_ids']=['42'];
Bale_Order_Notifier_Daily_Delivery::prepare('2026-10-20',[1,2],'Daily fixture');
$wpdb->fail_checkpoint=true;
Bale_Order_Notifier_Queue::instance()->process_queue();
Bale_Order_Notifier_Queue::instance()->process_queue();
check(Bale_Order_Notifier_API::$calls===['42'] && $wpdb->rows[1]->status==='uncertain','RC08 fixed: failed sent checkpoint cannot replay automatically');
echo "$observations worker audit observations reproduced (not fixes)\n";
