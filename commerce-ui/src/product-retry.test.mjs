import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const bundle=readFileSync(new URL('../../balika/assets/commerce/storefront.js',import.meta.url),'utf8');
const product={id:12,name:'Linked product',price:'100',image:'https://shop.test/p.png',url:'https://shop.test/product/12',available:true,direct:true};
const ok=data=>({ok:true,json:async()=>data});
const fail=message=>({ok:false,json:async()=>({message})});
async function waitFor(fn){for(let i=0;i<150;i++){if(fn())return;await new Promise(r=>setTimeout(r,10));}throw Error('UI timeout');}
function setup(detail,catalog=()=>ok({products:[product],categories:[],pages:1}),productId=12){
 const cfg={name:'Test',home:'https://shop.test/',account:'https://shop.test/account',checkout:'https://shop.test/checkout',catalog:'https://shop.test/catalog',product:'https://shop.test/product/',cart:'https://shop.test/cart',productId,restNonce:'nonce-test'};
 const dom=new JSDOM(`<div id="bon-storefront"></div><script id="bon-commerce-config" type="application/json">${JSON.stringify(cfg)}</script>`,{url:cfg.home,runScripts:'outside-only',pretendToBeVisual:true});
 dom.window.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
 const calls=[];let details=0,catalogs=0;
 dom.window.fetch=async(url,opts)=>{calls.push({url,opts});const path=new URL(url).pathname;
 if(path==='/product/12')return detail(++details);
 if(path==='/catalog')return catalog(++catalogs);
 return ok({success:true,data:{token:'a'.repeat(40),cart:{items:[],count:0,coupons:[],total:'0'}}});};
 dom.window.eval(bundle);return {dom,calls,count:()=>({details,catalogs})};
}
test('deep-link retry recovers product only, retains catalog and request security options',async()=>{
 const {dom,calls,count}=setup(n=>n===1?fail('Detail unavailable'):ok(product));const doc=dom.window.document;
 try{await waitFor(()=>doc.querySelector('.product-error button:not(:disabled)')&&doc.querySelector('.product-card'));
 doc.querySelector('.product-error button').click();await waitFor(()=>doc.querySelector('.product-detail'));
 assert.equal(doc.querySelector('.product-error'),null);assert.match(doc.querySelector('.product-detail').textContent,/Linked product/);
 assert.deepEqual(count(),{details:2,catalogs:1});
 const request=calls.filter(c=>c.url.endsWith('/product/12'))[1];assert.equal(request.opts.credentials,'same-origin');assert.equal(request.opts.cache,'no-store');assert.equal(request.opts.headers['X-WP-Nonce'],'nonce-test');
 }finally{dom.window.close();}
});
test('pending retry prevents duplicate requests and repeated failure remains recoverable',async()=>{
 let resolve;const {dom,count}=setup(n=>n===1?fail('First failure'):n===2?new Promise(r=>{resolve=r;}):ok(product));const doc=dom.window.document;
 try{await waitFor(()=>doc.querySelector('.product-error button:not(:disabled)'));const button=doc.querySelector('.product-error button');button.click();button.click();
 await waitFor(()=>resolve);assert.equal(button.disabled,true);assert.equal(count().details,2);
 resolve(fail('Second failure'));await waitFor(()=>doc.querySelector('.product-error button:not(:disabled)'));assert.match(doc.querySelector('.product-error').textContent,/Second failure/);
 doc.querySelector('.product-error button').click();await waitFor(()=>doc.querySelector('.product-detail'));assert.deepEqual(count(),{details:3,catalogs:1});
 }finally{dom.window.close();}
});
test('catalog and product errors retry independently without clearing the other error',async()=>{
 const {dom,count}=setup(n=>n===1?fail('Detail failure'):ok(product),n=>n===1?fail('Catalog failure'):ok({products:[product],categories:[],pages:1}));const doc=dom.window.document;
 try{await waitFor(()=>doc.querySelectorAll('.empty[role="alert"]').length===2);
 doc.querySelector('.empty[role="alert"]:not(.product-error) button').click();await waitFor(()=>doc.querySelector('.product-card'));
 assert.match(doc.querySelector('.product-error').textContent,/Detail failure/);assert.deepEqual(count(),{details:1,catalogs:2});
 doc.querySelector('.product-error button').click();await waitFor(()=>doc.querySelector('.product-detail'));assert.deepEqual(count(),{details:2,catalogs:2});
 }finally{dom.window.close();}
});
test('ordinary catalog without deep link makes no product request',async()=>{
 const {dom,count}=setup(()=>{throw Error('Unexpected detail request');},undefined,0);
 try{await waitFor(()=>dom.window.document.querySelector('.product-card'));assert.deepEqual(count(),{details:0,catalogs:1});assert.equal(dom.window.document.querySelector('.product-error'),null);}finally{dom.window.close();}
});
