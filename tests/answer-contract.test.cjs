'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { requiredSource, groundingError, toolResult } = require('../src/chat.cjs');

const page = { id: 21, kind: 'post', url: 'https://gpante.com/design-lesson/', excerpt: 'آموزش رایگان طراحی' };
const product = { id: 22, kind: 'product', url: 'https://gpante.com/product/design-course/',
  price: { amount: '50000', currency: 'IRT' }, attributes: [{ taxonomy: 'pa_file-format', terms: [{ name: 'DXF', slug: 'dxf' }] }] };
const evidence = (source, results, ok = true) => ({ name: 'Knowledge_Search', source, ok,
  status: ok ? 'ok' : 'transport_error', results });
const state = (chatInput, entries) => ({ chatInput, messages: [], evidence: entries });

test('uncertainty in another clause never exempts a positive claim from source and citation checks', () => {
  const empty = state('خرید بدون ایمیل ممکن است؟', [evidence('faq', [])]);
  for (const reply of ['نام فروشنده مشخص نیست؛ اما ایمیل برای خرید اجباری است.',
    'اطلاعات کافی نیست. ایمیل برای خرید اجباری است.',
    'ایمیل اجباری است و نام فروشنده مشخص نیست.',
    'ایمیل برای خرید اجباری است، نام فروشنده مشخص نیست.',
    'ایمیل اجباری است نام فروشنده مشخص نیست.']) {
    assert.equal(groundingError(empty, reply), 'source_unavailable', reply);
  }
  const found = state(empty.chatInput, [evidence('faq', [{ id: 'faq-email', url: 'https://gpante.com/faq-ai/#faq-email' }])]);
  assert.equal(groundingError(found, 'نام فروشنده مشخص نیست؛ ایمیل اختیاری است.'), 'reference_required');
  assert.equal(groundingError(found, 'نام فروشنده مشخص نیست؛ ایمیل اختیاری است.\n[مرجع](https://gpante.com/faq-ai/#faq-email)'), null);
  assert.equal(groundingError(empty, 'پاسخ مرتبطی پیدا نکردم.'), null);
  assert.equal(groundingError(empty, 'اطلاعات کافی نیست.'), null);
});

test('read failure and detailed-read checks remain active beside an unrelated limitation', () => {
  const failed = state('خرید بدون ایمیل ممکن است؟', [evidence('faq', [], false)]);
  assert.equal(groundingError(failed, 'منبع در دسترس نیست؛ اما ایمیل اجباری است.'), 'source_read_failed');
  assert.equal(groundingError(failed, 'اطلاعات فعلاً در دسترس نیست.'), null);
  assert.equal(groundingError(failed, 'متأسفانه، اطلاعات فعلاً در دسترس نیست.'), null);
  assert.equal(groundingError(failed, 'متأسفانه، اطلاعات فعلاً در دسترس نیست؛ اما ایمیل اجباری است.'), 'source_read_failed');
  assert.equal(groundingError(failed, 'اطلاعات فعلاً در دسترس نیست. برای پیگیری، با پشتیبانی تماس بگیرید.\n[تماس](https://gpante.com/contact-us/)'), null);
  const detailed = state('مراحل آموزش طراحی را توضیح بده', [evidence('site', [page])]);
  assert.equal(groundingError(detailed, 'نام نویسنده مشخص نیست؛ مرحله اول مستطیل رسم کن.\n[آموزش](' + page.url + ')'), 'content_read_required');
  assert.equal(groundingError(detailed, 'برای آموزش، این صفحه مرتبط است؛ جزئیات مراحل را نتوانستم بررسی کنم.\n[آموزش](' + page.url + ')'), null);
  const failedRead = { ...detailed, evidence: [...detailed.evidence, { name: 'Knowledge_Read', source: 'site', ok: false, status: 'api_error', results: [] }] };
  assert.equal(groundingError(failedRead, 'متن در دسترس نیست؛ مرحله اول مستطیل رسم کن.\n[آموزش](' + page.url + ')'), 'source_read_failed');
});

test('a generic-guidance label cannot exempt store-specific positive claims', () => {
  const empty = state('فایل DXF چیست؟', [evidence('faq', [])]);
  assert.equal(groundingError(empty, 'راهنمای عمومی: ایمیل برای خرید اجباری است.'), 'source_unavailable');
  assert.equal(groundingError(empty, 'راهنمای عمومی: این محصول برای MDF مناسب است.'), 'source_unavailable');
  assert.equal(groundingError(empty, 'راهنمای عمومی: فایل DXF را با نرم‌افزار سازگار باز کنید.'), null);
});

