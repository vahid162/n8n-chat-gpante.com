'use strict';
// Optional bounded read-only integration check against the public site.
const assert = require('node:assert/strict');
const { prepare, shape } = require('../src/catalog.cjs');
async function check(kind, query) {
  const request = prepare(kind, query);
  const url = new URL(request.url);
  for (const [key, value] of Object.entries(request.qs)) url.searchParams.set(key, value);
  const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(15000) });
  const result = shape(request.context, {
    statusCode: response.status, headers: Object.fromEntries(response.headers), body: await response.text()
  });
  assert(result.ok, JSON.stringify({ kind, status: result.status }));
  console.log(JSON.stringify({ kind, source: request.context.source, status: result.status,
    returned: result.results.length, pagination: result.pagination }));
  return result;
}
(async () => {
  const query = { search: 'باکس آجیل', format: 'DXF', thickness: '۲٫۷', material: 'MDF' };
  const first = await check('search', query);
  assert(first.results.length > 0);
  if (first.pagination.hasMore) {
    const next = await check('search', { ...query, page: 2 });
    assert(!next.results.some(item => first.results.some(previous => previous.id === item.id)));
  }
  const details = await check('details', { productId: 49304 });
  assert.equal(details.results[0].id, 49304);
  const guides = await check('knowledge', { source: 'guides' });
  assert.equal(guides.results.length, 2);
  await check('knowledge', { source: 'articles', search: 'لیزر' });
})().catch(error => { console.error(error.message); process.exitCode = 1; });
