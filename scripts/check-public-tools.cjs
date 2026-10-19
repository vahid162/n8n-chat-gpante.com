'use strict';
// Optional bounded read-only integration check against the public site.
const assert = require('node:assert/strict');
const { prepare, shape } = require('../src/catalog.cjs');
async function check(kind, query) {
  const request = prepare(kind, query);
  return read(request);
}
async function read(request) {
  const url = new URL(request.url);
  for (const [key, value] of Object.entries(request.qs)) url.searchParams.set(key, value);
  const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(15000) });
  const result = shape(request.context, {
    statusCode: response.status, headers: Object.fromEntries(response.headers), body: await response.text()
  });
  assert(result.ok, JSON.stringify({ kind: request.context.kind, status: result.status, httpStatus: response.status }));
  assert.notEqual(result.pagination.total, null, 'Provider must report its filtered total.');
  assert.notEqual(result.pagination.totalPages, null, 'Provider must report its page boundary.');
  console.log(JSON.stringify({ kind: request.context.kind, source: request.context.source, status: result.status,
    returned: result.results.length, pagination: result.pagination }));
  return result;
}
function catalogPage(page) {
  // This read has no search or attribute restriction: page size never caps catalog coverage.
  return read({ url: 'https://gpante.com/wp-json/gpante-support/v1/products', qs: { page },
    context: { kind: 'search', page, perPage: 5, constraints: {}, sort: 'relevance' } });
}
(async () => {
  const catalog = await catalogPage(1);
  assert(catalog.pagination.total > 0);
  if (catalog.pagination.totalPages > 1) {
    const last = await catalogPage(catalog.pagination.totalPages);
    assert.equal(last.pagination.total, catalog.pagination.total);
    assert.equal(last.pagination.hasMore, false);
    assert(!last.results.some(item => catalog.results.some(previous => previous.id === item.id)));
  }
  const query = { search: 'باکس آجیل', format: 'DXF', thickness: '۲٫۷', material: 'MDF' };
  const first = await check('search', query);
  assert(first.results.length > 0);
  if (first.pagination.hasMore) {
    const next = await check('search', { ...query, page: 2 });
    assert(!next.results.some(item => first.results.some(previous => previous.id === item.id)));
  }
  const cheaper = await check('search', { ...query, sort: 'price_asc' });
  assert.equal(cheaper.pagination.total, first.pagination.total);
  const synonym = await check('search', { ...query, search: 'جعبه آجیل', sort: 'price_asc' });
  assert.equal(synonym.pagination.total, cheaper.pagination.total);
  assert.deepEqual(synonym.results.map(item => item.id), cheaper.results.map(item => item.id));
  const details = await check('details', { productId: 49304 });
  assert.equal(details.results[0]?.id, 49304);
  const guides = await check('knowledge', { source: 'guides' });
  assert.equal(guides.results.length, 2);
  await check('knowledge', { source: 'articles', search: 'لیزر' });
})().catch(error => { console.error(error.message); process.exitCode = 1; });
