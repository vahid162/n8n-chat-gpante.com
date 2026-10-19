'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateTrusted, admitted, modelRequest, reserved, modelResult, settled, toolResult } = require('../src/chat.cjs');
const input = { action: 'sendMessage', sessionId: 'a'.repeat(64), clientId: 'b'.repeat(64), chatInput: 'سلام' };
const start = () => admitted(validateTrusted(input), { allowed: true, requestId: 'c'.repeat(32), messages: [] });
const usage = { prompt_tokens: 20, completion_tokens: 5, total_tokens: 25 };
const response = (message, finish_reason = 'stop', extra = {}) => ({ statusCode: 200, body: { usage, choices: [{ message, finish_reason }], ...extra } });

test('trusted ingress rejects browser-shaped identities and unknown actions', () => {
  assert.equal(validateTrusted(input).valid, true);
  for (const change of [{ clientId: '' }, { sessionId: 'browser_session_1234' }, { action: 'executeSql' }, { chatInput: 'م'.repeat(4001) }]) {
    assert.equal(validateTrusted({ ...input, ...change }).valid, false);
  }
  assert.equal(admitted(input, {}).done, true);
  assert.equal(admitted(input, { allowed: false, reason: 'capacity' }).status, 429);
});

test('server history is isolated by admission and bounded before reaching the model', () => {
  const messages = Array.from({ length: 12 }, (_, i) => ({ role: i % 2 ? 'assistant' : 'user', content: 'م'.repeat(4000) }));
  const state = admitted(input, { allowed: true, requestId: 'c'.repeat(32), messages });
  assert(JSON.stringify(state.messages).length < 30000);
  assert.equal(state.messages.at(-1).content, 'سلام');
  assert.deepEqual(admitted({ ...input, action: 'resetSession' }, { allowed: true, ok: true }).response, { ok: true });
  assert.equal(admitted({ ...input, action: 'loadPreviousSession' }, { allowed: true, messages: [] }).response.messages.length, 0);
});

test('each model dispatch requires fresh reservation and has explicit output/iteration caps', () => {
  const request = modelRequest(start(), 'system');
  assert.equal(request.body.max_tokens, 1536);
  assert.deepEqual(request.body.thinking, { type: 'disabled' });
  assert.equal(request.callIndex, 1);
  assert.equal(reserved(request, {}).done, true);
  assert.equal(reserved(request, { allowed: true }).body.model, 'deepseek-flash');
  assert.equal(modelRequest({ ...start(), callIndex: 3 }, 'system').body.tool_choice, 'none');
  assert.equal(modelRequest({ ...start(), callIndex: 4 }, 'system').done, true);
  assert.equal(modelRequest({ ...start(), messages: [{ role: 'user', content: 'a'.repeat(96000) }] }, 'system').done, true);
});

test('only complete provider usage can refund the conservative call reservation', () => {
  const state = modelRequest(start(), 'system');
  const success = modelResult(state, response({ role: 'assistant', content: '<think>secret</think>سلام' }));
  assert.equal(success.response.output, 'سلام');
  assert.deepEqual(success.usage, { prompt: 20, completion: 5, total: 25 });
  assert.equal(settled(success, { confirmed: true }).success, true);
  assert.equal(settled(success, {}).success, false);
  for (const bad of [null, { ...usage, total_tokens: 99 }, { ...usage, prompt_tokens: -1 }, { ...usage, completion_tokens: 1537 }, { ...usage, prompt_tokens: 0.5 }]) {
    const parsed = modelResult(state, response({ content: 'سلام' }, 'stop', { usage: bad }));
    assert.equal(parsed.usage.prompt, null);
    assert.equal(parsed.success, undefined);
  }
  assert.equal(modelResult(state, { error: 'secret' }).done, true);
  assert.equal(modelResult(state, response({ content: 'partial' }, 'length')).success, undefined);
});

test('one bounded tool per round cannot escape known tool names or parameters', () => {
  const state = modelRequest(start(), 'system');
  const call = { id: 'call_1', type: 'function', function: { name: 'Product_Search', arguments: '{"search":"باکس"}' } };
  const parsed = modelResult(state, response({ content: null, tool_calls: [call] }, 'tool_calls'));
  assert.equal(parsed.toolCount, 1);
  assert.equal(parsed.messages.at(-1).tool_calls[0].id, 'call_1');
  assert.equal(toolResult(parsed, { ok: false, status: 'api_error' }).messages.at(-1).tool_call_id, 'call_1');
  for (const calls of [[call, call], [{ ...call, function: { name: 'executeSQL', arguments: '{}' } }]]) {
    assert.equal(modelResult(state, response({ tool_calls: calls }, 'tool_calls')).done, true);
  }
});
