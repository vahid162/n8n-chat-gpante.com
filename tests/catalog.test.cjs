const test = require('node:test');
const assert = require('node:assert/strict');
const { normalize, prepare, shape } = require('../src/catalog.cjs');
const vm = require('node:vm');

test('accepts JSON records from a separate runtime realm used by Code task runners', () => {
  const query = vm.runInNewContext('JSON.parse(\'{"search":"باکس"}\')');
  assert.equal(prepare('search', query).qs.search, 'باکس');
  const request = prepare('details', { productId: 49304 });
  const input = vm.runInNewContext('JSON.parse(' + JSON.stringify(JSON.stringify({
    statusCode: 200, body: [product()], headers: { 'x-wp-total': '1', 'x-wp-totalpages': '1' }
  })) + ')');
  assert.equal(shape(request.context, input).ok, true);
});

function product(overrides = {}) {
  return {
    id: 49304,
    name: 'فایل برش لیزر باکس آجیل دامان',
    status: 'publish',
    type: 'simple',
    catalog_visibility: 'visible',
    is_password_protected: false,
    permalink: 'https://gpante.com/product/daman/',
    short_description: '<p>فایل مناسب برش لیزر</p>',
    prices: { price: '0', regular_price: '125000', sale_price: '0', currency_code: 'IRT', currency_symbol: 'تومان', currency_minor_unit: 0 },
    attributes: [
      { name: 'فرمت', taxonomy: 'pa_file-format', terms: [{ name: 'DXF', slug: 'dxf' }] },
      { name: 'ضخامت', taxonomy: 'pa_thickness', terms: [{ name: '۲٫۷', slug: '2-7' }] },
      { name: 'متریال', taxonomy: 'pa_materials-required', terms: [{ name: 'MDF', slug: 'mdf' }] },
    ],
    images: [{ src: 'https://gpante.com/wp-content/uploads/2026/10/daman.webp', alt: 'دامان' }],
    is_in_stock: true,
    on_sale: true,
    downloads: [{ download_url: 'https://gpante.com/private.zip' }],
    ...overrides,
  };
}

function response(body, headers = {}, statusCode = 200) {
  return { statusCode, headers, body };
}

test('normalization supports Persian/Arabic digits, letters, decimal marks and spacing', () => {
  assert.equal(normalize('  باكس\u200cآجيل ٢٫٧  MDF  '), 'باکس آجیل 2.7 mdf');
  assert.equal(normalize('ي ك ۱۲۳ ٤٥٦'), 'ی ک 123 456');
});

test('search keeps all constraints and only the fixed eligible public provider destination', () => {
  const result = prepare('search', '{"search":"باكس آجیل","format":"DXF","thickness":"۲٫۷","material":"MDF","page":"۲"}');
  assert.equal(result.url, 'https://gpante.com/wp-json/gpante-support/v1/products');
  assert.equal(result.qs.search, 'باکس آجیل');
  assert.equal(result.qs.format, 'dxf');
  assert.equal(result.qs.thickness, '2-7');
  assert.equal(result.qs.material, 'mdf');
  assert.equal(result.qs.page, 2);
  assert.equal(result.context.perPage, 5);
  assert.equal(prepare('search', { search: 'فایل', material: 'پلکسی' }).context.constraints.material, 'plexiglass');
  assert.equal(prepare('search', { search: 'فایل', thickness: '۲٫۶ میلی متر' }).context.constraints.thickness, '2-6-%d9%85%db%8c%d9%84%db%8c%d9%85%d8%aa%d8%b1');
  assert.equal(prepare('search', { search: 'فایل', material: 'چوب' }).context.constraints.material, '%da%86%d9%88%d8%a8');
  assert.equal(prepare('search', { search: 'فایل', format: 'کورل ۱۳' }).context.constraints.format, 'cdr-v3');
  assert.throws(() => prepare('search', { search: 'فایل', format: 'cdr-v13' }));
});

