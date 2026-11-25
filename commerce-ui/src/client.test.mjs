import test from 'node:test';
import assert from 'node:assert/strict';
import {catalogUrl, cartUrl, quantityValue, decodeResponse} from './client.mjs';
test('catalog query preserves plain-permalink REST route and encodes search safely', () => {
  const u = new URL(catalogUrl('https://shop.test/?rest_route=/balika/v1/catalog', {search:'روغن & کرم',category:3,page:2}));
  assert.equal(u.searchParams.get('rest_route'),'/balika/v1/catalog');
  assert.equal(u.searchParams.get('search'),'روغن & کرم');
  assert.equal(u.searchParams.get('page'),'2');
});
test('cart endpoint preserves native wc-ajax routing', () => {
  const u = new URL(cartUrl('https://shop.test/?wc-ajax=bon_commerce_cart','add'));
  assert.equal(u.searchParams.get('wc-ajax'),'bon_commerce_cart');assert.equal(u.searchParams.get('op'),'add');
});
test('quantity boundaries reject signs, decimals, arrays and overlarge quantities', () => {
  assert.equal(quantityValue(0),0);assert.equal(quantityValue(99),99);
  for(const input of [-1,'1.5',100,'2e3','bad',{}]) assert.equal(quantityValue(input),null);
});
test('server cart failures and invalid HTML responses are errors, not successful updates', async () => {
  await assert.rejects(decodeResponse({ok:true,json:async()=>({success:false,data:{message:'stock unavailable'}})},true),/stock unavailable/);
  await assert.rejects(decodeResponse({ok:false,json:async()=>{throw new Error('HTML');}}),/پاسخ نامعتبر/);
  assert.deepEqual(await decodeResponse({ok:true,json:async()=>({success:true,data:{cart:[]}})},true),{cart:[]});
});
