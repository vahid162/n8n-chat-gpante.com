'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { admitted, modelRequest, modelResult, toolResult, requiredSource, groundingError, finish } = require('../src/chat.cjs');
const product = { id: 1, name: 'باکس', url: 'https://gpante.com/product/box/', price: { amount: '50000', currency: 'IRT' },
  attributes: [{ taxonomy: 'pa_file-format', terms: [{ name: 'DXF', slug: 'dxf' }] },
    { taxonomy: 'pa_thickness', terms: [{ name: '2.7', slug: '2.7' }] },
    { taxonomy: 'pa_materials-required', terms: [{ name: 'MDF', slug: 'mdf' }] }] };
const faq = { id: 'faq-email', url: 'https://gpante.com/faq-ai/#faq-email', links: [] };
const facts = (source, results = [faq]) => ({ name: 'Knowledge_Search', source, ok: true, results });
const state = (chatInput, evidence = []) => ({ chatInput, messages: [{ role: 'user', content: chatInput }], callIndex: 1, toolCount: evidence.length, evidence });

test('purchase delivery questions read FAQ while unrelated product names retain product intent', () => {
  for (const q of ['خریدم کی ارسال میشه؟', 'امروز خرید کردم چه روزی ارسال میشه؟',
    'بعد از پرداخت فایل از کجا تحویل میگیرم؟', 'سفارشم با پست ارسال میشه؟']) {
    assert.equal(requiredSource(state(q)), 'faq', q);
  }
  assert.equal(requiredSource(state('طرح پستچی دارید؟')), 'products');
  assert.equal(requiredSource(state('طرح کامیون حمل بار دارید؟')), 'products');
  assert.equal(requiredSource(state('جنس این محصول چیست؟')), 'products');
  assert.equal(requiredSource(state('این ماکت از چه موادی ساخته می‌شود؟')), 'products');
  assert.equal(requiredSource(state('فایل DXF چیست؟')), 'faq');
});

test('a linked verified virtual download must be introduced as a downloadable design file', () => {
  const item = { ...product, virtual: true, downloadable: true };
  const s = state('ماکت ساختمان دارید؟', [{ name: 'Product_Search', source: 'products', ok: true, status: 'ok', results: [item] }]);
  const link = '[مشاهدهٔ محصول](' + product.url + ')';
  assert.equal(groundingError(s, 'بله موجود است.\n' + link), 'product_type_required');
  assert.equal(groundingError(s, 'این محصول فایل طراحی قابل دانلود است، نه ماکت آماده.\n' + link), null);
  for (const description of ['این محصول ماکت آماده است و فایل دانلودی نیست.',
    'این فایل قابل دانلود نیست؛ ماکت آماده دریافت می‌کنید.',
    'این فایل دیجیتال است و ماکت آماده هم ارسال می‌شود.',
    'فایل طراحی قابل دانلود است و با پست ارسال می‌شود.',
    'این محصول فایل طراحی قابل دانلود است و ماکت آماده بدون هزینه ارسال می‌شود.']) {
    const reply = description + '\n' + link;
    assert.equal(groundingError(s, reply), 'product_type_required', description);
    const result = modelResult({ ...s, callIndex: 4 }, { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 },
      choices: [{ finish_reason: 'stop', message: { content: reply } }] } });
    assert.equal(result.success, undefined);
    assert.equal(result.response.error.code, 'grounding_product_type_required');
  }
  assert.equal(groundingError(s, 'این محصول فایل طراحی قابل دانلود است و ماکت آماده ارسال نمی‌شود.\n' + link), null);
  assert.equal(groundingError(s, '**طرح ماکت آماده**\nفایل طراحی قابل دانلود است.\n' + link), null);
  assert.equal(groundingError(s, 'طرح ماکت آماده یک فایل طراحی قابل دانلود است.\n' + link), null);
  assert.equal(groundingError(s, 'طرح پستچی یک فایل طراحی قابل دانلود است.\n' + link), null);
  assert.equal(groundingError(s, 'فایل طراحی قابل دانلود است و ماکت آماده بدون هزینه ارسال نمی‌شود.\n' + link), null);
  assert.equal(groundingError(s, 'این محصول نه ماکت آماده است بلکه یک فایل طراحی قابل دانلود است.\n' + link), null);
  assert.equal(groundingError(s, 'این محصول نه یک ماکت آماده است و نه کالای فیزیکی است؛ فایل طراحی قابل دانلود است.\n' + link), null);
  assert.equal(groundingError(s, 'این محصول نه **ماکت آماده است** بلکه یک **فایل طراحی قابل دانلود** است.\n' + link), null);
  assert.equal(groundingError(s, 'فایل طراحی قابل دانلود است و **ماکت آماده بدون هزینه ارسال می‌شود**.\n' + link), 'product_type_required');
  for (const flags of [{}, { virtual: null, downloadable: null }, { virtual: false, downloadable: false },
    { virtual: false, downloadable: true }]) {
    assert.equal(groundingError({ ...s, evidence: [{ ...s.evidence[0], results: [{ ...product, ...flags }] }] }, 'این گزینه موجود است.\n' + link), null);
  }
});

