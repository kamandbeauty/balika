<?php
class OwnerOrderFixture {
    public $customer_id=1,$email='alice@example.test',$key='wc_order_fixture',$epoch='',$changes=[];
    function get_customer_id(){return $this->customer_id;}
    function get_billing_email(){return $this->email;}
    function get_order_key(){return $this->key;}
    function get_meta($key){return $key==='_bon_owner_epoch'?$this->epoch:'';}
    function get_type(){return 'shop_order';}
    function get_changes(){return $this->changes;}
    function update_meta_data($key,$value){if($key==='_bon_owner_epoch')$this->epoch=$value;}
}