test('explicit content type precedes ordering while support and product constraints retain precedence', () => {
  for (const question of ['جدیدترین مقاله آموزش طراحی را معرفی کن', 'تازه‌ترین مقاله نصب نرم افزار را نشان بده',
    'جدیدترین مقاله درباره باکس را معرفی کن', 'تازه ترین مقاله درباره ساخت جعبه را معرفی کن', 'یک مقاله درباره استند می خواهم']) {
    assert.equal(requiredSource(state(question, [])), 'articles', question);
  }
  for (const question of ['جدیدترین آموزش طراحی را معرفی کن', 'جدیدترین دوره طراحی را میخواهم']) {
    assert.equal(requiredSource(state(question, [])), 'site', question);
  }
  assert.equal(requiredSource(state('جدیدترین فایل باکس CDR معرفی کن', [])), 'products');
  assert.equal(requiredSource(state('جدیدترین مقاله آموزش خرید بدون ایمیل را بگو', [])), 'faq');
  assert.equal(requiredSource(state('پرفروش‌های همین دسته را نشان بده', [])), 'products');
});

test('mixed source recommendations bind free text, prices and attributes to their own links', () => {
  const s = state('آموزش طراحی معرفی کن', [evidence('site', [page, product])]);
  const paid = '**دوره طراحی**\n| قیمت | ۵۰٬۰۰۰ تومان |\n| فرمت | DXF |\n[دوره](' + product.url + ')';
  for (const separator of ['\n', '\n\n']) {
    assert.equal(groundingError(s, '**مقاله رایگان**\n| فرمت | PDF |\n[مقاله](' + page.url + ')' + separator + paid), null);
  }
  const first = '[مقاله](' + page.url + ')\nآموزش رایگان\n[دوره](' + product.url + ')\nقیمت ۵۰٬۰۰۰ تومان\n| فرمت | DXF |';
  assert.equal(groundingError(s, first), null);
  assert.equal(groundingError(s, first.replace('۵۰٬۰۰۰', '۶۰٬۰۰۰')), 'price_mismatch');
  assert.equal(groundingError(s, paid.replace('DXF', 'CDR')), 'attribute_mismatch');
  assert.equal(groundingError(s, '| فرمت | CDR |\n\n[دوره](' + product.url + ')'), 'attribute_mismatch');
  assert.equal(groundingError(s, '[دوره](' + product.url + ')\nقیمت ۵۰٬۰۰۰ تومان'), null);
  assert.equal(groundingError(s, '**مقاله**\nقیمت ۵۰٬۰۰۰ تومان\n[مقاله](' + page.url + ')\n' + paid), 'unverified_price');
});

test('ambiguous multi-product price claims cannot silently borrow the next linked product', () => {
  const other = { ...product, id: 23, url: 'https://gpante.com/product/other-course/', price: { amount: '60000', currency: 'IRT' } };
  const s = state('دوره معرفی کن', [evidence('site', [product, other])]);
  assert.equal(groundingError(s, 'هر دو دوره ۵۰٬۰۰۰ تومان هستند.\n[دوره یک](' + product.url + ') [دوره دو](' + other.url + ')'), 'unverified_price');
  assert.equal(groundingError(s, '[دوره یک](' + product.url + ') [دوره دو](' + other.url + ')\nهر دو دوره ۵۰٬۰۰۰ تومان هستند.'), 'unverified_price');
});

test('unambiguous recommendation headings keep ordinary Markdown paragraph spacing usable', () => {
  const other = { ...product, id: 23, url: 'https://gpante.com/product/other-course/', price: { amount: '60000', currency: 'IRT' } };
  const s = state('دوره معرفی کن', [evidence('site', [product, other])]);
  const table = price => '| ویژگی | مشخصات |\n| --- | --- |\n| قیمت | ' + price + ' تومان |\n| فرمت | DXF |';
  const last = '**دوره اول**\n\n' + table('۵۰٬۰۰۰') + '\n\n[دوره یک](' + product.url + ')';
  const first = '**دوره دوم**\n\n[دوره دو](' + other.url + ')\n\n' + table('۶۰٬۰۰۰');
  assert.equal(groundingError(s, last), null);
  assert.equal(groundingError(s, first), null);
  assert.equal(groundingError(s, last + '\n\n' + first), null);
  assert.equal(groundingError(s, last + '\n\n' + first.replace('۶۰٬۰۰۰', '۵۰٬۰۰۰')), 'price_mismatch');
  assert.equal(groundingError(s, last + '\n\n' + first.replace('DXF', 'CDR')), 'attribute_mismatch');
});