test('normalizing existing referral labels always preserves the response and replay length cap', () => {
  const s = state('طراحی سفارشی می خواهم', [facts('faq', [])]);
  const start = 'اطلاعات کافی نیست.';
  const tail = '\n[x](tel:+989179509010)\n[c](https://gpante.com/contact-us/)';
  const content = start + ' '.repeat(8000 - start.length - tail.length) + tail;
  assert.equal(content.length, 8000);
  const result = modelResult(s, { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 },
    choices: [{ finish_reason: 'stop', message: { content } }] } });
  assert.equal(result.success, true);
  assert(result.output.length <= 8000);
  assert.equal(result.output.split('tel:+989179509010').length, 2);
  assert.equal(result.output.split('https://gpante.com/contact-us/').length, 2);
  const replay = admitted({}, { allowed: true, replay: true, requestId: 'a'.repeat(32), output: result.output });
  assert.equal(replay.status, 200);
  assert.equal(replay.response.output, result.output);
});

test('raw and Markdown approved referral destinations normalize to one phone/contact pair', () => {
  const s = state('طراحی اختصاصی می خواهم', [facts('faq')]);
  const phone = 'tel:+989179509010';
  const contact = 'https://gpante.com/contact-us/';
  for (const existing of [phone + '\n' + contact, '[x](' + phone + ')\n' + phone + '\n[c](' + contact + ')\n' + contact]) {
    const content = 'برای بررسی طراحی، این راهنما را ببینید.\n[راهنما](' + faq.url + ')\n' + existing;
    const result = modelResult(s, { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 },
      choices: [{ finish_reason: 'stop', message: { content } }] } });
    assert.equal(result.success, true);
    assert.equal(result.output.split(phone).length, 2);
    assert.equal(result.output.split(contact).length, 2);
    assert(result.output.includes('09179509010'));
  }
});

