import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const bundle=readFileSync(new URL('../../balika/assets/commerce/storefront.js',import.meta.url),'utf8');
async function waitFor(fn){for(let i=0;i<150;i++){if(fn())return;await new Promise(r=>setTimeout(r,10));}throw Error('UI timeout');}
function setup(respond){
 const cfg={name:'Pagination',home:'https://shop.test/',account:'https://shop.test/account/',checkout:'https://shop.test/checkout/',catalog:'https://shop.test/catalog',product:'https://shop.test/product/',cart:'https://shop.test/cart',productId:0};
 const dom=new JSDOM(`<div id="bon-storefront"></div><script id="bon-commerce-config" type="application/json">${JSON.stringify(cfg)}</script>`,{url:cfg.home,runScripts:'outside-only',pretendToBeVisual:true});
 dom.window.fetch=async url=>{
  const u=new URL(url);
  if(u.pathname==='/cart')return {ok:true,json:async()=>({success:true,data:{token:'a'.repeat(40),cart:{items:[],count:0,coupons:[],total:'0'}}})};
  return respond(u);
 };
 dom.window.eval(bundle);return dom;
}
const product={id:1,name:'Page two product',image:'https://shop.test/img.png',price:'100',available:true,direct:true,url:'https://shop.test/product/1'};
const response=(page,products=[],pages=2)=>({ok:true,json:async()=>({page,products,pages,categories:[]})});
const nav=doc=>doc.querySelector('.pagination');
test('empty first page can reach populated next page and return',async()=>{
 const calls=[];const dom=setup(u=>{const page=Number(u.searchParams.get('page'));calls.push(page);return response(page,page===2?[product]:[]);});const doc=dom.window.document;
 try{
  await waitFor(()=>nav(doc));assert.match(doc.querySelector('.empty').textContent,/محصولی پیدا نشد/);
  assert.equal(nav(doc).firstElementChild.disabled,true);assert.equal(nav(doc).lastElementChild.disabled,false);
  nav(doc).lastElementChild.click();await waitFor(()=>doc.querySelector('.product-card'));
  assert.equal(nav(doc).lastElementChild.disabled,true);assert.equal(nav(doc).firstElementChild.disabled,false);
  nav(doc).firstElementChild.click();await waitFor(()=>doc.querySelector('.empty h3')&&nav(doc));
  assert.deepEqual(calls,[1,2,1]);
 }finally{dom.window.close();}
});
test('empty later page with reduced page count still offers previous page',async()=>{
 const dom=setup(u=>Number(u.searchParams.get('page'))===1?response(1,[product]):response(2,[],1));const doc=dom.window.document;
 try{await waitFor(()=>nav(doc));nav(doc).lastElementChild.click();await waitFor(()=>doc.querySelector('.empty h3')&&nav(doc));
 assert.equal(nav(doc).firstElementChild.disabled,false);assert.equal(nav(doc).lastElementChild.disabled,true);
 nav(doc).firstElementChild.click();await waitFor(()=>doc.querySelector('.product-card'));
 }finally{dom.window.close();}
});
test('empty catalog without other pages has reset action but no useless pagination',async()=>{
 const dom=setup(()=>response(1,[],0));const doc=dom.window.document;
 try{await waitFor(()=>doc.querySelector('.empty h3'));assert.equal(nav(doc),null);assert.match(doc.querySelector('.empty button').textContent,/نمایش همه محصولات/);}finally{dom.window.close();}
});
test('pagination is hidden during loading and after request failure',async()=>{
 let resolve;const dom=setup(u=>Number(u.searchParams.get('page'))===1?response(1,[product]):new Promise(r=>{resolve=r;}));const doc=dom.window.document;
 try{await waitFor(()=>nav(doc));nav(doc).lastElementChild.click();await waitFor(()=>resolve&&doc.querySelector('[aria-busy="true"]'));
 assert.equal(nav(doc),null);resolve({ok:false,json:async()=>({message:'Temporary error'})});await waitFor(()=>doc.querySelector('.empty[role="alert"]'));assert.equal(nav(doc),null);
 }finally{dom.window.close();}
});
