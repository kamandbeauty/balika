import React, {useEffect, useRef, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {catalogUrl, cartUrl, decodeResponse, quantityValue} from './client.mjs';
const cfg = JSON.parse(document.getElementById('bon-commerce-config').textContent);
const fa = n => new Intl.NumberFormat('fa-IR').format(n);
function Icon({name, ...props}) {
  const shapes = {
    bag: <><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></>,
    search: <><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></>,
    user: <><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></>,
    arrow: <path d="m14 5-7 7 7 7M7 12h14"/>,
    close: <path d="m6 6 12 12M6 18 18 6"/>,
    chat: <path d="M20 11a8 8 0 0 1-8 8H5l-3 3V11a9 9 0 0 1 18 0Z"/>,
    plus: <path d="M12 5v14M5 12h14"/>,
    minus: <path d="M5 12h14"/>,
    shop: <><path d="M3 9h18l-2-6H5L3 9ZM5 10v11h14V10M9 21v-7h6v7"/></>,
    check: <path d="m5 12 4 4L20 5"/>,
  };
  return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>{shapes[name] || shapes.bag}</svg>;
}
function Modal({title, children, close, wide = false}) {
  const ref = useRef();
  useEffect(() => { ref.current.showModal(); const old = document.body.style.overflow; document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = old; }; }, []);
  return <dialog ref={ref} className={wide ? 'modal wide' : 'modal'} onCancel={e => {e.preventDefault(); close();}} onClick={e => {if (e.target === ref.current) close();}}>
    <div className="modal-head"><h2>{title}</h2><button className="icon-btn" aria-label="بستن" onClick={close}><Icon name="close"/></button></div>{children}
  </dialog>;
}
function App() {
  const [search, setSearch] = useState(''), [query, setQuery] = useState(''), [category, setCategory] = useState(0), [page, setPage] = useState(1);
  const [catalog, setCatalog] = useState({products: [], categories: [], pages: 1}), [loading, setLoading] = useState(true), [error, setError] = useState('');
  const [cart, setCart] = useState({items: [], count: 0, coupons: [], total: ''}), [token, setToken] = useState(''), [cartError, setCartError] = useState('');
  const [busy, setBusy] = useState(false), [cartOpen, setCartOpen] = useState(false), [product, setProduct] = useState(null), [coupon, setCoupon] = useState(''), [toast, setToast] = useState('');
  const [retry, setRetry] = useState(0);
  const mutation = useRef(false);
  useEffect(() => { document.title = cfg.name; }, []);
  useEffect(() => { const t = setTimeout(() => {setQuery(search.trim()); setPage(1);}, 350); return () => clearTimeout(t); }, [search]);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    fetch(catalogUrl(cfg.catalog, {search: query, category, page}), {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce':cfg.restNonce || ''}})
      .then(r => decodeResponse(r)).then(setCatalog).catch(e => {if (e.name !== 'AbortError') setError(e.message);}).finally(() => {if (!controller.signal.aborted) setLoading(false);});
    return () => controller.abort();
  }, [query, category, page, retry]);
  async function bootstrap() {
    setCartError('');
    try { const data = await decodeResponse(await fetch(cartUrl(cfg.cart, 'bootstrap'), {credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce':cfg.restNonce || ''}}), true); setToken(data.token); setCart(data.cart); }
    catch (e) {setCartError(e.message); setToken('');}
  }
  useEffect(() => { bootstrap(); }, []);
  useEffect(() => {
    if (!cfg.productId) return;
    const controller = new AbortController();
    fetch(cfg.product + cfg.productId, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce':cfg.restNonce || ''}})
      .then(r => decodeResponse(r)).then(setProduct).catch(e => {if (e.name !== 'AbortError') setError(e.message);});
    return () => controller.abort();
  }, []);
  useEffect(() => { if (!toast) return; const t = setTimeout(() => setToast(''), 3500); return () => clearTimeout(t); }, [toast]);
  async function updateCart(op, fields) {
    if (mutation.current || !token) return;
    mutation.current = true; setBusy(true); setCartError('');
    try {
      const data = await decodeResponse(await fetch(cartUrl(cfg.cart, op), {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'X-Balika-Cart':token, 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:new URLSearchParams(fields)}), true);
      setCart(data.cart); setToast(op === 'add' ? 'محصول به سبد اضافه شد' : 'سبد خرید به‌روز شد');
      if (op === 'add') {setProduct(null); setCartOpen(true);}
      if (op === 'coupon') setCoupon('');
    } catch(e) { setCartError(e.message); setCartOpen(true); }
    finally {mutation.current = false; setBusy(false);}
  }
  function purchase(p) {
    if (!p.available) return <span className="unavailable">ناموجود</span>;
    if (!p.direct) return <a className="button primary" href={p.url}>انتخاب و خرید در سایت <Icon name="arrow"/></a>;
    return <button className="button primary" disabled={busy || !token} onClick={() => updateCart('add', {product_id:p.id, quantity:1})}><Icon name="plus"/> افزودن به سبد</button>;
  }
  return <>
    <div className="topline">انتخاب در فروشگاه · پرداخت امن با درگاه‌های سایت</div>
    <header className="header"><a className="brand" href={cfg.home}><span className="brand-mark"><Icon name="bag"/></span><span>{cfg.name}<small>فروشگاه آنلاین</small></span></a>
      <div className="header-actions"><a className="desktop-link" href={cfg.account}><Icon name="user"/> حساب و سفارش‌ها</a><button className="cart-button" onClick={() => setCartOpen(true)}><Icon name="bag"/><span>سبد خرید</span><b>{fa(cart.count)}</b></button></div>
    </header>
    <main>
      <section className="hero"><div className="hero-copy"><div className="eyebrow"><span/> انتخاب راحت، خرید مطمئن</div><h1>فروشگاه شما،<br/><em>همیشه در دسترس.</em></h1><p>محصول دلخواهت را پیدا کن، سبدت را بساز و خرید را با درگاه امن فروشگاه تکمیل کن.</p><a href="#products" className="hero-link">کشف محصولات <Icon name="arrow"/></a></div>
        <div className="hero-art" aria-hidden="true"><div className="orbit o1"/><div className="orbit o2"/><div className="art-bag"><Icon name="bag" width="110" height="110"/></div><div className="art-tag"><Icon name="check"/> انتخاب شما</div><span className="art-spark">✦</span></div></section>
      <section className="catalog-section" id="products"><div className="section-title"><div><div className="eyebrow">از فروشگاه انتخاب کنید</div><h2>{query ? `نتایج «${query}»` : 'محصولات فروشگاه'}</h2></div><label className="search"><Icon name="search"/><input type="search" maxLength="65" placeholder="جست‌وجوی محصول…" value={search} onChange={e => setSearch(e.target.value)} aria-label="جست‌وجوی محصول"/></label></div>
        <div className="categories" aria-label="دسته‌بندی محصولات"><button className={!category ? 'active' : ''} onClick={() => {setCategory(0);setPage(1);}}>همه محصولات</button>{catalog.categories.map(c => <button key={c.id} className={category === c.id ? 'active' : ''} onClick={() => {setCategory(c.id);setPage(1);}}>{c.name}</button>)}</div>
        {cartError && !cartOpen && <div className="notice" role="alert">{cartError} <button onClick={bootstrap}>بررسی دوباره سبد</button></div>}
        {error ? <div className="empty" role="alert"><Icon name="search"/><h3>دریافت محصولات ممکن نشد</h3><p>{error}</p><button className="button" onClick={() => setRetry(x => x+1)}>تلاش دوباره</button></div> : loading ? <div className="products" aria-label="در حال دریافت محصولات" aria-busy="true">{Array.from({length:8},(_,i) => <div key={i} className="skeleton"><div/><span/><span/></div>)}</div> : catalog.products.length ? <>
          <div className="products">{catalog.products.map(p => <article key={p.id} className="product-card"><button className="product-image" onClick={() => setProduct(p)} aria-label={`مشاهده ${p.name}`}><img src={p.image} alt={p.name} loading="lazy" decoding="async"/>{p.on_sale && <span className="sale">پیشنهاد فروشگاه</span>}</button><div className="product-info"><span className="availability">{p.available ? 'قابل سفارش' : 'ناموجود'}</span><button className="product-name" onClick={() => setProduct(p)}>{p.name}</button><div className="product-bottom"><span>{p.price || 'مشاهده قیمت در سایت'}</span><button aria-label={`جزئیات ${p.name}`} onClick={() => setProduct(p)}><Icon name="arrow"/></button></div></div></article>)}</div>
          <nav className="pagination" aria-label="صفحه‌های محصولات"><button className="button" disabled={page <= 1} onClick={() => setPage(p => p-1)}>قبلی</button><span>صفحه {fa(page)} از {fa(Math.max(1, catalog.pages))}</span><button className="button" disabled={page >= catalog.pages} onClick={() => setPage(p => p+1)}>بعدی</button></nav>
        </> : <div className="empty"><Icon name="search"/><h3>محصولی پیدا نشد</h3><p>دسته‌بندی یا عبارت جست‌وجو را تغییر بده.</p><button className="button" onClick={() => {setSearch('');setCategory(0);setPage(1);}}>نمایش همه محصولات</button></div>}
      </section>
      <section className="benefits"><div><Icon name="check"/><strong>پرداخت در سایت</strong><span>با درگاه‌های فعال فروشگاه</span></div><div><Icon name="user"/><strong>سفارش‌های شما</strong><a href={cfg.account}>مشاهده در حساب کاربری</a></div><div><Icon name="chat"/><strong>کنار شما هستیم</strong><a href={cfg.support || cfg.home}>{cfg.support ? 'ارتباط با پشتیبانی' : 'بازگشت به سایت فروشگاه'}</a></div></section>
    </main>
    <footer><span>{cfg.name}</span><p>مبلغ نهایی، هزینه ارسال و شرایط خرید در صفحه پرداخت مشخص می‌شود.</p><small>فروشگاه مستقل بالیکا · متصل به ووکامرس</small></footer>
    <nav className="mobile-nav" aria-label="ناوبری اصلی"><a href="#products"><Icon name="shop"/>فروشگاه</a><button onClick={() => setCartOpen(true)}><Icon name="bag"/>سبد ({fa(cart.count)})</button><a href={cfg.account}><Icon name="user"/>حساب من</a>{cfg.support && <a href={cfg.support}><Icon name="chat"/>پشتیبانی</a>}</nav>
    {toast && <div className="toast" role="status"><Icon name="check"/>{toast}</div>}
    {product && <Modal title="جزئیات محصول" close={() => setProduct(null)} wide><div className="product-detail"><img src={product.image} alt={product.name}/><div><span className="availability">{product.available ? 'قابل سفارش' : 'ناموجود'}</span><h2>{product.name}</h2><p>{product.description || 'اطلاعات تکمیلی این محصول در صفحه اصلی فروشگاه در دسترس است.'}</p><strong className="detail-price">{product.price}</strong>{purchase(product)}{product.direct && !token && <p className="notice">سبد آماده نیست؛ <a href={product.url}>از صفحه محصول ادامه بدهید.</a></p>}<a className="text-link" href={product.url}>اطلاعات کامل در سایت <Icon name="arrow"/></a>{!product.direct && <small>انتخاب ویژگی‌ها و شرایط اختصاصی در صفحه محصول انجام می‌شود.</small>}</div></div></Modal>}
    {cartOpen && <Modal title={`سبد خرید شما · ${fa(cart.count)} کالا`} close={() => setCartOpen(false)}>
      {cartError && <div className="notice" role="alert">{cartError} {!token && <button onClick={bootstrap}>تازه‌سازی سبد</button>}</div>}
      {!cart.items.length ? <div className="empty"><Icon name="bag" width="45" height="45"/><h3>سبدت هنوز خالی است</h3><p>از میان محصولات، انتخابت را به سبد اضافه کن.</p><button className="button primary" onClick={() => setCartOpen(false)}>ادامه خرید</button></div> : <><div className="cart-items">{cart.items.map(item => <div key={item.key} className="cart-item"><img src={item.image} alt=""/><div className="cart-line"><strong>{item.name}</strong><span>{item.total}</span><div className="quantity"><button disabled={busy || !token || item.quantity >= 99} aria-label={`افزایش تعداد ${item.name}`} onClick={() => updateCart('quantity', {key:item.key, quantity:quantityValue(item.quantity+1)})}><Icon name="plus" width="15"/></button><span>{fa(item.quantity)}</span><button disabled={busy || !token} aria-label={`کاهش تعداد ${item.name}`} onClick={() => updateCart('quantity', {key:item.key, quantity:Math.max(0,item.quantity-1)})}><Icon name="minus" width="15"/></button><button className="remove" disabled={busy || !token} onClick={() => updateCart('remove',{key:item.key})}>حذف</button></div></div></div>)}</div>
      <form className="coupon" onSubmit={e => {e.preventDefault();updateCart('coupon',{coupon});}}><input value={coupon} maxLength="100" onChange={e => setCoupon(e.target.value)} placeholder="کد تخفیف دارید؟" aria-label="کد تخفیف"/><button disabled={busy || !token || !coupon.trim()}>اعمال کد</button></form>
      {cart.coupons?.map(c => <div className="coupon-chip" key={c}>{c}<button disabled={busy || !token} onClick={() => updateCart('remove_coupon',{coupon:c})} aria-label={`حذف کد ${c}`}><Icon name="close" width="14"/></button></div>)}
      <div className="cart-total"><span>جمع فعلی سبد</span><strong>{cart.total}</strong></div><p className="fine-print">هزینه ارسال، مالیات و شرایط نهایی در پرداخت ووکامرس بررسی می‌شوند. سبد با فروشگاه اصلی این مرورگر مشترک است.</p><a className={`button primary checkout ${busy ? 'disabled' : ''}`} href={cfg.checkout} aria-disabled={busy} onClick={e => {if(busy)e.preventDefault();}}>ادامه و پرداخت امن <Icon name="arrow"/></a></>}
    </Modal>}
  </>;
}
createRoot(document.getElementById('bon-storefront')).render(<App/>);
