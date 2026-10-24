'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { build, admin, cleanup } = require('../scripts/build-workflow.cjs');
const { buildWidget } = require('../scripts/build-widget.cjs');
const root = path.resolve(__dirname, '..');

for (const [file, generate] of [
  ['workflows/gpante-support-main.json', () => JSON.stringify(build(), null, 2) + '\n'],
  ['workflows/gpante-support-admin.json', () => JSON.stringify(admin(), null, 2) + '\n'],
  ['workflows/gpante-support-retention.json', () => JSON.stringify(cleanup(), null, 2) + '\n'],
  ['integration/wordpress/woodmart-chat.html', buildWidget],
]) {
  test('tracked deployment artifact matches its generator: ' + file, () => {
    assert.equal(fs.readFileSync(path.join(root, file), 'utf8'), generate(),
      'Regenerate the changed workflow/widget before publishing its tracked output.');
  });
}
