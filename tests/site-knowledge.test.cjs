'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { prepare, shape } = require('../src/catalog.cjs');
const { requiredSource, groundingError, modelRequest, toolResult } = require('../src/chat.cjs');
const { build } = require('../scripts/build-workflow.cjs');
const page = (overrides = {}) => ({ id: 123, status: 'publish', is_password_protected: false, content_type: 'page', navigation_only: false,
  link: 'https://gpante.com/coreldraw-training-for-laser-and-cnc-machines-new/', title: { rendered: 'آموزش کورل' },
  excerpt: { rendered: 'آموزش رایگان و دوره کورل', protected: false, truncated: false }, ...overrides });
const response = body => ({ statusCode: 200, headers: { 'x-wp-total': String(body.length), 'x-wp-totalpages': body.length ? '1' : '0' }, body });
const state = (chatInput, evidence = []) => ({ chatInput, evidence, messages: [], toolCount: evidence.length, callIndex: 0 });
const site = results => ({ name: 'Knowledge_Search', source: 'site', ok: true, results });

test('site coverage distinguishes incomplete empty results and exposes explicit unapplied suggestions', () => {
  const context = prepare('knowledge', { source: 'site', search: 'تراکتورنمونهه رایگان 2026' }).context;
  const r = response([]);
  Object.assign(r.headers, { 'x-gpante-coverage': 'incomplete', 'x-gpante-invalid-documents': '1', 'x-gpante-index-initializing': '0',
    'x-gpante-suggestion': encodeURIComponent('تراکتورنمونه رایگان 2026') });
  const result = shape(context, r);
  assert.equal(result.ok, true); assert.equal(result.status, 'incomplete_source');
  assert.deepEqual(result.coverage, { complete: false, invalidDocuments: 1, initializing: false });
  assert.deepEqual(result.querySuggestion, { search: 'تراکتورنمونه رایگان 2026', applied: false });
  r.headers['x-gpante-invalid-documents'] = '0'; r.headers['x-gpante-index-initializing'] = '1';
  assert.equal(shape(context, r).status, 'incomplete_source');
  r.headers['x-gpante-index-initializing'] = '0'; r.headers['x-gpante-coverage'] = 'complete';
  assert.equal(shape(context, r).status, 'no_matches');
  r.headers['x-gpante-coverage'] = 'incomplete';
  assert.equal(shape(context, r).status, 'invalid_response');
});

test('malformed coverage and spelling suggestion headers fail closed', () => {
  const context = prepare('knowledge', { source: 'site', search: 'کورل' }).context;
  for (const headers of [
    { 'x-gpante-coverage': 'unknown' },
    { 'x-gpante-coverage': 'complete', 'x-gpante-invalid-documents': '1', 'x-gpante-index-initializing': '0' },
    { 'x-gpante-coverage': 'incomplete', 'x-gpante-invalid-documents': '-1', 'x-gpante-index-initializing': '1' },
    { 'x-gpante-suggestion': '%ff' },
    { 'x-gpante-suggestion': encodeURIComponent('https://evil.example') },
  ]) {
    const r=response([]); Object.assign(r.headers, headers);
    assert.equal(shape(context,r).status,'invalid_response');
  }
});

test('site search and bounded ID read use the one fixed provider and reject URL/category/intent abuse', () => {
  const search = prepare('knowledge', { source: 'site', search: 'کورل ۲۰۲۶ رایگان', intent: 'learning', page: 2 });
  assert.deepEqual(search.qs, { page: 2, source: 'site', search: 'کورل 2026 رایگان', intent: 'learning' });
  const read = prepare('read', { contentId: '۱۲۳', search: 'کورل' });
  assert.equal(read.url, search.url); assert.deepEqual(read.qs, { source: 'site', contentId: 123, search: 'کورل' });
  assert.equal(read.context.perPage, 1); assert.equal(read.context.source, 'site');
  for (const q of [{ contentId: 0 }, { contentId: 123, url: 'https://evil.test' }, { contentId: 123, source: 'guides' }]) assert.throws(() => prepare('read', q));
  for (const q of [{ source: 'site', categoryId: 1 }, { source: 'articles', intent: 'learning' }, { source: 'site', intent: 'anything' }]) assert.throws(() => prepare('knowledge', q));
});

