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
  assert.deepEqual(admitted({ ...input, action: 'resetSession' }, { allowed: true, ok: true }).response, { ok: true, policy: undefined });
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

test('bounded tool batches preserve all call IDs and drain in order before the next model request', () => {
  const state = modelRequest(start(), 'system');
  const call = { id: 'call_1', type: 'function', function: { name: 'Product_Search', arguments: '{"search":"باکس"}' } };
  for (const size of [1, 2, 3]) {
    const calls = Array.from({ length: size }, (_, i) => ({ ...call, id: 'call_' + (i + 1) }));
    let parsed = modelResult(state, response({ content: null, tool_calls: calls }, 'tool_calls'));
    assert.equal(parsed.toolCount, size);
    assert.equal(modelRequest(parsed, 'system').response.error.code, 'tool_sequence');
    assert.deepEqual(parsed.messages.at(-1).tool_calls, calls);
    for (let i = 0; i < size; i++) {
      assert.equal(parsed.pendingTool.id, calls[i].id);
      parsed = toolResult(parsed, i % 2 ? { ok: false, status: 'invalid_query', results: [] } : { ok: true, results: [] });
      assert.equal(parsed.messages.at(-1).tool_call_id, calls[i].id);
      assert.equal(parsed.toolsPending, i < size - 1);
    }
    assert.equal(parsed.pendingTool, undefined);
    assert.equal(parsed.pendingTools, undefined);
    assert.equal(parsed.callIndex, 1);
    assert.equal(modelRequest(parsed, 'system').body.tool_choice, size === 3 ? 'none' : 'auto');
    assert.deepEqual(parsed.messages.filter(m => m.role === 'tool').map(m => m.tool_call_id), calls.map(c => c.id));
  }
});

test('invalid batches execute no tools and expose only fixed technical reason codes', () => {
  const state = modelRequest(start(), 'system');
  const call = { id: 'call_1', type: 'function', function: { name: 'Product_Search', arguments: '{}' } };
  const cases = [
    [[null], 'model_tool_invalid_id'],
    [[{ ...call, id: 42 }], 'model_tool_invalid_id'],
    [[call, call], 'model_tool_duplicate_id'],
    [[call, { ...call, id: 'call_2', type: 'unsafe' }], 'model_tool_invalid_type'],
    [[call, { ...call, id: 'call_2', function: { name: 'executeSQL', arguments: '{}' } }], 'model_tool_unknown_name'],
    [[{ ...call, function: { name: 'Product_Search', arguments: {} } }], 'model_tool_invalid_arguments'],
    [[{ ...call, function: { name: 'Product_Search', arguments: 'x'.repeat(6001) } }], 'model_tool_invalid_arguments'],
    [[], 'model_tool_calls_missing'],
    [Array.from({ length: 4 }, (_, i) => ({ ...call, id: 'call_' + i })), 'model_tool_limit']
  ];
  for (const [calls, code] of cases) {
    const parsed = modelResult(state, response({ content: 'private provider text', tool_calls: calls }, 'tool_calls'));
    assert.equal(parsed.response.error.code, code);
    assert.equal(parsed.pendingTool, undefined);
    assert.equal(parsed.toolCount, 0);
    assert(!JSON.stringify(parsed.response).includes('private provider text'));
    assert.match(code, /^[a-z_]{1,64}$/);
  }
  assert.equal(modelResult({ ...state, toolCount: 2 }, response({ tool_calls: [call, { ...call, id: 'call_2' }] }, 'tool_calls')).response.error.code, 'model_tool_limit');
  assert.equal(modelResult({ ...state, callIndex: 4 }, response({ tool_calls: [call] }, 'tool_calls')).response.error.code, 'model_tool_limit');
});

test('provider failure diagnostics remain precise after conservative accounting', () => {
  const state = modelRequest(start(), 'system');
  const cases = [
    [{ statusCode: 503 }, 'model_http_error'],
    [{ statusCode: 200, body: {} }, 'model_message_missing'],
    [response({ content: 'private' }, 'stop', { usage: null }), 'model_usage_invalid'],
    [response({ content: '' }), 'model_empty_content'],
    [response({ content: 'private' }, 'length'), 'model_finish_length'],
    [response({ content: '' }, 'content_filter'), 'model_content_filter'],
    [response({ content: '' }, 'insufficient_system_resource'), 'model_provider_resource'],
    [response({ content: '' }, 'aborted'), 'model_provider_aborted'],
    [response({ content: 'private' }, 'private reason'), 'model_finish_unexpected'],
    [response({ content: '' }, { toString: 'private' }), 'model_finish_unexpected']
  ];
  for (const [result, code] of cases) {
    const parsed = modelResult(state, result);
    assert.equal(parsed.response.error.code, code);
    assert.equal(settled(parsed, { confirmed: false }).response.error.code, code);
    assert.equal(settled(parsed, {}).success, false);
    assert(!JSON.stringify(parsed.response).includes('private'));
  }
  const success = modelResult(state, response({ content: 'hello' }));
  assert.equal(settled(success, {}).response.error.code, 'accounting_unavailable');
});
