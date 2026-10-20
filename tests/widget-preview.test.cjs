'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { buildPreview } = require('../scripts/preview-widget.cjs');
const { buildWidget } = require('../scripts/build-widget.cjs');

test('offline preview exercises the real embed with isolated mock actions and no network fallback', async () => {
  const html = buildPreview();
  assert(html.includes(buildWidget()), 'the preview contains the exact production embed');
  assert(html.includes("connect-src 'none'"), 'CSP blocks connections even if preview initialization fails');
  const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
  assert.equal(scripts.length, 2);
  const mode = { value: 'success' };
  let networkCalls = 0;
  const window = { fetch() { networkCalls++; throw new Error('Network access is forbidden'); } };
  const context = vm.createContext({ window, Response, document: {
    getElementById(id) { if(id==='gpante-support') return {dataset:{appearance:JSON.stringify({version:1,texts:{title:'fixture'}})}}; assert.equal(id, 'preview-mode'); return mode; }
  } });
  vm.runInContext(scripts[0][1], context);
  const call = async (action, chatInput) => {
    const response = await window.fetch('/wp-json/gpante-support/v1/chat', { body: JSON.stringify({ action, chatInput }) });
    return { ok: response.ok, data: await response.json() };
  };
  assert.equal((await (await window.fetch('/wp-json/gpante-support/v1/appearance', {method:'GET'})).json()).version,1);
  assert.deepEqual((await call('loadPreviousSession')).data.messages, []);
  assert.match((await call('sendMessage', 'سلام')).data.output, /سلام/u);
  assert.equal((await call('loadPreviousSession')).data.messages.length, 2);
  mode.value = 'error';
  assert.equal((await call('sendMessage', 'دوباره')).ok, false);
  mode.value = 'malformed';
  await assert.rejects(call('sendMessage', 'نامعتبر'));
  mode.value = 'network';
  await assert.rejects(call('loadPreviousSession'), /Simulated offline state/);
  mode.value = 'success';
  assert.equal((await call('loadPreviousSession')).data.messages.length, 2, 'failed sends do not add mock history');
  assert.equal((await call('resetSession')).data.ok, true);
  assert.deepEqual((await call('loadPreviousSession')).data.messages, []);
  await assert.rejects(window.fetch('https://gpante.com/wp-json/gpante-support/v1/chat', {}), /blocks network requests/);
  assert.equal(networkCalls, 0);
});
