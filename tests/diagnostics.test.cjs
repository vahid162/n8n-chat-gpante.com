'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { diagnosticQuery, toolDiagnostic, appendDiagnostic, modelDiagnostic, executionDiagnostic } = require('../src/diagnostics.cjs');
const { adminInput } = require('../src/admin.cjs');
const { modelResult } = require('../src/chat.cjs');

test('diagnostics redact identities and secrets and never retain source bodies or arbitrary fields', () => {
  const event = toolDiagnostic({ pendingTool: { function: { name: 'Knowledge_Search', arguments: JSON.stringify({ source: 'site', search: 'کورل person@example.com ۰۹۱۲۳۴۵۶۷۸۹ https://evil.test/key', sql: 'secret' }) } } },
    { source: 'site', status: 'ok', results: [{ id: 12, kind: 'page', content: 'private source body', url: 'https://evil.test' }], coverage: { complete: false } });
  assert.equal(event.source, 'site'); assert.equal(event.coverage, 'incomplete');
  assert.deepEqual(event.results, [{ id: 12, rank: 1, kind: 'page' }]);
  assert(!JSON.stringify(event).includes('example.com')); assert(!JSON.stringify(event).includes('09123456789'));
  assert(!JSON.stringify(event).includes('evil.test')); assert(!JSON.stringify(event).includes('private'));
  assert.equal(diagnosticQuery('a'.repeat(200)).length <= 120, true);
  assert.equal(diagnosticQuery('token_fixture_AbCdGhIjKlMnOpQrStUvWxYz1234'), '[redacted]');
  assert.equal(diagnosticQuery('AbCdGhIjKlMnOpQrStUvWxYz1234'), '[redacted]');
});
test('diagnostic caps and rejection codes are bounded without changing the response', () => {
  let state = { response: { error: { code: 'grounding_source_required' } } };
  for (let i = 0; i < 30; i++) state = modelDiagnostic(state);
  assert.equal(state.diagnostics.length, 12);
  assert.equal(appendDiagnostic(state, { event: 'source' }).diagnostics.length, 12);
  assert.deepEqual(state.response, { error: { code: 'grounding_source_required' } });
  assert.equal(modelDiagnostic({ groundingError: 'raw provider payload!' }).diagnostics, undefined);
});
test('only fixed private admin operations can inspect diagnostics or mark a known synthetic turn', () => {
  assert.deepEqual(adminInput({ operation: 'getDiagnostics', sql: 'secret' }), { operation: 'getDiagnostics' });
  assert.deepEqual(adminInput({ operation: 'markSynthetic', requestId: 'a'.repeat(32), traffic: 'human' }), { operation: 'markSynthetic', requestId: 'a'.repeat(32) });
  assert.deepEqual(adminInput({ operation: 'markSynthetic', requestId: 'bad' }), {});
});
test('successful correction does not repeat an inherited rejection event', () => {
  const parsed = modelResult({ chatInput: 'سلام', callIndex: 2, toolCount: 0, groundingError: 'source_required', messages: [] },
    { statusCode: 200, body: { usage: { prompt_tokens: 1, completion_tokens: 1, total_tokens: 2 }, choices: [{ finish_reason: 'stop', message: { content: 'سلام' } }] } });
  assert.equal(parsed.success, true); assert.equal(parsed.groundingError, undefined);
  assert.equal(modelDiagnostic(parsed).diagnostics, undefined);
});
test('failed detail lookups retain only valid public target IDs, without arbitrary arguments', () => {
  for (const [tool, field] of [['Product_Details', 'productId'], ['Knowledge_Read', 'contentId']]) {
    const state = { pendingTool: { function: { name: tool, arguments: JSON.stringify({ [field]: 456, body: 'private argument' }) } } };
    const event = toolDiagnostic(state, { status: 'api_error', results: [] });
    assert.equal(event.targetId, 456); assert.equal(event.query, '');
    assert(!JSON.stringify(event).includes('private'));
    for (const id of [-1, 0, '456', 'private@example.invalid', Number.MAX_SAFE_INTEGER + 1]) {
      state.pendingTool.function.arguments = JSON.stringify({ [field]: id });
      assert.equal(toolDiagnostic(state, { status: 'api_error' }).targetId, undefined);
    }
  }
});
test('execution metadata accepts only release/hash and bounded model/tool counts', () => {
  const revision = { workflowVersion: '1.9.0', sourceRevision: 'a'.repeat(64), secret: 'private' };
  const state = { callIndex: 4, toolCount: 3, messages: [{ content: 'private transcript' }] };
  assert.deepEqual(executionDiagnostic(state, revision), { event: 'execution', workflowVersion: '1.9.0', sourceRevision: 'a'.repeat(64), modelRounds: 4, toolCalls: 3 });
  for (const invalid of [{ callIndex: 5 }, { toolCount: 4 }, { callIndex: '1' }, { toolCount: -1 }]) {
    assert.equal(executionDiagnostic({ ...state, ...invalid }, revision), null);
  }
  assert.equal(executionDiagnostic(state, { ...revision, workflowVersion: 'private-text' }), null);
  assert.equal(executionDiagnostic(state, { ...revision, sourceRevision: 'https://private.invalid' }), null);
});
