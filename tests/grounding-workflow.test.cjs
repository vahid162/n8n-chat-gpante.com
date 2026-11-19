'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { build } = require('../scripts/build-workflow.cjs');

// Run the actual generated Code/gate graph; only SQL and remote HTTP boundaries are fixtures.
function run(question, replies, source, options = {}) {
  const w = build();
  const outputs = new Map();
  const visited = [];
  let name = 'Validate Trusted Input', data = { body: { action: 'sendMessage', policyVersion: 2,
    sessionId: 'a'.repeat(64), clientId: 'b'.repeat(64), chatInput: question } }, calls = 0;
  while (name !== 'Return Chat Reply') {
    assert(visited.length < 100, 'bounded graph');
    visited.push(name);
    const node = w.nodes.find(n => n.name === name);
    let branch = 0;
    if (node.type === 'n8n-nodes-base.code') {
      const lookup = key => ({ first: () => ({ json: outputs.get(key) }), itemMatching: () => ({ json: outputs.get(key) }) });
      data = new Function('$input', '$', node.parameters.jsCode)({ first: () => ({ json: data }) }, lookup)[0].json;
    } else if (node.type === 'n8n-nodes-base.if') {
      const field = node.parameters.conditions.conditions[0].leftValue.match(/\$json\.(\w+)/)[1];
      branch = data[field] === true ? 0 : 1;
    } else if (node.type === 'n8n-nodes-base.postgres') {
      data = { result: name === 'Admit Request' ? { allowed: true, requestId: 'c'.repeat(32), messages: [], policy: { version: 2 }, expiresAt: '2026-11-01T00:00:00Z' }
        : name.startsWith('Reserve Model') ? { allowed: true } : name.startsWith('Settle Model') ? { confirmed: options.settlementRefused !== true && options.settlementRefusedRound !== calls } : { saved: true } };
    } else if (name.startsWith('Call DeepSeek')) {
      assert(calls < replies.length, 'no unexpected model dispatch');
      const message = replies[calls++];
      data = { statusCode: 200, body: { usage: { prompt_tokens: 10, completion_tokens: 10, total_tokens: 20 },
        choices: [{ finish_reason: message.tool_calls ? 'tool_calls' : 'stop', message }] } };
    } else if (name.startsWith('Read Public Source')) data = typeof source === 'function' ? source(data.toolRequest) : source;
    else assert.fail('Unexpected graph node: ' + name);
    outputs.set(name, data);
    name = w.connections[name].main[branch][0].node;
  }
  return { data, calls, visited };
}
const call = (name, args) => ({ tool_calls: [{ id: 'call_1', type: 'function', function: { name, arguments: JSON.stringify(args) } }] });
const http = body => ({ statusCode: 200, headers: { 'x-wp-total': '1', 'x-wp-totalpages': '1' }, body });

test('generated graph settles and repairs an unsupported final before reading FAQ and persisting once', () => {
  const url = 'https://gpante.com/faq-ai/#faq-email';
  const source = http([{ id: 'faq-email', page_id: 51415, status: 'publish', is_password_protected: false, link: url,
    title: { rendered: 'ایمیل ندارم' }, content: { rendered: 'ایمیل اختیاری است.', protected: false, truncated: false }, links: [] }]);
  const result = run('ایمیل ندارم می‌توانم خرید کنم؟', [{ content: 'ایمیل الزامی است.' },
    call('Knowledge_Search', { source: 'faq', search: 'ایمیل' }), { content: 'ایمیل اختیاری است. [مرجع](' + url + ')' }], source);
  assert.equal(result.data.status, 200); assert.equal(result.calls, 3);
  assert(!result.visited.includes('Prepare Tool 1'));
  assert(result.visited.includes('Prepare Tool 2'));
  assert.equal(result.visited.filter(n => n.startsWith('Settle Model')).length, 3);
  assert.equal(result.visited.filter(n => n === 'Finish Request').length, 1);
  const execution = result.data.diagnostics.find(event => event.event === 'execution');
  assert.equal(execution.workflowVersion, '1.10.0');
  assert.match(execution.sourceRevision, /^[a-f0-9]{64}$/);
  assert.equal(execution.modelRounds, 3);
  assert.equal(execution.toolCalls, 1);
  assert(!result.data.diagnostics.some(event => event.code?.startsWith('fallback_')));
  assert(!result.data.response.output.includes('الزامی'));
});

