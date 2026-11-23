import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const bundle = readFileSync(new URL('../../balika/assets/commerce/storefront.js', import.meta.url),'utf8');
const config = {name:'فروشگاه آزمایشی',home:'https://shop.test/',account:'https://shop.test/my-account/',checkout:'https://shop.test/checkout/',catalog:'https://shop.test/wp-json/balika/v1/catalog',product:'https://shop.test/wp-json/balika/v1/product/',cart:'https://shop.test/?wc-ajax=bon_commerce_cart',productId:0,restNonce:'rest-test'};
const product={id:12,name:'<img src=x onerror=alert(1)> کرم',description:'محصول آزمایشی',price:'۱۰۰ تومان',image:'https://shop.test/image.png',url:'https://shop.test/product/12',available:true,direct:true};
async function waitFor(fn) {for(let i=0;i<100;i++){if(fn())return;await new Promise(r=>setTimeout(r,10));}throw new Error('UI did not reach expected state');}
function setup({fail=false,direct=true}={}){
 const dom=new JSDOM(`<div id="bon-storefront"></div><script id="bon-commerce-config" type="application/json">${JSON.stringify(config)}</script>`,{url:'https://shop.test/?bon_shop=1',runScripts:'outside-only',pretendToBeVisual:true});
 dom.window.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
 const calls=[];
 dom.window.fetch=async (url,opts={})=>{
  calls.push({url,opts});
  const u=new URL(url),op=u.searchParams.get('op');
  let data;
  if(op==='bootstrap') data={success:true,data:{token:'x'.repeat(40),cart:{items:[],count:0,total:'۰ تومان',coupons:[]}}};
  else if(op==='add') data={success:true,data:{cart:{items:[{key:'abc',name:product.name,quantity:1,total:'۱۰۰ تومان',image:product.image}],count:1,total:'۱۰۰ تومان',coupons:[]}}};
  else if(fail) return {ok:false,json:async()=>({message:'catalog unavailable'})};
  else data={products:[{...product,direct}],categories:[],pages:1};
  return {ok:true,json:async()=>data};
 };
 dom.window.eval(bundle);
 return {dom,calls};
}
test('real React bundle: catalog, safe text rendering, cart POST token and Woo checkout',async()=>{
 const {dom,calls}=setup();const doc=dom.window.document;
 try{
  await waitFor(()=>doc.querySelector('.product-image'));
  assert.equal(doc.querySelectorAll('img[onerror]').length,0);
  assert.match(doc.querySelector('.product-name').textContent,/<img/);
  doc.querySelector('.product-image').click();
  await waitFor(()=>doc.querySelector('.product-detail .primary:not(:disabled)'));
  doc.querySelector('.product-detail .primary').click();
  await waitFor(()=>doc.querySelector('.checkout'));
  assert.equal(doc.querySelector('.checkout').href,config.checkout);
  const add=calls.find(c=>new URL(c.url).searchParams.get('op')==='add');
  assert.equal(add.opts.method,'POST');assert.equal(add.opts.credentials,'same-origin');
  assert.equal(add.opts.headers['X-Balika-Cart'],'x'.repeat(40));
  assert.match(String(add.opts.body),/product_id=12/);
  assert.doesNotMatch(String(add.opts.body),/price=/);
  assert.equal(calls.find(c=>c.url.includes('catalog')).opts.headers['X-WP-Nonce'],'rest-test');
 }finally{dom.window.close();}
});
test('products needing native configuration link to canonical product instead of unsafe direct add',async()=>{
 const {dom,calls}=setup({direct:false});const doc=dom.window.document;
 try{await waitFor(()=>doc.querySelector('.product-image'));doc.querySelector('.product-image').click();await waitFor(()=>doc.querySelector('.product-detail a.primary'));assert.equal(doc.querySelector('.product-detail a.primary').href,product.url);assert.equal(calls.filter(c=>new URL(c.url).searchParams.get('op')==='add').length,0);}finally{dom.window.close();}
});
test('catalog error is shown with retry rather than fake products',async()=>{
 const {dom}=setup({fail:true});try{await waitFor(()=>dom.window.document.querySelector('.empty[role=alert]'));assert.match(dom.window.document.querySelector('.empty').textContent,/catalog unavailable/);assert.equal(dom.window.document.querySelectorAll('.product-card').length,0);}finally{dom.window.close();}
});