test('custom design and honest product absence return the approved call and contact links together', () => {
  const done = (s, output) => modelResult(s, { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 },
    choices: [{ finish_reason: 'stop', message: { content: output } }] } });
  const phone = '[تماس مستقیم با طراح: 09179509010](tel:+989179509010)';
  const contact = '[صفحهٔ تماس با ما](https://gpante.com/contact-us/)';
  const absence = 'طرح مرتبطی پیدا نکردم.';
  const custom = state('طراحی سفارشی می خواهم', [facts('faq', [])]);
  const missing = state('طرح صندوق دارید؟', [{ name: 'Product_Search', source: 'products', ok: true, status: 'no_matches', results: [] }]);
  for (const s of [custom, missing]) {
    const result = done(s, absence);
    assert.equal(result.success, true);
    assert(result.output.includes(phone));
    assert(result.output.includes(contact));
    assert.equal(result.response.output, result.output);
  }
  const paired = done(custom, absence + '\n' + phone + '\n' + contact);
  assert.equal(paired.success, true);
  assert.equal(paired.output.split('tel:+989179509010').length, 2);
  assert.equal(paired.output.split('https://gpante.com/contact-us/').length, 2);
  const unavailable = { ...missing, evidence: [{ ...missing.evidence[0], ok: false, status: 'transport_error' }] };
  assert(!done(unavailable, 'اطلاعات فعلاً در دسترس نیست.').output.includes('tel:'));
  const incomplete = { ...missing, evidence: [{ ...missing.evidence[0], status: 'incomplete_source', coverage: { complete: false } }] };
  assert(!done(incomplete, 'اطلاعات فعلاً در دسترس نیست.').output.includes('tel:'));
  const found = { ...missing, evidence: [{ ...missing.evidence[0], status: 'ok', results: [product] }] };
  assert(!done(found, 'این گزینه موجود است.\n[محصول](' + product.url + ')').output.includes('tel:'));
  assert(!finish({ output: absence }).includes('tel:'));
  const clause = 'اطلاعات کافی نیست.\n';
  const long = done(custom, clause.repeat(Math.floor(7990 / clause.length)).trim());
  assert.equal(long.success, true);
  assert(long.output.length <= 8000);
  assert(long.output.includes(phone) && long.output.includes(contact));
});

test('source requirements are narrow, preserve explicit intent, and use context only for follow-ups', () => {
  for (const q of ['ایمیل ندارم می‌توانم خرید کنم؟', 'شماره همراه برای خرید اجباریه؟', 'زبانه تو شیار جا نمیره', 'DXF چیست؟', 'طراحی اختصاصی می‌خواهم']) assert.equal(requiredSource(state(q)), 'faq', q);
  assert.equal(requiredSource(state('طرح استند موبایل دارید؟')), 'products');
  assert.equal(requiredSource(state('باکس MDF معرفی کن و بگو بدون ایمیل میشه خرید؟')), 'faq');
  assert.equal(requiredSource(state('آموزش کورل')), 'site');
  assert.equal(requiredSource(state('متن قوانین بازپرداخت')), 'guides');
  assert.equal(requiredSource(state('سلام')), null);
  assert.equal(requiredSource(state('پایتخت فرانسه کجاست؟')), null);
  assert.equal(requiredSource(state('قیمت دلار چنده؟')), null);
  assert.equal(requiredSource({ ...state('ارزان‌ترش را داری؟'), messages: [{ role: 'user', content: 'باکس MDF دارید؟' }, { role: 'assistant', content: 'گزینه قبلی' }, { role: 'user', content: 'ارزان‌ترش را داری؟' }] }), 'products');
});

test('factual answers require the right current-turn source; greeting needs no source', () => {
  assert.equal(groundingError(state('سلام'), 'سلام؛ چطور کمک کنم؟'), null);
  assert.equal(groundingError(state('ایمیل ندارم می‌توانم خرید کنم؟'), 'ایمیل الزامی است.'), 'source_required');
  assert.equal(groundingError(state('ایمیل ندارم می‌توانم خرید کنم؟', [facts('guides')]), 'ایمیل الزامی است.'), 'source_required');
  assert.equal(groundingError(state('ایمیل ندارم می‌توانم خرید کنم؟', [facts('faq')]), 'ایمیل اختیاری است. [مرجع](' + faq.url + ')'), null);
  assert.equal(groundingError(state('ایمیل ندارم می‌توانم خرید کنم؟', [facts('faq')]), 'ایمیل اختیاری است.'), 'reference_required');
});

test('citations must come from this turn, including exact FAQ anchors and design references', () => {
  const s = state('ایمیل ندارم می‌توانم خرید کنم؟', [facts('faq')]);
  assert.equal(groundingError(s, '[مرجع](https://gpante.com/faq-ai/#faq-made-up)'), 'unverified_reference');
  assert.equal(groundingError(s, '[تلگرام](https://t.me/gpante_designer)'), 'unverified_reference');
  assert.equal(groundingError({ ...s, evidence: [facts('faq', [{ ...faq, links: [{ url: 'https://t.me/gpante_designer' }] }])] }, '[مرجع](' + faq.url + ') [تلگرام](https://t.me/gpante_designer)'), null);
  assert.equal(groundingError(state('باکس دارید؟'), '[محصول](' + product.url + ')'), 'source_required');
});

