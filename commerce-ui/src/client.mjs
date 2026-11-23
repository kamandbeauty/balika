export function catalogUrl(endpoint, {search = '', category = 0, page = 1} = {}) {
  const url = new URL(endpoint);
  url.searchParams.set('page', String(page));
  if (search) url.searchParams.set('search', search);
  if (category) url.searchParams.set('category', String(category));
  return url.toString();
}
export function cartUrl(endpoint, op) {
  const url = new URL(endpoint);
  url.searchParams.set('op', op);
  return url.toString();
}
export async function decodeResponse(response, wrapped = false) {
  let payload;
  try { payload = await response.json(); } catch { throw new Error('پاسخ نامعتبر از سایت؛ اتصال یا تنظیمات کش را بررسی کنید.'); }
  if (!response.ok || (wrapped && !payload.success)) {
    throw new Error(payload?.data?.message || payload?.message || 'درخواست انجام نشد. دوباره تلاش کنید.');
  }
  return wrapped ? payload.data : payload;
}
export function quantityValue(value) {
  if (!/^\d{1,2}$/.test(String(value))) return null;
  return Number(value);
}
