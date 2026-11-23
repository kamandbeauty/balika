// Known-defect reproductions for 2.4.0; intentionally separate from npm test.
// Passing means the documented defect exists, NOT that the behavior is correct.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const bundle = readFileSync(new URL('../../balika/assets/commerce/storefront.js', import.meta.url), 'utf8');
const config = {name:'Audit', home:'https://shop.test/', account:'https://shop.test/account/', checkout:'https://shop.test/checkout/', catalog:'https://shop.test/wp-json/balika/v1/catalog', product:'https://shop.test/wp-json/balika/v1/product/', cart:'https://shop.test/?wc-ajax=bon_commerce_cart', productId:0};
const product = {id:1, name:'Fixture', image:'https://shop.test/img.png', price:'100', available:true, direct:true, url:'https://shop.test/product/1'};
async function waitFor(fn) { for(let i=0;i<100;i++) {if(fn()) return; await new Promise(r=>setTimeout(r,10));} throw Error('UI timeout'); }
function setup(emptyPage, detailFailure = false) {
 const calls = [];
 const dom = new JSDOM(`<div id="bon-storefront"></div><script id="bon-commerce-config" type="application/json">${JSON.stringify({...config, productId:detailFailure ? 1 : 0})}</script>`, {url:'https://shop.test/?bon_shop=1',runScripts:'outside-only',pretendToBeVisual:true});
 dom.window.HTMLDialogElement.prototype.showModal = function() { this.setAttribute('open',''); };
 dom.window.fetch = async url => {
  calls.push(String(url));
  const op = new URL(url).searchParams.get('op');
  if(detailFailure && String(url).includes('/product/1')) return {ok:false,json:async()=>({message:'Temporary product error'})};
  if(op === 'bootstrap') return {ok:true,json:async()=>({success:true,data:{token:'a'.repeat(40),cart:{items:[],count:0,coupons:[],total:'0'}}})};
  if(op === 'add') return {ok:false,json:async()=>({success:false,data:{message:'Stock changed'}})};
  return {ok:true,json:async()=>({products:emptyPage ? [] : [product],categories:[],page:1,pages:2})};
 };
 dom.window.eval(bundle);
 dom.auditCalls = calls;
 return dom;
}
test('A08 reproduced: empty filtered first page hides navigation to existing second page', async()=>{
 const dom = setup(true), doc = dom.window.document;
 try { await waitFor(()=>doc.querySelector('.empty h3')); assert.equal(doc.querySelector('.pagination'), null); assert.match(doc.querySelector('.empty').textContent,/محصولی پیدا نشد/); }
 finally {dom.window.close();}
});
test('A09 reproduced: retry after deep-link product error does not retry product request', async()=>{
 const dom = setup(false, true), doc = dom.window.document;
 try {
  await waitFor(()=>doc.querySelector('.empty[role="alert"]'));
  assert.match(doc.querySelector('.empty').textContent,/Temporary product error/);
  doc.querySelector('.empty button').click();
  await waitFor(()=>dom.auditCalls.filter(u=>u.includes('/catalog')).length === 2);
  assert.equal(dom.auditCalls.filter(u=>u.includes('/product/1')).length, 1);
  assert.equal(doc.querySelector('.product-detail'), null);
 } finally {dom.window.close();}
});