test('product price and supported attributes are checked against the linked product, not another result', () => {
  const s = state('باکس MDF دارید؟', [{ name: 'Product_Search', ok: true, results: [product, { ...product, id: 2, url: 'https://gpante.com/product/other/', price: { amount: '60000', currency: 'IRT' } }] }]);
  const reply = price => '**باکس**\n| ویژگی | مشخصات |\n| قیمت | ' + price + ' |\n| فرمت | DXF |\n| ضخامت | ۲٫۷ میلی‌متر |\n| متریال | MDF |\n[محصول](' + product.url + ')';
  assert.equal(groundingError(s, reply('۵۰٬۰۰۰ تومان')), null);
  assert.equal(groundingError(s, reply('۵۰ هزار تومان')), null);
  assert.equal(groundingError(s, reply('۶۰٬۰۰۰ تومان')), 'price_mismatch');
  assert.equal(groundingError(s, reply('۵۰٬۰۰۰ ریال')), 'price_mismatch');
  assert.equal(groundingError(s, reply('رایگان')), 'price_mismatch');
  for (const [from, to] of [['DXF', 'CDR'], ['۲٫۷ میلی‌متر', '۳ میلی‌متر'], ['MDF', 'پلکسی']]) assert.equal(groundingError(s, reply('۵۰٬۰۰۰ تومان').replace(from, to)), 'attribute_mismatch');
  assert.equal(groundingError(s, reply('۵۰٬۰۰۰ تومان').replace(product.url, 'https://gpante.com/product/invented/')), 'unverified_reference');
});

test('decimal thickness names and canonical slugs never authorize their integer components', () => {
  for (const term of [
    { name: '۲٫۷ میلیمتر', slug: '2-7' },
    { name: '', slug: '2-7' },
    { name: '۲٫۶ میلیمتر', slug: '2-6-%d9%85%db%8c%d9%84%db%8c%d9%85%d8%aa%d8%b1' },
    { name: '۲٫۴۵ میلیمتر', slug: '2-45-mm' },
  ]) {
    const expected = term.slug.startsWith('2-45') ? '2.45' : term.slug.startsWith('2-6') ? '2.6' : '2.7';
    const item = { ...product, attributes: [{ taxonomy: 'pa_thickness', terms: [term] }] };
    const s = state('باکس دارید؟', [{ name: 'Product_Search', ok: true, results: [item] }]);
    const reply = thickness => '| ضخامت | ' + thickness + ' میلی‌متر |\n[محصول](' + item.url + ')';
    for (const value of [expected, expected.replace('.', '-'), expected.replace('.', ',')]) {
      assert.equal(groundingError(s, reply(value)), null, JSON.stringify({ term, value }));
    }
    for (const value of expected.split('.')) {
      assert.equal(groundingError(s, reply(value)), 'attribute_mismatch', JSON.stringify({ term, value }));
    }
  }
});