test('site search exposes typed summaries, while ID read returns only a matching public bounded body', () => {
  const search = shape(prepare('knowledge', { source: 'site', search: 'کورل' }).context, response([page()]));
  assert.equal(search.ok, true); assert.equal(search.results[0].kind, 'page'); assert.equal(search.results[0].excerpt, 'آموزش رایگان و دوره کورل');
  assert.equal(search.results[0].content, undefined);
  const record = page({ excerpt: undefined, content: { rendered: '<p>مرحله اول طراحی</p>', protected: false, truncated: true } });
  const read = shape(prepare('read', { contentId: 123 }).context, response([record]));
  assert.equal(read.ok, true); assert.equal(read.results[0].content, 'مرحله اول طراحی'); assert.equal(read.results[0].truncated, true);
  assert.equal(shape(prepare('read', { contentId: 124 }).context, response([record])).status, 'invalid_response');
});

test('the exact public homepage is readable only through site discovery and ID read', () => {
  const context = prepare('knowledge', { source: 'site', search: 'آموزش' }).context;
  const homepage = page({ link: 'https://gpante.com/' });
  const search = shape(context, response([homepage]));
  assert.equal(search.ok, true); assert.equal(search.results[0].url, 'https://gpante.com/');
  const read = shape(prepare('read', { contentId: 123 }).context,
    response([page({ link: 'https://gpante.com/', excerpt: undefined, content: { rendered: 'متن عمومی صفحه نخست', protected: false, truncated: false } })]));
  assert.equal(read.ok, true); assert.equal(read.results[0].content, 'متن عمومی صفحه نخست');
  for (const source of ['articles', 'guides']) {
    assert.equal(shape(prepare('knowledge', { source, search: 'آموزش' }).context, response([homepage])).status, 'invalid_response');
  }
  for (const link of ['https://gpante.com', 'http://gpante.com/', 'https://www.gpante.com/', 'https://gpante.com/?private=1', 'https://gpante.com/#private', 'https://gpante.com/checkout/', 'https://gpante.com/my-account/', 'https://gpante.com/cart/']) {
    assert.equal(shape(context, response([page({ link })])).status, 'invalid_response', link);
  }
});

test('site records reject private/malformed/operational content and retain title/link only for navigation', () => {
  const context = prepare('knowledge', { source: 'site', search: 'حساب' }).context;
  for (const changes of [{ status: 'private' }, { is_password_protected: true }, { content_type: 'attachment' }, { navigation_only: 'yes' }, { excerpt: { rendered: 'private', protected: true, truncated: false } }, { link: 'https://evil.test/page/' }, { link: 'https://gpante.com/wp-admin/' }, { link: 'https://gpante.com/my-account/' }, { link: 'https://gpante.com/my-account/orders/' }, { link: 'https://gpante.com/checkout/order-received/' }]) assert.equal(shape(context, response([page(changes)])).status, 'invalid_response', JSON.stringify(changes));
  const navigation = page({ navigation_only: true, link: 'https://gpante.com/my-account/', excerpt: undefined });
  const output = shape(context, response([navigation]));
  assert.equal(output.ok, true); assert.equal(output.results[0].navigationOnly, true); assert.equal(output.results[0].excerpt, undefined);
  assert.equal(shape(prepare('read', { contentId: 123 }).context, response([navigation])).status, 'invalid_response');
  const custom = page({ navigation_only: true, link: 'https://gpante.com/member-area/', excerpt: undefined });
  assert.equal(shape(context, response([custom])).ok, true);
  assert.equal(shape(prepare('read', { contentId: 123 }).context, response([custom])).status, 'invalid_response');
  assert.equal(shape(context, response([page(), page()])).status, 'invalid_response');
  assert.equal(shape(context, response([page({ excerpt: { rendered: 'text', protected: false } })])).status, 'invalid_response');
  assert.equal(shape(context, response([page({ navigation_only: true, link: 'https://gpante.com/', excerpt: undefined })])).ok, true);
});

test('site product records retain verified fresh facts and disallow product ID reads', () => {
  const product = { id: 124, content_type: 'product', status: 'publish', type: 'simple', catalog_visibility: 'visible', is_password_protected: false,
    name: 'دوره کورل', permalink: 'https://gpante.com/product/corel/', prices: { price: '100', currency_code: 'IRT', currency_minor_unit: 0 }, attributes: [] };
  const output = shape(prepare('knowledge', { source: 'site', search: 'کورل' }).context, response([page(), product]));
  assert.equal(output.ok, true); assert.equal(output.results[1].kind, 'product'); assert.equal(output.results[1].price.amount, '100');
  assert.equal(shape(prepare('read', { contentId: 124 }).context, response([product])).status, 'invalid_response');
  const s = state('دوره کورل', [site(output.results)]);
  assert.equal(groundingError(s, 'دوره کورل ۱۰۰ تومان\n[دوره](https://gpante.com/product/corel/)'), null);
  assert.equal(groundingError(s, 'دوره کورل ۲۰۰ تومان\n[دوره](https://gpante.com/product/corel/)'), 'price_mismatch');
});

