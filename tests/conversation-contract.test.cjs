'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateTrusted, admitted } = require('../src/chat.cjs');
const { build } = require('../scripts/build-workflow.cjs');
const base = { sessionId: 'a'.repeat(64), clientId: 'b'.repeat(64), action: 'loadPreviousSession', policyVersion: 2 };
test('paged user history keeps reset marker and IDs outside short model messages', () => {
  const result = { allowed: true, messages: [{ role: 'user', content: 'q', requestId: 'c'.repeat(32), expiresAt: '2026-11-01T00:00:00Z' }, { role: 'assistant', content: 'a', requestId: 'c'.repeat(32), feedback: 'helpful' }],
    hasMore: true, nextCursor: '10', historyResetAt: '2026-10-24T00:00:00Z', requestId: 'd'.repeat(32) };
  const page = admitted(base, result).response;
  assert.equal(page.historyResetAt, result.historyResetAt); assert.equal(page.nextCursor, '10'); assert.equal(page.hasMore, true);
  assert.equal(page.messages[1].requestId, 'c'.repeat(32)); assert.equal(page.messages[1].feedback, 'helpful');
  const model = admitted({ ...base, action: 'sendMessage', chatInput: 'next' }, result);
  assert.deepEqual(model.messages[0], { role: 'user', content: 'q' });
});
test('replay and owned feedback complete without model dispatch or raw fields', () => {
  const replay = admitted({ ...base, action: 'sendMessage' }, { allowed: true, replay: true, requestId: 'c'.repeat(32), output: 'saved', secret: 'raw' });
  assert.equal(replay.done, true); assert.equal(replay.response.output, 'saved'); assert.equal(replay.response.secret, undefined);
  assert.equal(admitted({ ...base, action: 'feedback' }, { allowed: true, ok: true }).done, true);
  for (const fields of [{ sendId: 'bad' }, { sendId: ['a'.repeat(32)] }, { historyCursor: ['1'] }, { historyCursor: '-1' }, { action: 'feedback', feedbackRequestId: 'x', feedbackValue: 'helpful' }]) {
    assert.equal(validateTrusted({ ...base, ...fields }).valid, false);
  }
});
test('generated graph passes all nine admission fields and persists bounded diagnostic metadata', () => {
  const graph = build(); const node = name => graph.nodes.find(n => n.name === name);
  assert.match(node('Admit Request').parameters.query, /admit_v4.*\$9::text/);
  assert.match(node('Admit Request').parameters.options.queryReplacement, /historyCursor.*feedbackRequestId.*feedbackValue/);
  assert.match(node('Finish Request').parameters.query, /finish_v4.*\$5::jsonb/);
  assert.match(node('Record Tool Facts 1').parameters.jsCode, /appendDiagnostic\(toolResult/);
  assert.equal(graph.nodes.length, 69);
});
