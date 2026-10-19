'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validate, budget, finish } = require('../src/chat.cjs');
const sessionId = 'test_session_12345678';

test('rejects invalid sessions, unsupported actions and excessive messages before budget/model', () => {
  for (const input of [
    { sessionId: 'short', chatInput: 'سلام' },
    { sessionId: '../another_session_123456', chatInput: 'سلام' },
    { sessionId, action: 'loadPreviousSession', chatInput: 'سلام' },
    { sessionId, chatInput: '  ' },
    { sessionId, chatInput: 'a'.repeat(4001) }
  ]) assert.equal(validate(input).valid, false);
  assert.deepEqual(validate({ sessionId, action: 'sendMessage', chatInput: ' سلام ', extra: 'discard' }),
    { valid: true, sessionId, chatInput: 'سلام' });
  assert.equal(validate({ sessionId, chatInput: 'a'.repeat(4000) }).valid, true);
});

test('budget errors fail closed and do not retain user input', () => {
  const input = { sessionId, chatInput: 'سلام', valid: true };
  assert.equal(budget(input, { allowed: true }).allowed, true);
  for (const result of [{ error: 'private error' }, { allowed: 'true' }, {}]) {
    const rejected = budget(input, result);
    assert.equal(rejected.allowed, false);
    assert.equal(rejected.chatInput, undefined);
    assert(!rejected.output.includes('private error'));
  }
  assert.match(budget(input, { reason: 'session_limit', retry_after: 15 }).output, /15/);
});

test('final reply hides internal errors and reasoning, strips HTML and unsafe links', () => {
  assert(!finish({ error: 'secret failure', output: 'internal' }).includes('secret'));
  assert.equal(finish({ output: '<think>internal reasoning</think><b>سلام</b>' }), 'سلام');
  const safe = '[محصول](https://gpante.com/product/sample/)';
  assert.equal(finish({ output: safe }), safe);
  for (const address of [
    'https://external.example/test', 'https://gpante.com@external.example/',
    'https://gpante.com/wp-content/uploads/paid.zip', 'https://gpante.com/%77p-json/test',
    'https://gpante.com/product/../../wp-json/test',
    'https://gpante.com/product/a/?token=private', 'javascript:alert(1)'
  ]) assert(!finish({ output: `[عنوان](${address})` }).includes(address));
  assert(!finish({ output: 'نشانی https://external.example/test و https://gpante.com/wp-content/paid.zip' }).includes('https://'));
  assert.equal(finish({ output: 'a'.repeat(9000) }).length, 8000);
});

test('Markdown spacing, nested labels, images and reference links cannot bypass the destination policy', () => {
  for (const output of [
    '[راهنما]( //external.example/path )',
    '![تصویر]( //external.example/path )',
    '[راهنما [خرید]]( //external.example/path "عنوان" )',
    '[راهنما][external]\n\n[external]: //external.example/path',
    '[راهنما][external]\n\n[external]: ftp://external.example/path',
    '[راهنما][external]\n\n[external]: javascript:alert(1)',
    '[راهنما][external]\n\n[external]:\n//external.example/path'
  ]) {
    const reply = finish({ output });
    assert(!reply.includes('external.example'), reply);
    assert(!reply.includes('[external]:'), reply);
    assert(!reply.includes('!['), reply);
  }
  assert.equal(finish({ output: '[تماس]( https://gpante.com/contact-us/ "راهنما" )' }),
    '[تماس](https://gpante.com/contact-us/)');
  assert.equal(finish({ output: '!!![محصول](https://gpante.com/product/sample/)' }),
    '[محصول](https://gpante.com/product/sample/)');
});