test('incomplete discovery requires a coverage disclosure and cannot establish absence', () => {
  const coverage = { complete: false, invalidDocuments: 1, initializing: false };
  const empty = state('آموزش طراحی', [{ ...evidence('site', []), status: 'incomplete_source', coverage }]);
  const limitation = 'پوشش جست‌وجو کامل نیست؛ فعلاً نتوانستم همهٔ منابع را بررسی کنم.';
  assert.equal(groundingError(empty, 'آموزش مرتبطی پیدا نشد.'), 'source_incomplete');
  assert.equal(groundingError(empty, limitation), null);
  assert.equal(groundingError(empty, limitation + ' آموزش مرتبطی پیدا نشد.'), 'source_incomplete');
  assert.equal(groundingError(empty, limitation + ' مرحله اول مستطیل رسم کنید.'), 'source_unavailable');
  const partial = { ...empty, evidence: [{ ...empty.evidence[0], results: [page] }] };
  assert.equal(groundingError(partial, 'این آموزش مرتبط است.\n[آموزش](' + page.url + ')'), 'source_incomplete');
  assert.equal(groundingError(partial, 'پوشش جست‌وجو کامل نیست. این آموزش مرتبط است.\n[آموزش](' + page.url + ')'), null);
  assert.equal(groundingError({ ...partial, chatInput: 'طراحی' }, 'این منبع مرتبط است.\n[منبع](' + page.url + ')'), 'source_incomplete');
  const read = toolResult({ ...state('آموزش طراحی', []), pendingTool: { id: 'site_1',
    function: { name: 'Knowledge_Search', arguments: '{"source":"site"}' } } },
  { ok: true, source: 'site', status: 'incomplete_source', results: [], coverage: { ...coverage, secret: 'not-evidence' } });
  assert.deepEqual(read.evidence[0].coverage, coverage);
  assert.equal(groundingError(read, 'آموزش مرتبطی پیدا نشد.'), 'source_incomplete');
});

test('unbound free-price claims cannot bypass validation while free-source limitations remain usable', () => {
  const other = { ...product, id: 23, url: 'https://gpante.com/product/other-course/' };
  const s = state('دوره معرفی کن', [evidence('site', [product, other, page])]);
  assert.equal(groundingError(s, 'دوره اول رایگان است.\n\n[دوره اول](' + product.url + ')\n\n[دوره دوم](' + other.url + ')'), 'unverified_price');
  assert.equal(groundingError(s, 'دوره اول رایگان است.\n[تماس](https://gpante.com/contact-us/)\n\n[دوره اول](' + product.url + ')'), 'unverified_price');
  assert.equal(groundingError(s, '**آموزش رایگان**\n[مقاله](' + page.url + ')\n\n**دوره**\n۵۰٬۰۰۰ تومان\n[دوره](' + product.url + ')'), null);
  assert.equal(groundingError(state('آموزش رایگان طراحی', [evidence('site', [])]), 'آموزش رایگان مرتبطی پیدا نکردم.'), null);
});

