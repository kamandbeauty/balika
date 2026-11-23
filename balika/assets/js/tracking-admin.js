(() => {
  'use strict';
  const form = document.getElementById('bon-tracking-form');
  if (!form) return;
  const fields = [...form.querySelectorAll('input:not([type="hidden"])')];
  const value = el => el.type === 'checkbox' ? el.checked : el.value;
  const initial = new Map(fields.map(el => [el, value(el)]));
  let submitting = false;
  const dirtyRows = () => new Set(fields.filter(el => value(el) !== initial.get(el)).map(el => el.closest('tr'))).size;
  const update = () => {
    const count = dirtyRows();
    document.getElementById('bon-tracking-dirty').textContent = count ? `${count} ردیف تغییر کرده؛ هنوز ثبت نشده است.` : '';
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('pageshow', () => { submitting = false; update(); });
  window.addEventListener('beforeunload', event => {
    if (!submitting && dirtyRows()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