test('generated graph corrects a mismatched price with existing evidence and no second tool read', () => {
  const url = 'https://gpante.com/product/box/';
  const source = http([{ id: 1, name: 'باکس', permalink: url, status: 'publish', type: 'simple', catalog_visibility: 'visible',
    is_password_protected: false, prices: { price: '50000', currency_code: 'IRT', currency_minor_unit: 0 }, attributes: [], images: [] }]);
  const result = run('قیمت باکس چنده؟', [call('Product_Search', { search: 'باکس' }),
    { content: 'باکس ۶۰٬۰۰۰ تومان [محصول](' + url + ')' }, { content: 'باکس ۵۰٬۰۰۰ تومان [محصول](' + url + ')' }], source);
  assert.equal(result.data.status, 200); assert.equal(result.calls, 3);
  assert.equal(result.visited.filter(n => n.startsWith('Read Public Source')).length, 1);
  assert(result.data.response.output.includes('۵۰٬۰۰۰'));
});

test('generated graph replaces the fourth ungrounded response without dispatching a phantom tool', () => {
  const result = run('ایمیل ندارم می‌توانم خرید کنم؟', Array.from({ length: 4 }, () => ({ content: 'ایمیل الزامی است.' })));
  assert.equal(result.calls, 4); assert.equal(result.data.fallbackReason, 'source_required');
  assert(!result.visited.some(n => n.startsWith('Read Public Source') || n.startsWith('Prepare Tool')));
  assert.equal(result.visited.filter(n => n === 'Finish Request').length, 1);
  assert.equal(result.data.success, true);
  assert.match(result.data.response.output, /https:\/\/gpante\.com\/faq-ai\//);
  assert.doesNotMatch(result.data.response.output, /ایمیل الزامی است/);
});

test('settlement refusal after a rejected final prevents any correction dispatch and closes once', () => {
  const result = run('ایمیل ندارم می‌توانم خرید کنم؟', [{ content: 'ایمیل الزامی است.' }], undefined, { settlementRefused: true });
  assert.equal(result.calls, 1); assert.equal(result.data.response.error.code, 'accounting_unavailable');
  assert(!result.visited.includes('Prepare Model 2'));
  assert.equal(result.visited.filter(n => n === 'Finish Request').length, 1);
  assert.equal(result.data.success, false);
});


test('generated graph corrects a summary-only lesson by reading the discovered public page within four calls', () => {
  const url = 'https://gpante.com/corel-training/';
  const record = { id: 123, status: 'publish', is_password_protected: false, content_type: 'page', navigation_only: false,
    link: url, title: { rendered: 'آموزش کورل' } };
  const source = request => http([{ ...record, [request.context.kind === 'read' ? 'content' : 'excerpt']:
    { rendered: request.context.kind === 'read' ? 'مرحله اول یک سند تازه بسازید.' : 'آموزش طراحی کورل', protected: false, truncated: false } }]);
  const result = run('مراحل آموزش کورل را گام به گام توضیح بده', [
    call('Knowledge_Search', { source: 'site', search: 'کورل', intent: 'learning' }),
    { content: 'مرحله اول یک سند تازه بسازید. [آموزش](' + url + ')' },
    call('Knowledge_Read', { contentId: 123 }),
    { content: 'مرحله اول یک سند تازه بسازید. [آموزش](' + url + ')' }
  ], source);
  assert.equal(result.data.status, 200); assert.equal(result.calls, 4);
  assert.equal(result.visited.filter(n => n.startsWith('Read Public Source')).length, 2);
  assert.equal(result.visited.filter(n => n.startsWith('Settle Model')).length, 4);
  assert.equal(result.visited.filter(n => n === 'Finish Request').length, 1);
  assert.equal(result.data.toolCount, 2);
});

test('generated graph rejects mixed uncertainty after an empty FAQ and settles the honest correction', () => {
  const empty = { statusCode: 200, headers: { 'x-wp-total': '0', 'x-wp-totalpages': '0' }, body: [] };
  const result = run('خرید بدون ایمیل ممکن است؟', [
    call('Knowledge_Search', { source: 'faq', search: 'ایمیل' }),
    { content: 'نام فروشنده مشخص نیست؛ اما ایمیل برای خرید اجباری است.' },
    { content: 'پاسخ مرتبطی پیدا نکردم.' }
  ], empty);
  assert.equal(result.data.status, 200); assert.equal(result.calls, 3);
  assert.equal(result.visited.filter(n => n.startsWith('Read Public Source')).length, 1);
  assert.equal(result.visited.filter(n => n.startsWith('Settle Model')).length, 3);
  assert.equal(result.visited.filter(n => n === 'Finish Request').length, 1);
  assert.equal(result.data.response.output, 'پاسخ مرتبطی پیدا نکردم.');
});

test('generated graph stops a repeated unsupported answer and persists only a settled safe fallback', () => {
  const empty = { statusCode: 200, headers: { 'x-wp-total': '0', 'x-wp-totalpages': '0' }, body: [] };
  const unsupported = { content: 'ایمیل برای خرید اجباری است.' };
  const replies = [call('Knowledge_Search', { source: 'faq', search: 'ایمیل' }), unsupported, unsupported];
  const successful = run('خرید بدون ایمیل ممکن است؟', replies, empty);
  assert.equal(successful.calls, 3);
  assert.equal(successful.data.fallbackReason, 'source_unavailable');
  assert.equal(successful.data.success, true);
  assert.match(successful.data.response.output, /در پرسش‌های متداول پاسخ مرتبطی پیدا نکردم/);
  assert.match(successful.data.response.output, /https:\/\/gpante\.com\/faq-ai\//);
  assert.doesNotMatch(successful.data.response.output, /ایمیل برای خرید اجباری است/);
  assert(successful.data.diagnostics.some(event => event.event === 'rejection' && event.code === 'fallback_source_unavailable'));
  assert(successful.visited.indexOf('Settle Model 3') < successful.visited.indexOf('Finish Request'));
  assert.equal(successful.visited.filter(n => n === 'Finish Request').length, 1);
  const unsettled = run('خرید بدون ایمیل ممکن است؟', replies, empty, { settlementRefusedRound: 3 });
  assert.equal(unsettled.calls, 3);
  assert.equal(unsettled.data.success, false);
  assert.equal(unsettled.data.response.error.code, 'accounting_unavailable');
  assert.doesNotMatch(unsettled.data.response.output, /https:/);
  assert(!unsettled.data.diagnostics.some(event => event.code?.startsWith('fallback_')));
  assert.equal(unsettled.visited.filter(n => n === 'Finish Request').length, 1);
});

test('generated graph limits repeated missing citations without guessing at matching products', () => {
  const url = 'https://gpante.com/product/box/';
  const unsupported = { content: 'این طرح موجود و رایگان است.' };
  const source = http([{ id: 1, name: 'باکس', permalink: url, status: 'publish', type: 'simple', catalog_visibility: 'visible',
    is_password_protected: false, prices: { price: '50000', currency_code: 'IRT', currency_minor_unit: 0 }, attributes: [], images: [] }]);
  const result = run('باکس دارید؟', [call('Product_Search', { search: 'باکس' }), unsupported, unsupported], source);
  assert.equal(result.calls, 3);
  assert.equal(result.data.fallbackReason, 'reference_required');
  assert.equal(result.data.success, true);
  assert.match(result.data.response.output, /نتوانستم پاسخ قابل‌استنادی/);
  assert.match(result.data.response.output, /https:\/\/gpante\.com\//);
  assert.doesNotMatch(result.data.response.output, /product\/box|رایگان|پیدا نکردم/);
});