test('conditional free-download procedure stays usable with separate FAQ and contact links', () => {
  const faq = { id: 'delivery', kind: 'faq', url: 'https://gpante.com/faq-ai/#delivery' };
  const s = state('چطور طرح رایگان را دانلود کنم؟', [evidence('faq', [faq])]);
  const links = '\n\n[راهنمای دریافت فایل](' + faq.url + ')\n\n[تماس](https://gpante.com/contact-us/)';
  for (const procedure of ['برای طرحی که در صفحه محصول رایگان معرفی شده، پس از تکمیل سفارش بخش دریافت فایل را بررسی کنید.',
    'اگر فایل رایگان باشد، پس از تکمیل سفارش جزئیات سفارش را بررسی کنید.',
    'اگر همان فایل در صفحهٔ محصول رایگان معرفی شده است، مراحل ثبت سفارش را ادامه دهید.',
    'اگر گزینهٔ انتخابی در صفحهٔ محصول رایگان اعلام شده باشد، راهنمای دریافت همان صفحه را دنبال کنید.',
    'اگر همان فایل یا گزینه در صفحهٔ محصول رایگان معرفی شده باشد، راهنمای دریافت را دنبال کنید.',
    'اگر فایل رایگان باشد، جزئیات سفارش را بررسی کنید. رایگان‌بودن یک گزینه به معنی رایگان‌بودن همهٔ گزینه‌های محصول نیست.']) {
    assert.equal(groundingError(s, procedure + links), null, procedure);
    assert.equal(groundingError(s, procedure + '\nاین محصول رایگان است.' + links), 'unverified_price');
  }
  assert.equal(groundingError(s, 'این محصول رایگان است.' + links), 'unverified_price');
  assert.equal(groundingError(s, 'همهٔ فایل‌ها رایگان هستند.' + links), 'unverified_price');
  for (const assertion of ['اگر فایل را بخرید این محصول رایگان است.', 'برای طرحی که می‌خرید این فایل رایگان است.',
    'اگر فایل را انتخاب کنید قیمت این محصول رایگان است.']) assert.equal(groundingError(s, assertion + links), 'unverified_price');
  const qualification = 'رایگان‌بودن یک گزینه به معنی رایگان‌بودن همهٔ گزینه‌های محصول نیست.';
  assert.equal(groundingError(s, qualification + links), null);
  assert.equal(groundingError(s, qualification + '\nهمهٔ فایل‌ها رایگان هستند.' + links), 'unverified_price');
  assert.equal(groundingError(s, qualification.replace('نیست', 'است') + links), 'unverified_price');
  assert.equal(groundingError(s, 'اگر فایل رایگان باشد، قیمت آن ۱۰ تومان است.' + links), 'unverified_price');
  assert.equal(groundingError(state(s.chatInput, []), 'اگر فایل رایگان باشد، جزئیات سفارش را بررسی کنید.'), 'source_required');
  assert.equal(groundingError(state(s.chatInput, [evidence('faq', [], false)]), 'اگر فایل رایگان باشد، جزئیات سفارش را بررسی کنید.'), 'source_read_failed');
});

test('short delivery follow-ups inherit only the latest relevant purchase context', () => {
  const followups = ['ارسالش چی میشه؟', 'کی می‌رسه؟', 'فایل را از کجا بگیرم؟'];
  const history = [{ role: 'user', content: 'فایل طرح باکس خریدم' }, { role: 'assistant', content: 'راهنمای قبلی' }];
  for (const q of followups) {
    const s = { ...state(q, []), messages: [...history, { role: 'user', content: q }] };
    assert.equal(requiredSource(s), 'faq', q);
    assert.equal(groundingError(s, 'فایل شما در حساب آماده دانلود است.'), 'source_required', q);
    const consecutive = { ...s, messages: [...s.messages, { role: 'assistant', content: 'راهنمای عمومی' }, { role: 'user', content: 'کی می‌رسه؟' }], chatInput: 'کی می‌رسه؟' };
    assert.equal(requiredSource(consecutive), 'faq');
    const changed = { ...s, messages: [...history, { role: 'user', content: 'دوره کورل معرفی کن' }, { role: 'user', content: q }] };
    assert.notEqual(requiredSource(changed), 'faq', q);
  }
  for (const q of ['قیمت این فایل چنده؟', 'فرمت این محصول چیست؟', 'آموزش نصب کورل را بگو']) {
    assert.notEqual(requiredSource({ ...state(q, []), messages: [...history, { role: 'user', content: q }] }), 'faq', q);
  }
  assert.equal(requiredSource(state('کی می‌رسه؟', [])), null);
  assert.equal(requiredSource({ ...state('فایل را از کجا بگیرم؟', []), messages: [
    { role: 'user', content: 'دوره کورل خریدم' }, { role: 'user', content: 'فایل را از کجا بگیرم؟' }] }), 'faq');
  assert.equal(requiredSource({ ...state('کی می‌رسه دورهٔ جدید آموزش کورل؟', []), messages: history }), 'site');
});

test('design licence and sharing policy require current FAQ, while explicit rules retain guides', () => {
  for (const question of ['مجوز فایل خریداری شده چه مدتی دارد؟', 'فایل طرح برای همیشه در دسترس است؟',
    'طرح را در اختیار دیگران بگذارم؟', 'فروش مجدد فایل مجاز است؟']) {
    assert.equal(requiredSource(state(question, [])), 'faq');
    assert.equal(groundingError(state(question, []), 'مجوز استفاده محدودیت زمانی ندارد.'), 'source_required');
  }
  assert.equal(requiredSource(state('متن قوانین استفاده و مجوز فایل را بگو', [])), 'guides');
  assert.equal(requiredSource(state('طرح همیشه بهار دارید؟', [])), 'products');
});
