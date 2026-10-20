'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');

function buildWidget() {
  const read = file => fs.readFileSync(path.join(root, 'wordpress-plugin/gpante-support-chat', file), 'utf8').trim();
  const css = read('assets/chat.css');
  const markup = execFileSync('php', [path.join(__dirname, 'render-widget.php')], {encoding: 'utf8'}).trim();
  const script = read('assets/chat.js');
  if (/<\/style/i.test(css) || /<\/script/i.test(script) || /<\?php/.test(markup)) throw new Error('Unsafe inline boundary.');
  return '<!-- GPante Support Chat: paste once in the global WoodMart footer. Requires the server-side chat endpoint. -->\n'
    + '<style>\n' + css + '\n</style>\n\n' + markup + '\n\n<script>\n' + script + '\n</script>\n';
}

if (require.main === module) {
  const output = path.join(root, 'integration/wordpress/woodmart-chat.html');
  fs.writeFileSync(output, buildWidget());
  console.log('Built self-contained WoodMart HTML (no keys or external assets): ' + output);
}
module.exports = { buildWidget };
