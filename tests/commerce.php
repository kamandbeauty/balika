<?php
// Isolated commerce tests: no real customer/cart, API calls or external requests.
define('ABSPATH', __DIR__);define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
$GLOBALS['settings']=[];$GLOBALS['direct_filter']=true;$GLOBALS['posts']=[];$GLOBALS['meta']=[];$GLOBALS['products']=[];$GLOBALS['transients']=[];
function get_option($key,$default=[]){if($key==='bon_commerce_settings')return $GLOBALS['settings'];if($key==='bon_channels')return $GLOBALS['channels']??[];return $default;}
function add_action(...$args){}
function apply_filters($name,$value,...$args){if($name==='bon_commerce_direct_purchase')return $GLOBALS['direct_filter'];if($name==='woocommerce_add_to_cart_validation'||$name==='woocommerce_update_cart_validation')return $GLOBALS['validation']??true;return $value;}
function get_post_field($key,$id){return $GLOBALS['posts'][$id]->$key??'';}
function get_post($id){return $GLOBALS['posts'][$id]??false;}
function get_post_type($id){return $GLOBALS['posts'][$id]->post_type??false;}
function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
function wc_get_products($args){return array_values($GLOBALS['products']);}
function wp_trim_words($text,$n,$more){return $text;}
function wp_strip_all_tags($text){return strip_tags($text);}
function strip_shortcodes($text){return $text;}
function wp_get_attachment_image_url(...$args){return 'https://shop.test/image.png';}
function wc_placeholder_img_src(...$args){return 'https://shop.test/placeholder.png';}
function esc_url_raw($url){return $url;}
function get_permalink($id){return 'https://shop.test/product/'.$id;}
function wp_parse_url($url){return parse_url($url);}
function wp_timezone(){return new DateTimeZone('Asia/Tehran');}
function wp_json_encode($v){return json_encode($v);}
function get_the_title($id){return $GLOBALS['posts'][$id]->post_title??'Product';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function home_url($path){return 'https://shop.test'.$path;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}
function wc_get_page_permalink($slug){return 'https://shop.test/'.$slug;}
function wp_html_excerpt($text,$n,$more){return $text;}
function sanitize_text_field($v){return is_scalar($v)?trim(strip_tags((string)$v)):'';}
function sanitize_key($s){return is_scalar($s)?preg_replace('/[^a-z0-9_]/','',strtolower($s)):'';}
function absint($v){return abs((int)$v);}
function wp_unslash($v){return $v;}
function nocache_headers(){}
function wp_generate_password($len,...$args){return str_repeat('s',$len);}
function bon_money($amount){return $amount.' IRR';}
function wc_coupons_enabled(){return true;}
function wc_format_coupon_code($c){return strtolower(trim($c));}
function current_time($type){return '2026-10-28 12:00:00';}
function bon_get_enabled_platforms(){return ($GLOBALS['transport']??true)?['bale']:[];}
class WP_Error{private $code,$message;function __construct($c,$m,$data=[]){$this->code=$c;$this->message=$m;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class JsonResult extends RuntimeException {public $success,$data,$status;function __construct($ok,$data,$status){$this->success=$ok;$this->data=$data;$this->status=$status;}}
function wp_send_json_success($data){throw new JsonResult(true,$data,200);}
function wp_send_json_error($data,$status){throw new JsonResult(false,$data,$status);}
class Product {
 public $id=1,$status='publish',$type='simple',$visible=true,$stock=true,$purchasable=true,$single=false;
 function get_id(){return $this->id;}function get_status(){return $this->status;}function get_type(){return $this->type;}function is_type($t){return $this->type===$t;}function is_visible(){return $this->visible;}function is_in_stock(){return $this->stock;}function is_purchasable(){return $this->purchasable;}function is_sold_individually(){return $this->single;}function get_name(){return 'Test product';}function get_short_description(){return 'Description';}function get_price_html(){return '<b>100</b> IRR';}function get_image_id(){return 1;}function is_on_sale(){return false;}function get_permalink(...$args){return get_permalink($this->id);}function get_max_purchase_quantity(){return 5;}function managing_stock(){return true;}function backorders_allowed(){return false;}function get_stock_managed_by_id(){return 1;}function has_enough_stock($q){return $q<=5;}
}
class Bale_Order_Notifier_API {
    function send_reply(...$a){return $this->send_message(...$a);}static $messages=[];function __construct(...$a){}function send_message(...$a){self::$messages[]=$a;return true;}}
class Bale_Order_Notifier_Queue {static $records=[],$fail=false;static function table_name(){return 'wp_bon_queue';}static function enqueue($platform,$method,$params,$order=0,$type=''){if(self::$fail)return false;self::$records[]=compact('params','type');return true;}}
class FakeDB {public $options='wp_options'; function delete($table,$where){$key=substr($where['option_name'],strlen('_transient_'));if(!isset($GLOBALS['transients'][$key]))return 0;unset($GLOBALS['transients'][$key]);return 1;} public $prefix='wp_',$locked=false,$existing=false,$releases=0;function prepare($q,...$a){return $q;}function get_var($q){if(strpos($q,'RELEASE_LOCK')!==false){$this->releases++;return 1;}if(strpos($q,'GET_LOCK')!==false)return !$this->locked;return $this->existing?123:null;}}
class Session {public $data=[],$saved=false,$cookie=false;function get($k){return $this->data[$k]??null;}function set($k,$v){$this->data[$k]=$v;}function set_customer_session_cookie($v){$this->cookie=$v;}function save_data(){$this->saved=true;}}
class Cart {
 public $items=[],$added=0,$coupons=[];
 function calculate_totals(){}function get_cart(){return $this->items;}function get_cart_contents_count(){return array_sum(array_column($this->items,'quantity'));}function get_total($mode){return $this->get_cart_contents_count()*100;}function get_applied_coupons(){return $this->coupons;}function get_product_subtotal($p,$q){return $q*100 . ' IRR';}
 function add_to_cart($id,$q){$this->added++;$this->items['line']=['data'=>wc_get_product($id),'quantity'=>$q];return 'line';}
 function get_cart_item($key){return $this->items[$key]??false;}function set_quantity($key,$q,$refresh){if($q===0)unset($this->items[$key]);else $this->items[$key]['quantity']=$q;return true;}
 function get_cart_item_quantities(){return [1=>$this->get_cart_contents_count()];}function apply_coupon($c){if($c!=='valid')return false;$this->coupons[]=$c;return true;}function remove_coupon($c){$this->coupons=array_values(array_diff($this->coupons,[$c]));}
}
function WC(){return $GLOBALS['wc'];}
require __DIR__.'/../balika/includes/class-bon-commerce.php';require __DIR__.'/../balika/includes/class-bon-channels.php';
$n=0;function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "PASS: $name\n";}
$c=Bale_Order_Notifier_Commerce::class;$h=new Bale_Order_Notifier_Commerce();$ch=new Bale_Order_Notifier_Channels();
$p=new Product();$products[1]=$p;$posts[1]=(object)['ID'=>1,'post_type'=>'product','post_status'=>'publish','post_password'=>''];
check(!$c::enabled('storefront')&&is_wp_error($h->catalog_permission()),'storefront disabled by default and catalog refuses access');
check($c::public_product($p),'published visible unprotected product is public');
check(!$c::direct_purchase($p),'direct cart is explicit opt-in');
$settings=['storefront'=>true,'direct_cart'=>true];check($c::direct_purchase($p),'simple product allowed after opt-in');
$p->status='private';check(!$c::public_product($p),'private products never exposed');$p->status='publish';
$posts[1]->post_password='private';check(!$c::public_product($p),'password-protected product excluded');$posts[1]->post_password='';
$p->visible=false;check(!$c::public_product($p),'hidden products excluded');$p->visible=true;
$p->type='variable';check(!$c::direct_purchase($p),'variation selection delegates to native product page');$p->type='simple';
$p->stock=false;check(!$c::direct_purchase($p),'out-of-stock product cannot be added');$p->stock=true;
$direct_filter=false;check(!$c::direct_purchase($p),'extension filter can disable direct cart for custom product requirements');$direct_filter=true;
check($c::same_origin('https://shop.test','https://shop.test/')&&!$c::same_origin('https://evil.test','https://shop.test/'),'cross-origin requests rejected');
check(!$c::same_origin('http://shop.test','https://shop.test/')&&!$c::same_origin('https://shop.test:444','https://shop.test/'),'origin checks include scheme and port');
check(!$c::same_origin('null','https://shop.test/'),'opaque origin rejected');
check($c::valid_cart_token(str_repeat('a',40),str_repeat('a',40))&&!$c::valid_cart_token(str_repeat('a',40),str_repeat('b',40)),'cart secret must match the current Woo session');
check(!$c::valid_cart_token(null,'')&&!$c::valid_cart_token(str_repeat('a',40),[]),'empty or malformed session tokens rejected');
foreach([-1,100,'1.5','2e3',[]] as $q)check($c::quantity($q)===false,'invalid quantity rejected: '.json_encode($q));
$wc=(object)['session'=>new Session(),'cart'=>new Cart()];
function cart_request($handler,$op,$method='POST',$fields=[],$token=''){
 $_REQUEST=['op'=>$op];$_POST=$fields;$_SERVER=['REQUEST_METHOD'=>$method,'HTTP_ORIGIN'=>'https://shop.test','HTTP_X_BALIKA_CART'=>$token];
 try{$handler->cart_endpoint();}catch(JsonResult $r){return $r;}throw new RuntimeException('No JSON response');
}
$r=cart_request($h,'bootstrap','GET');$token=$r->data['token'];
check($r->success&&strlen($token)===40&&$wc->session->cookie&&$wc->session->saved,'bootstrap issues session-scoped token and persists native Woo cookie');
$r=cart_request($h,'add','GET',['product_id'=>1],$token);check(!$r->success&&$r->status===405&&!$wc->cart->added,'GET cannot mutate cart');
$r=cart_request($h,'add','POST',['product_id'=>1],'bad');check(!$r->success&&$r->status===403&&!$wc->cart->added,'missing or wrong cart token cannot mutate cart');
$r=cart_request($h,'add','POST',['product_id'=>1,'quantity'=>2,'price'=>1],$token);check($r->success&&$r->data['cart']['total']==='200 IRR','cart ignores client price and uses Woo product prices');
$validation=false;$r=cart_request($h,'add','POST',['product_id'=>1,'quantity'=>1],$token);check(!$r->success&&$wc->cart->added===1,'WooCommerce add-to-cart validation respected');$validation=true;
$r=cart_request($h,'quantity','POST',['key'=>'line','quantity'=>6],$token);check(!$r->success&&$wc->cart->items['line']['quantity']===2,'quantity above stock rejected without altering item');
$r=cart_request($h,'quantity','POST',['key'=>'other-session','quantity'=>1],$token);check(!$r->success,'cannot mutate cart keys outside current session');
$r=cart_request($h,'coupon','POST',['coupon'=>'valid'],$token);check($r->success&&$r->data['cart']['coupons']===['valid'],'coupons use native cart method');
$r=cart_request($h,'remove','POST',['key'=>'line'],$token);check($r->success&&$r->data['cart']['count']===0,'native cart removal works');
$settings['bot']=true;
check(!$c::bot_message(['chat'=>['id'=>-1],'from'=>['id'=>7],'text'=>'/shop']),'bot catalog is restricted to private chat');
check($c::bot_message(['chat'=>['id'=>7],'from'=>['id'=>7],'text'=>'/shop']),'bot shop command handled');
check(strpos(json_encode(Bale_Order_Notifier_API::$messages),'bon_shop')!==false,'bot links to web storefront, not a fake native payment');
check(Bale_Order_Notifier_Channels::valid_destination('@valid_channel')&&!Bale_Order_Notifier_Channels::valid_destination('https://evil.test'),'channel destinations strictly validated');
check(is_wp_error(Bale_Order_Notifier_Channels::scheduled_time('2026-02-30T12:00')),'invalid publication date rejected');
$future=(new DateTimeImmutable('+1 day',wp_timezone()))->format('Y-m-d\TH:i');check(is_int(Bale_Order_Notifier_Channels::scheduled_time($future)),'publication time uses site timezone');
$wpdb=new FakeDB();$channels=['c'=>['label'=>'Channel','destination'=>'@valid_channel','enabled'=>true]];
$posts[20]=(object)['post_type'=>'bon_channel_job'];$meta[20]=['_bon_state'=>'scheduled','_bon_source'=>1,'_bon_channel'=>'c','_bon_destination'=>'@valid_channel'];
$ch->dispatch(20);check(!Bale_Order_Notifier_Queue::$records,'disabled channel module never queues publications');
$settings['channels']=true;$wpdb->locked=true;$ch->dispatch(20);check(!Bale_Order_Notifier_Queue::$records,'publication lock blocks concurrent workers');$wpdb->locked=false;
$ch->dispatch(20);check(count(Bale_Order_Notifier_Queue::$records)===1&&$meta[20]['_bon_state']==='queued','due content enters existing Bale outbox');
$ch->dispatch(20);check(count(Bale_Order_Notifier_Queue::$records)===1,'queued job is not queued twice');
$meta[20]['_bon_state']='scheduled';$wpdb->existing=true;$ch->dispatch(20);check(count(Bale_Order_Notifier_Queue::$records)===1&&$meta[20]['_bon_state']==='queued','crash recovery detects existing outbox before marking job queued');
check(Bale_Order_Notifier_Channels::can_deliver(20),'active published content is deliverable');
$channels['c']['enabled']=false;check(!Bale_Order_Notifier_Channels::can_deliver(20),'disabled destination prevents delivery even after enqueue');$channels['c']['enabled']=true;
$channels['c']['destination']='@other_channel';check(!Bale_Order_Notifier_Channels::can_deliver(20),'destination changes never silently reroute queued content');$channels['c']['destination']='@valid_channel';
$posts[1]->post_status='draft';check(!Bale_Order_Notifier_Channels::can_deliver(20),'unpublished content is blocked at delivery time');$posts[1]->post_status='publish';
$meta[20]['_bon_state']='cancelled';check(!Bale_Order_Notifier_Channels::can_deliver(20),'cancelled publication cannot be delivered');
check($wpdb->releases>0,'publication locks released');
function current_user_can($cap,...$args){return !empty($GLOBALS['can']) && ($cap!=='edit_post'||!empty($GLOBALS['can_edit']));}
function wp_die(...$args){throw new RuntimeException('forbidden');}
function check_admin_referer($action){if(empty($GLOBALS['nonce']))throw new RuntimeException('csrf');}
function get_current_user_id(){return 10;}
function wp_using_ext_object_cache(){return false;}
function admin_url($p){return 'https://shop.test/wp-admin/'.$p;}
class Redirected extends RuntimeException{}
function wp_safe_redirect($url){throw new Redirected($url);}
function wp_insert_post($args,$errors){$GLOBALS['inserts'][]=$args;return count($GLOBALS['inserts'])+100;}
function channel_request($handler){try{$handler->handle();}catch(Redirected $e){return;}throw new RuntimeException('No redirect');}
$can=false;$can_edit=true;$nonce=true;$inserts=[];$_POST=['operation'=>'schedule'];
try{$ch->handle();}catch(RuntimeException $e){check($e->getMessage()==='forbidden','channel administrator endpoint rejects unauthorised users');}
$can=true;$nonce=false;
try{$ch->handle();}catch(RuntimeException $e){check($e->getMessage()==='csrf','channel administrator endpoint rejects invalid nonce');}
$nonce=true;
$token=str_repeat('a',32);$key='bon_channel_once_10_'.hash('sha256',$token);
$_POST=['operation'=>'schedule','channel'=>'c','source'=>1,'publish_at'=>$future,'schedule_token'=>$token];
$transients[$key]=1;$can_edit=false;channel_request($ch);check(!$inserts,'cannot schedule a source the manager cannot edit');$can_edit=true;
$posts[1]->post_status='draft';channel_request($ch);check(!$inserts,'cannot schedule unpublished source');$posts[1]->post_status='publish';
channel_request($ch);check(count($inserts)===1&&$inserts[0]['meta_input']['_bon_state']==='scheduled','authorised schedule stores source, destination and timestamp');
channel_request($ch);check(count($inserts)===1,'single-use schedule form prevents repeated POST publication');
$transport=false;$transients=[];
check(!$c::bot_message(['chat'=>['id'=>7],'from'=>['id'=>7],'text'=>'/shop']),'bot module respects global Bale disable switch');
$meta[20]['_bon_state']='queued';
check(!Bale_Order_Notifier_Channels::can_deliver(20),'queued channel messages respect global Bale disable switch');
echo "$n checks passed\n";