test('explicit CDR table versions match the linked product while generic CDR remains valid', () => {
  const item = { ...product, attributes: [{ taxonomy: 'pa_file-format', terms: [{ name: 'CDR نسخه ۱۵', slug: 'cdr-v15' }] }] };
  const s = state('باکس دارید؟', [{ name: 'Product_Search', ok: true, results: [item] }]);
  const reply = format => '| فرمت | ' + format + ' |\n[محصول](' + item.url + ')';
  for (const format of ['CDR', 'CDR۱۵', 'CDR ۱۵', 'CDR نسخه۱۵', 'CDR نسخهٔ۱۵', 'CDR (نسخه۱۵)', 'CDR:۱۵', 'CDR v15', 'CDR version 15', 'cdr-v15', 'کورل ۱۵']) {
    assert.equal(groundingError(s, reply(format)), null, format);
  }
  for (const format of ['CDR۲۰', 'CDR ۲۰', 'CDR نسخه۲۰', 'CDR نسخهٔ۲۰', 'CDR (نسخه۲۰)', 'CDR:۲۰', 'CDR v20', 'CDR version 20', 'cdr-v20', 'کورل ۲۰', 'CDR۱۵ و CDR۲۰', 'DXF']) {
    assert.equal(groundingError(s, reply(format)), 'attribute_mismatch', format);
  }
  const all = { ...s, evidence: [{ name: 'Product_Search', ok: true, results: [{ ...item, attributes: [{ taxonomy: 'pa_file-format', terms: [{ name: 'تمام نسخه‌های CDR', slug: 'all-cdr' }] }] }] }] };
  assert.equal(groundingError(all, reply('CDR')), null);
  assert.equal(groundingError(all, reply('all-cdr')), null);
  for (const format of ['CDR15', 'CDR نسخهٔ۲۰', 'CDR version 20']) assert.equal(groundingError(all, reply(format)), null, format);
  const v13 = { ...s, evidence: [{ name: 'Product_Search', ok: true, results: [{ ...item, attributes: [{ taxonomy: 'pa_file-format', terms: [{ name: 'کورل ۱۳', slug: 'cdr-v3' }] }] }] }] };
  for (const format of ['کورل ۱۳', 'CDR13', 'CDRv13', 'cdr-v3']) assert.equal(groundingError(v13, reply(format)), null, format);
  assert.equal(groundingError(v13, reply('CDR۲۰')), 'attribute_mismatch');
});

test('failed/empty reads permit an honest abstention but cannot authorize invented facts', () => {
  const s = state('باکس دارید؟', [{ name: 'Product_Search', ok: false, status: 'transport_error', results: [] }]);
  assert.equal(groundingError(s, 'اطلاعات محصولات فعلاً در دسترس نیست.'), null);
  assert.equal(groundingError(s, 'باکس ۵۰٬۰۰۰ تومان است.'), 'unverified_price');
  assert.equal(groundingError(state('باکس دارید؟', [{ name: 'Product_Search', ok: true, results: [] }]), 'طرح مرتبطی پیدا نشد.'), null);
  assert.equal(groundingError(state('ایمیل ندارم می‌توانم خرید کنم؟', [facts('faq', [])]), 'ایمیل اختیاری است.'), 'source_unavailable');
});

test('rejected content is corrected within existing calls; the last round fails closed', () => {
  const response = { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 }, choices: [{ finish_reason: 'stop', message: { content: 'ایمیل الزامی است.' } }] } };
  const s = modelRequest(admitted({ chatInput: 'ایمیل ندارم می‌توانم خرید کنم؟', action: 'sendMessage' }, { allowed: true, requestId: 'a'.repeat(32), messages: [] }), 'system');
  const retry = modelResult(s, response);
  assert.equal(retry.done, false); assert.equal(retry.success, undefined);
  assert.equal(retry.toolsPending, false);
  assert.equal(retry.groundingError, 'source_required');
  assert.equal(retry.messages.at(-1).role, 'system');
  assert(!JSON.stringify(retry.messages).includes('ایمیل الزامی است.'));
  const last = modelResult({ ...s, callIndex: 4 }, response);
  assert.equal(last.response.error.code, 'grounding_source_required');
  const pending = { ...s, pendingTool: { id: 'a', function: { name: 'Knowledge_Search', arguments: '{"source":"faq"}' } } };
  const read = toolResult(pending, { ok: true, kind: 'knowledge', source: 'faq', results: [faq] });
  assert.equal(read.evidence[0].source, 'faq');
  assert.equal(read.evidence[0].results[0].id, faq.id);
});

test('workflow sends a rejected final directly to the next model round without a phantom tool', () => {
  const { build } = require('../scripts/build-workflow.cjs');
  const workflow = build();
  for (let round = 1; round <= 3; round++) {
    assert.equal(workflow.connections['Reply Is Ready ' + round].main[1][0].node, 'Tools Remain ' + round);
    assert.equal(workflow.connections['Tools Remain ' + round].main[1][0].node, 'Prepare Model ' + (round + 1));
    assert.equal(workflow.connections['Tools Remain ' + round].main[0][0].node, 'Prepare Tool ' + round);
  }
});