test('site raw and normalized output budgets are measured in bytes and not silently cut', () => {
  const context = prepare('knowledge', { source: 'site', search: 'کورل' }).context;
  assert.equal(shape(context, response([page({ unexpected: 'ژ'.repeat(33000) })])).status, 'response_too_large');
  const record = page({ excerpt: undefined, content: { rendered: 'ژ'.repeat(6001), protected: false, truncated: false } });
  assert.equal(shape(prepare('read', { contentId: 123 }).context, response([record])).status, 'invalid_response');
});

test('learning and installation use site; product format, FAQ and legal gates keep precedence', () => {
  for (const q of ['آموزش کورل', 'دوره رایگان کورل', 'کورل', 'آموزش نصب کورل ۲۰۲۶', 'یادگیری طراحی', 'صفحه خدمات را پیدا کن', 'ثبت نام دوره کورل چطور است؟', 'اموزش طراحی']) assert.equal(requiredSource(state(q)), 'site', q);
  assert.equal(requiredSource(state('مقاله نصب کورل')), 'articles');
  for (const q of ['فایل کورل باکس MDF سه میل معرفی کن', 'طرح باکس با فرمت CDR میخوام', 'فایل کورل میخوام']) assert.equal(requiredSource(state(q)), 'products', q);
  assert.equal(requiredSource(state('آموزش خرید بدون ایمیل')), 'faq');
  assert.equal(requiredSource(state('دوره میخوام ولی حساب کاربری ندارم')), 'faq');
  assert.equal(requiredSource(state('متن قوانین بازپرداخت')), 'guides');
});

test('site citations and content-read evidence are current-turn bounded; failures remain distinct', () => {
  const result = { id: 123, kind: 'page', url: page().link, excerpt: 'خلاصه' };
  assert.equal(groundingError(state('آموزش کورل', [site([result])]), '[آموزش](' + result.url + ')'), null);
  assert.equal(groundingError(state('آموزش کورل', [site([result])]), '[آموزش](https://gpante.com/other/)'), 'unverified_reference');
  const detailed = state('مراحل آموزش کورل را گام به گام توضیح بده', [site([result])]);
  assert.equal(groundingError(detailed, 'مرحله اول طراحی\n[آموزش](' + result.url + ')'), 'content_read_required');
  assert.equal(groundingError(detailed, 'برای آموزش، این صفحه مرتبط است؛ جزئیات مراحل را نتوانستم بررسی کنم.\n[آموزش](' + result.url + ')'), null);
  const read = toolResult({ ...detailed, pendingTool: { id: 'read', function: { name: 'Knowledge_Read', arguments: '{"contentId":123}' } } }, { ok: true, source: 'site', results: [{ ...result, content: 'مرحله اول طراحی', truncated: false }] });
  assert.equal(read.evidence.at(-1).source, 'site');
  assert.equal(groundingError({ ...detailed, evidence: [...detailed.evidence, { name: 'Knowledge_Read', source: 'site', ok: false, status: 'api_error', results: [] }] }, 'مراحل پیدا نشد.'), 'source_read_failed');
  assert.equal(groundingError(read, 'مرحله اول طراحی\n[آموزش](' + result.url + ')'), null);
  assert.equal(groundingError(state('آموزش کورل', [{ name: 'Knowledge_Read', source: 'site', ok: false, status: 'api_error' }]), 'پیدا نشد'), 'source_read_failed');
});

test('model exposes read plus typed site search and every workflow tool round maps read without graph growth', () => {
  const request = modelRequest(state('آموزش کورل'), 'system');
  const tools = request.body.tools.map(t => t.function);
  assert(tools.find(t => t.name === 'Knowledge_Search').parameters.properties.source.enum.includes('site'));
  assert.deepEqual(tools.find(t => t.name === 'Knowledge_Read').parameters.required, ['contentId']);
  const workflow = build(); assert.equal(workflow.nodes.length, 69);
  assert.match(workflow.description, /۱٫۷٫۰/);
  assert(workflow.description.length <= 255, 'workflow metadata fits the connector contract');
  for (let i = 1; i <= 3; i++) assert.match(workflow.nodes.find(n => n.name === 'Prepare Tool ' + i).parameters.jsCode, /Knowledge_Read:'read'/);
});
