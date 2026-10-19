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

test('search keeps all constraints and only fixed public Store API destination', () => {
  const result = prepare('search', '{"search":"باكس آجیل","format":"DXF","thickness":"۲٫۷","material":"MDF","page":"۲"}');
  assert.equal(result.url, 'https://gpante.com/wp-json/wc/store/v1/products');
  assert.equal(result.qs.search, 'باکس آجیل');
  assert.equal(result.qs.catalog_visibility, 'search');
  assert.equal(result.qs.attribute_relation, 'and');
  assert.equal(result.qs['attributes[0][attribute]'], 'pa_file-format');
  assert.equal(result.qs['attributes[0][slug]'], 'dxf');
  assert.equal(result.qs['attributes[1][slug]'], '2-7');
  assert.equal(result.qs['attributes[2][slug]'], 'mdf');
  assert.equal(result.qs.page, 2);
  assert.equal(result.qs.per_page, 5);
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
  assert.equal(request.url, 'https://gpante.com/wp-json/wc/store/v1/products');
  assert.equal(request.qs.include, 49304);
  assert.equal(request.qs.catalog_visibility, 'search');
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

test('constraint mismatch or missing attributes cannot become relaxed product matches', () => {
  const context = prepare('search', { search: 'آجیل', format: 'dxf', thickness: '2.7', material: 'mdf' }).context;
  const missing = product({ attributes: [] });
  const other = product();
  other.attributes[1].terms[0].slug = '3';
  assert.equal(shape(context, response([missing])).status, 'constraint_mismatch');
  assert.equal(shape(context, response([other])).status, 'constraint_mismatch');
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
  assert.equal(posts.url, 'https://gpante.com/wp-json/wp/v2/posts');
  assert.equal(posts.qs.status, 'publish');
  assert.equal(posts.qs.per_page, 3);
  const guides = prepare('knowledge', { source: 'guides' });
  assert.equal(guides.url, 'https://gpante.com/wp-json/wp/v2/pages');
  assert.equal(guides.qs.include, '4752,4743');
  assert.equal(guides.qs._fields.includes('content'), true);
  const publicGuide = shape(guides.context, response([{ id: 4752, link: 'https://gpante.com/rules/', title: { rendered: 'قوانین' }, content: { rendered: '<p>مقررات خرید</p>' } }]));
  assert.equal(publicGuide.results[0].excerpt, 'مقررات خرید');
  assert.throws(() => prepare('knowledge', { source: 'https://evil.example' }));
  assert.throws(() => prepare('knowledge', { source: 'guides', include: '1' }));
  const bad = shape(guides.context, response([{ id: 99, link: 'https://gpante.com/private/', title: { rendered: 'Private' }, excerpt: { rendered: 'no' } }]));
  assert.equal(bad.status, 'invalid_response');
});

test('knowledge text removes executable markup and shortcodes, decodes entities and labels truncation', () => {
  const context = prepare('knowledge', { search: 'دانلود' }).context;
  const result = shape(context, response([{
    id: 18, link: 'https://gpante.com/download-help/', title: { rendered: 'راهنمای &amp; دانلود' },
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