test('category-only requests accept category evidence of the correct content type', () => {
  const url = 'https://gpante.com/category/tutorials/';
  const s = state('دسته‌های مقاله‌ها را می‌خواهم', [{ name: 'Category_Search', source: 'articles', ok: true, results: [{ id: 12, url }] }]);
  assert.equal(requiredSource(s), 'article_categories');
  assert.equal(groundingError(s, '[دسته آموزش](' + url + ')'), null);
  assert.equal(groundingError({ ...s, evidence: [{ ...s.evidence[0], source: 'products' }] }, '[دسته آموزش](' + url + ')'), 'source_required');
  const productCategory = { ...s, chatInput: 'لیست دسته‌های محصولات را نشان بده', evidence: [{ ...s.evidence[0], source: 'products' }] };
  assert.equal(requiredSource(productCategory), 'product_categories');
  assert.equal(groundingError(productCategory, '[دسته محصولات](' + url + ')'), null);
  assert.equal(requiredSource(state('پرفروش‌های همین دسته را نشان بده')), 'products');
});

test('successive product follow-ups retain the source gate and explicit topic changes reset it', () => {
  const messages = ['باکس MDF دارید؟', 'ارزان‌ترش را داری؟', 'ادامه', 'ادامه'].map(content => ({ role: 'user', content }));
  assert.equal(requiredSource({ ...state('ادامه'), messages }), 'products');
  assert.equal(requiredSource({ ...state('ادامه'), messages: [...messages, { role: 'user', content: 'پایتخت فرانسه کجاست؟' }, { role: 'user', content: 'ادامه' }] }), null);
});

test('a failed read cannot be reported as a successful search with no matches', () => {
  for (const status of ['api_error', 'transport_error', 'invalid_response', 'rate_limited', 'incomplete_response', 'constraint_mismatch', 'sort_unverifiable']) {
    const s = state('باکس دارید؟', [{ name: 'Product_Search', ok: false, status, results: [] }]);
    assert.equal(groundingError(s, 'طرح مرتبطی پیدا نشد.'), 'source_read_failed');
    assert.equal(groundingError(s, 'اطلاعات محصولات فعلاً در دسترس نیست.'), null);
  }
  assert.equal(groundingError(state('گزینه‌ای پیدا کردی؟', [{ name: 'Product_Search', ok: false, status: 'transport_error', results: [] }]), 'طرح مرتبطی پیدا نشد.'), 'source_read_failed');
});

test('practical checkout guidance takes precedence over the generic training keyword', () => {
  const s = state('آموزش خرید بدون ایمیل را بگو', [facts('faq')]);
  assert.equal(requiredSource(s), 'faq');
  assert.equal(groundingError(s, 'ایمیل اختیاری است. [مرجع](' + faq.url + ')'), null);
});

test('valid free prices, unknown attributes and separate product sections remain usable', () => {
  const free = { ...product, price: { amount: '0', currency: 'IRT' } };
  const second = { ...product, id: 2, url: 'https://gpante.com/product/second/', price: { amount: '12.50', currency: 'USD' } };
  const s = state('باکس دارید؟', [{ name: 'Product_Search', ok: true, results: [free, second] }]);
  assert.equal(groundingError(s, '**باکس**\n| قیمت | رایگان |\n| ضخامت | در منبع ذکر نشده |\n[محصول](' + free.url + ')'), null);
  assert.equal(groundingError(s, '**باکس**\nقیمت: رایگان\n[محصول](' + free.url + ')\n\n**مدل دوم**\nقیمت: ۱۲٫۵۰ دلار\n[محصول](' + second.url + ')'), null);
  assert.equal(groundingError(s, '**باکس**\nقیمت: ۱۲٫۵۰ دلار\n[محصول](' + free.url + ')\n\n**مدل دوم**\nقیمت: رایگان\n[محصول](' + second.url + ')'), 'price_mismatch');
});