test('unknown parameters, unsupported constraints and malformed pagination are rejected', () => {
  for (const input of [
    { search: 'فایل', url: 'http://127.0.0.1' },
    { search: 'فایل', format: 'executable' },
    { search: 'فایل', material: 'anything' },
    { search: 'فایل', thickness: '2.7 or 3' },
    { search: 'فایل', page: 0 },
    { search: 'فایل', page: '2e2' },
    { search: 'فایل', page: 1.5 },
    { search: 'فایل', sessionId: 'other-user' },
    { search: 'فایل', format: ['dxf'] },
  ]) assert.throws(() => prepare('search', input));
  for (const input of ['{', 'null', '[]', '{"search":"فایل","__proto__":{}}']) {
    assert.throws(() => prepare('search', input));
  }
  assert.throws(() => prepare('arbitrary', { search: 'x' }));
});

test('details cannot bypass public discovery visibility and product IDs must be positive integers', () => {
  const request = prepare('details', { productId: '۴۹۳۰۴' });
  assert.equal(request.url, 'https://gpante.com/wp-json/gpante-support/v1/products');
  assert.equal(request.qs.productId, 49304);
  for (const productId of [0, -1, 1.2, '1/../../users', '1,2', Number.MAX_SAFE_INTEGER + 1]) {
    assert.throws(() => prepare('details', { productId }));
  }
  const absent = shape(request.context, response([], { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' }));
  assert.equal(absent.status, 'no_matches');
  assert.deepEqual(absent.results, []);
  const hidden = shape(request.context, response([product({ catalog_visibility: 'hidden' })]));
  assert.equal(hidden.ok, false);
  const wrongId = shape(request.context, response([product({ id: 49305 })]));
  assert.equal(wrongId.ok, false);
});

test('public shape honors free price, minor units and large integer amounts without floats', () => {
  const context = prepare('search', { search: 'آجیل', format: 'dxf', thickness: '2.7', material: 'mdf' }).context;
  const result = shape(context, response(Array.from({ length: 5 }, (_, index) => product({ id: 49304 + index })), { 'x-wp-total': '7', 'x-wp-totalpages': '2' }));
  assert.equal(result.ok, true);
  assert.equal(result.status, 'partial');
  assert.equal(result.pagination.total, 7);
  assert.equal(result.pagination.hasMore, true);
  assert.equal(result.results[0].price.amount, '0');
  assert.equal(result.results[0].price.minorAmount, '0');
  assert.equal(result.results[0].price.currency, 'IRT');
  assert.equal(result.results[0].price.minorUnit, 0);
  assert.equal(JSON.stringify(result).includes('private.zip'), false);
  const decimal = shape(context, response([product({ prices: { price: '900719925474099312', currency_code: 'EUR', currency_minor_unit: 2 } })]));
  assert.equal(decimal.results[0].price.amount, '9007199254740993.12');
});

test('product delivery facts preserve digital, physical and mixed booleans without guessing legacy fields', () => {
  for (const kind of ['search', 'details']) {
    const context = prepare(kind, kind === 'search' ? { search: 'فایل' } : { productId: 49304 }).context;
    for (const [is_virtual, is_downloadable] of [[true, true], [false, false], [false, true], [true, false]]) {
      const result = shape(context, response([product({ is_virtual, is_downloadable })]));
      assert.equal(result.ok, true);
      assert.equal(result.results[0].virtual, is_virtual);
      assert.equal(result.results[0].downloadable, is_downloadable);
      assert.equal(JSON.stringify(result).includes('private.zip'), false);
      assert.equal(result.results[0].downloads, undefined);
    }
    for (const changes of [{}, { is_virtual: 'true', is_downloadable: 'false' }, { is_virtual: 1, is_downloadable: 0 }, { is_virtual: null, is_downloadable: null }]) {
      const result = shape(context, response([product(changes)]));
      assert.equal(result.ok, true);
      assert.equal(result.results[0].virtual, null);
      assert.equal(result.results[0].downloadable, null);
    }
  }
});

test('constraint mismatch or missing attributes cannot become relaxed product matches', () => {
  const context = prepare('search', { search: 'آجیل', format: 'dxf', thickness: '2.7', material: 'mdf' }).context;
  const missing = product({ attributes: [] });
  const other = product();
  other.attributes[1].terms[0].slug = '3';
  assert.equal(shape(context, response([missing])).status, 'constraint_mismatch');
  assert.equal(shape(context, response([other])).status, 'constraint_mismatch');
});

test('verified MDF-or-plexiglass is compatible while combined-required materials stay distinct', () => {
  const context = prepare('search', { material: 'mdf' }).context;
  const material = (slug, name) => product({ attributes: [{ taxonomy: 'pa_materials-required', name: 'متریال', terms: [{ slug, name }] }] });
  assert.equal(shape(context, response([material('mdf-plexiglass', 'MDF یا پلکسی')])).ok, true);
  assert.equal(shape(context, response([material('mdf-plexiglass', 'MDF و پلکسی')])).status, 'constraint_mismatch');
  assert.equal(shape(context, response([material('mdf-%d9%88-%d9%be%d9%84%da%a9%d8%b3%db%8c', 'MDF و پلکسی')])).status, 'constraint_mismatch');
  assert.equal(shape(context, response([material('mdf-plxi', 'MDF/ PLXI')])).status, 'constraint_mismatch');
  const combined = prepare('search', { material: 'mdf و پلکسی' }).context;
  assert.equal(shape(combined, response([material('mdf', 'MDF')])).status, 'constraint_mismatch');
});

test('invalid and absent prices remain distinct and unsafe links cannot hide behind encoding', () => {
  const context = prepare('search', { search: 'فایل' }).context;
  assert.equal(shape(context, response([product({ prices: null })])).results[0].price, null);
  for (const prices of [{ price: 10, currency_code: 'IRT', currency_minor_unit: 0 },
    { price: '-1', currency_code: 'IRT', currency_minor_unit: 0 },
    { price: '10', currency_code: 'irt', currency_minor_unit: 0 },
    { price: '10', currency_code: 'IRT', currency_minor_unit: 1.5 }]) {
    assert.equal(shape(context, response([product({ prices })])).status, 'invalid_response');
  }
  for (const permalink of ['https://gpante.com/product/%2e%2e/', 'https://gpante.com/product/a%00/',
    'https://gpante.com/product/a%5cb/']) {
    assert.equal(shape(context, response([product({ permalink })])).status, 'invalid_response');
  }
});

test('malformed payloads, API failures, throttling and empty results have different outcomes', () => {
  const context = prepare('search', { search: 'آجیل' }).context;
  assert.equal(shape(context, response([])).status, 'no_matches');
  assert.equal(shape(context, response('{bad')).status, 'invalid_response');
  assert.equal(shape(context, response({ message: 'secret internal error' }, {}, 503)).status, 'api_error');
  const limited = shape(context, response({}, { 'Retry-After': '20' }, 429));
  assert.equal(limited.status, 'rate_limited');
  assert.equal(limited.retryAfter, '20');
  assert.equal(JSON.stringify(limited).includes('secret internal error'), false);
  assert.equal(shape(context, response([], { 'x-wp-total': '7', 'x-wp-totalpages': '2' })).status, 'incomplete_response');
  assert.equal(shape(context, response([product()], { 'x-wp-total': '7', 'x-wp-totalpages': '2' })).status, 'incomplete_response');
  assert.equal(shape(context, { error: { message: 'private transport info' } }).status, 'transport_error');
  assert.equal(shape(context, response([product()], { 'x-wp-total': 'garbage' })).status, 'invalid_response');
  assert.equal(shape(context, response([product(), product()])).status, 'invalid_response');
});

test('unsafe product links fail closed while unsafe images are excluded', () => {
  const context = prepare('search', { search: 'فایل' }).context;
  for (const permalink of ['http://gpante.com/product/x/', 'https://evil.example/product/x/', 'https://gpante.com/private.zip', 'https://gpante.com/product/x/?download_file=1', 'https://gpante.com/product/../private/']) {
    assert.equal(shape(context, response([product({ permalink })])).status, 'invalid_response');
  }
  const result = shape(context, response([product({ images: [{ src: 'javascript:alert(1)' }, { src: 'https://gpante.com/private.zip' }, { src: 'https://gpante.com/wp-content/uploads/x.png', alt: '<b>x</b>' }] })]));
  assert.deepEqual(result.results[0].images, [{ url: 'https://gpante.com/wp-content/uploads/x.png', alt: 'x' }]);
});

test('knowledge uses only public posts or the two approved public guide pages', () => {
  const posts = prepare('knowledge', { search: 'دانلود' });
  assert.equal(posts.url, 'https://gpante.com/wp-json/gpante-support/v1/knowledge');
  assert.equal(posts.qs.source, 'articles');
  assert.equal(posts.context.perPage, 3);
  const guides = prepare('knowledge', { source: 'guides' });
  assert.equal(guides.url, 'https://gpante.com/wp-json/gpante-support/v1/knowledge');
  assert.equal(guides.qs.source, 'guides');
  const publicGuide = shape(guides.context, response([{ id: 4752, status: 'publish', is_password_protected: false, link: 'https://gpante.com/rules/', title: { rendered: 'قوانین' }, content: { rendered: '<p>مقررات خرید</p>' } }]));
  assert.equal(publicGuide.results[0].excerpt, 'مقررات خرید');
  assert.throws(() => prepare('knowledge', { source: 'https://evil.example' }));
  assert.throws(() => prepare('knowledge', { source: 'guides', include: '1' }));
  const bad = shape(guides.context, response([{ id: 99, link: 'https://gpante.com/private/', title: { rendered: 'Private' }, excerpt: { rendered: 'no' } }]));
  assert.equal(bad.status, 'invalid_response');
});

test('knowledge text removes executable markup and shortcodes, decodes entities and labels truncation', () => {
  const context = prepare('knowledge', { search: 'دانلود' }).context;
  const result = shape(context, response([{
    id: 18, status: 'publish', is_password_protected: false, link: 'https://gpante.com/download-help/', title: { rendered: 'راهنمای &amp; دانلود' },
    excerpt: { rendered: '<script>doEvil()</script><style>bad</style><p>راهنما&nbsp;برای دانلود [secret file="paid.zip"]</p><a href="https://evil.example/">لینک</a> ' + 'متن '.repeat(1000) },
    private: 'must not appear',
  }]));
  const item = result.results[0];
  assert.equal(item.title, 'راهنمای & دانلود');
  assert.equal(item.excerpt.includes('doEvil'), false);
  assert.equal(item.excerpt.includes('secret'), false);
  assert.equal(item.excerpt.includes('evil.example'), false);
  assert.equal(item.excerpt.length <= 1200, true);
  assert.equal(item.truncated, true);
  assert.equal(JSON.stringify(result).includes('must not appear'), false);
});

test('a server-side page size is a display bound, and full response metadata remains visible', () => {
  const context = prepare('search', { search: 'فایل', page: 2 }).context;
  const items = [product(), product({ id: 49305 })];
  const result = shape(context, response(JSON.stringify(items), { 'X-WP-Total': '7', 'X-WP-TotalPages': '2' }));
  assert.equal(result.status, 'ok');
  assert.equal(result.pagination.page, 2);
  assert.equal(result.pagination.total, 7);
  assert.equal(result.pagination.hasMore, false);
});

test('eligibility includes catalog-only and search-only products but rejects absent or protected metadata', () => {
  const context = prepare('search', { search: 'فایل' }).context;
  for (const catalog_visibility of ['visible', 'catalog', 'search']) {
    assert.equal(shape(context, response([product({ catalog_visibility })])).ok, true);
  }
  for (const overrides of [
    { is_password_protected: true }, { is_password_protected: undefined },
    { status: 'private' }, { status: undefined }, { catalog_visibility: 'hidden' },
    { catalog_visibility: undefined }, { type: 'variation' },
  ]) assert.equal(shape(context, response([product(overrides)])).status, 'invalid_response');
});

test('price ascending is server requested and rejects an incorrectly ordered page without floating point comparisons', () => {
  const request = prepare('search', { search: 'آجیل', sort: 'price_asc', page: 2 });
  assert.equal(request.qs.sort, 'price_asc');
  assert.equal(request.qs.page, 2);
  const withPrice = (id, price) => product({ id, prices: { price, currency_code: 'IRT', currency_minor_unit: 0 } });
  const ordered = [withPrice(1, '0'), withPrice(2, '2'), withPrice(3, '10'), withPrice(4, '900719925474099312')];
  assert.equal(shape(request.context, response(ordered)).ok, true);
  assert.equal(shape(request.context, response([ordered[3], ordered[2]])).status, 'sort_mismatch');
  assert.equal(shape(request.context, response([withPrice(1, '')])).status, 'sort_unverifiable');
  assert.throws(() => prepare('search', { search: 'فایل', sort: 'rand' }));
});

test('bounded tool output fails explicitly instead of omitting requested results', () => {
  const context = prepare('search', { search: 'فایل' }).context;
  const attributes = Array.from({ length: 30 }, () => ({ name: 'متریال', taxonomy: 'pa_materials-required',
    terms: Array.from({ length: 40 }, () => ({ name: 'متن'.repeat(30), slug: 'mdf' })) }));
  const result = shape(context, response([product({ attributes })]));
  assert.equal(result.status, 'response_too_large');
  assert.equal(result.ok, false);
  assert.deepEqual(result.results, []);
  assert(JSON.stringify(result).length <= 16000);
});

test('search syntax is bounded literal text with at most eight terms', () => {
  for (const search of ['%باكس', 'باکس" OR 1=1', 'الف ب ج د ه و ز ح ط', 'باکس\nآجیل\u0000']) {
    assert.throws(() => prepare('search', { search }));
  }
  assert.equal(prepare('search', { search: 'جعبه آجیل' }).qs.search, 'جعبه آجیل');
});

test('knowledge requires explicit public non-password metadata', () => {
  const context = prepare('knowledge', { source: 'articles' }).context;
  const item = { id: 1, status: 'publish', is_password_protected: false, link: 'https://gpante.com/article/',
    title: { rendered: 'راهنما' }, excerpt: { rendered: 'توضیح' } };
  assert.equal(shape(context, response([item])).ok, true);
  assert.equal(shape(context, response([{ ...item, is_password_protected: true }])).status, 'invalid_response');
  assert.equal(shape(context, response([{ ...item, status: undefined }])).status, 'invalid_response');
});

function faq(overrides = {}) {
  return { id: 'faq-format-cdr', page_id: 51415, status: 'publish', is_password_protected: false,
    link: 'https://gpante.com/faq-ai/#faq-format-cdr', title: { rendered: 'فرمت CDR چیست؟' },
    content: { rendered: 'CDR فرمت CorelDRAW است.', protected: false, truncated: false },
    links: [{ title: 'پشتیبانی', url: 'https://gpante.com/contact-us/' }, { title: 'تلگرام طراحی', url: 'https://t.me/gpante_designer' }], ...overrides };
}

test('FAQ is a separate fixed knowledge source with no arbitrary page or article category', () => {
  const request = prepare('knowledge', { source: 'faq', search: 'شیار', page: 2 });
  assert.equal(request.url, 'https://gpante.com/wp-json/gpante-support/v1/knowledge');
  assert.deepEqual(request.qs, { source: 'faq', search: 'شیار', page: 2 });
  assert.throws(() => prepare('knowledge', { source: 'faq', categoryId: 1 }));
  assert.throws(() => prepare('knowledge', { source: 'faq', pageId: 1 }));
  assert.throws(() => prepare('knowledge', { source: 'faq', url: 'https://evil.example/' }));
});

test('FAQ returns paired questions, source anchors, verified support references and bounded answer status', () => {
  const context = prepare('knowledge', { source: 'faq' }).context;
  const result = shape(context, response([faq()]));
  assert.equal(result.ok, true); assert.equal(result.source, 'faq');
  assert.equal(result.results[0].url, 'https://gpante.com/faq-ai/#faq-format-cdr');
  assert.equal(result.results[0].links[1].url, 'https://t.me/gpante_designer');
  assert.equal(result.results[0].truncated, false);
  const fresh = shape(context, response([faq({ id: 'elementor-new-question', link: 'https://gpante.com/faq-ai/' })]));
  assert.equal(fresh.ok, true);
  const long = shape(context, response([faq({ content: { rendered: 'متن'.repeat(1200), protected: false, truncated: true } })]));
  assert.equal(long.results[0].excerpt.length, 2400); assert.equal(long.results[0].truncated, true);
});

test('FAQ rejects unpublished pages, mismatched question anchors, duplicate IDs and unsafe references', () => {
  const context = prepare('knowledge', { source: 'faq' }).context;
  for (const override of [{ page_id: 1 }, { status: 'private' }, { is_password_protected: true },
    { id: 'faq-format-cdr\n' }, { link: 'https://gpante.com/faq-ai/#faq-other' }, { link: 'https://gpante.com/faq-ai/#faq-format-cdr\n' },
    { link: 'https://gpante.com/faq-ai/?x=1#faq-format-cdr' }, { title: { rendered: '' } },
    { content: { rendered: 'متن', protected: true, truncated: false } }]) {
    assert.equal(shape(context, response([faq(override)])).status, 'invalid_response');
  }
  for (const url of ['https://t.me/gpante_designer.evil','https://t.me/gpante_designer?x=1',
    'https://gpante.com/wp-json/users/','https://gpante.com/wp-includes/example/','https://gpante.com/my-account/']) {
    assert.equal(shape(context, response([faq({ links: [{ title: 'نامعتبر', url }] })])).status, 'invalid_response');
  }
  assert.equal(shape(context, response([faq(), faq()])).status, 'invalid_response');
});

test('discovery supports explicit catalog browsing, real categories and article categories', () => {
  for (const sort of ['newest', 'best_selling']) {
    assert.equal(prepare('search', { sort }).qs.sort, sort);
  }
  const request = prepare('search', { categoryId: '۱۲', material: 'mdf', sort: 'newest' });
  assert.equal(request.qs.categoryId, 12);
  assert.equal(request.qs.material, 'mdf');
  assert.throws(() => prepare('search', {}));
  assert.throws(() => prepare('search', { categoryId: 0 }));
  assert.equal(prepare('knowledge', { search: 'کورل', categoryId: 140 }).qs.categoryId, 140);
  assert.throws(() => prepare('knowledge', { source: 'guides', categoryId: 140 }));
  const categories = prepare('categories', { source: 'products', search: 'باکس', parentId: 0, page: 2 });
  assert.equal(categories.url, 'https://gpante.com/wp-json/gpante-support/v1/categories');
  assert.deepEqual(categories.qs, { page: 2, source: 'products', search: 'باکس', parentId: 0 });
  assert.throws(() => prepare('categories', { source: 'users' }));
  assert.throws(() => prepare('categories', { parentId: -1 }));
  assert.throws(() => prepare('categories', { parentId: [0] }));
});

test('discovery verifies the requested source ordering and category scope', () => {
  const headers = { 'X-GPante-Sort': 'newest' };
  const context = prepare('search', { categoryId: 12, sort: 'newest' }).context;
  const first = product({ id: 10, category_ids: [12, 13], published_at: '2026-10-21T10:00:00Z' });
  const second = product({ id: 9, category_ids: [12], published_at: '2026-10-20T10:00:00Z' });
  const result = shape(context, response([first, second], headers));
  assert.equal(result.ok, true);
  assert.equal(result.categoryId, 12);
  assert.equal(result.results[0].publishedAt, first.published_at);
  assert.equal(shape(context, response([second, first], headers)).status, 'sort_mismatch');
  assert.equal(shape(context, response([first])).status, 'sort_unverifiable');
  assert.equal(shape(context, response([{ ...first, category_ids: [99] }], headers)).status, 'constraint_mismatch');
  const sold = prepare('search', { sort: 'best_selling' }).context;
  assert.equal(shape(sold, response([product()], { 'X-GPante-Sort': 'best_selling' })).status, 'sort_unverifiable');
  const ranked = shape(sold, response([product()], { 'X-GPante-Sort': 'best_selling', 'X-GPante-Sales-Period': 'all_time' }));
  assert.equal(ranked.ok, true);
  assert.equal(ranked.salesPeriod, 'all_time');
});

test('categories expose bounded public navigation and honor source and parent scope', () => {
  const context = prepare('categories', { source: 'products', parentId: 0 }).context;
  const item = { id: 12, name: 'باکس', parent: 0, link: 'https://gpante.com/product-category/box/', source: 'products', private: 'secret' };
  const result = shape(context, response([item], { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' }));
  assert.equal(result.ok, true);
  assert.equal(result.results[0].url, item.link);
  assert.equal(result.source, 'products');
  assert.equal(JSON.stringify(result).includes('secret'), false);
  for (const invalid of [{ source: 'articles' }, { parent: 99 }, { link: 'https://evil.example/category/' }]) {
    assert.equal(shape(context, response([{ ...item, ...invalid }])).ok, false);
  }
});
