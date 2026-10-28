'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const root = '../wordpress-plugin/gpante-support-chat/';
const script = () => readFileSync(require.resolve(root + 'assets/admin.js'), 'utf8');
const palette = {primary:'#365e28',on_primary:'#ffffff',surface:'#ffffff',text:'#263023',messages:'#f7f9f4',user:'#e9f0e2',assistant:'#ffffff',accent:'#fbbc34'};

function fixture({clipboard, confirm, initialColors, inputColors} = {}) {
  class Element {
    constructor(value = '') { this.value = value; this.dataset = {}; this.listeners = {}; this.attributes = {}; this.hidden = false; this.textContent = ''; this.style = {setProperty:(key,value) => {this.styles ||= {}; this.styles[key] = value;}}; }
    set innerHTML(value) { throw new Error('Preview must render text without HTML'); }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    dispatch(type, extra = {}) { const event = {target:this,defaultPrevented:false,preventDefault() {this.defaultPrevented = true;},...extra}; for (const listener of this.listeners[type] || []) listener(event); return event; }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key]; }
    checkValidity() { return this.value.trim() !== '' && [...this.value].length <= (this.maxLength || Infinity); }
    focus() { document.activeElement = this; }
    select() { document.selected = this; }
    remove() { this.removed = true; }
  }
  const document = new Element();
  const ids = {};
  const field = (id,value,maxLength) => { const node = new Element(value); node.id = id; node.maxLength = maxLength; ids[id] = node; return node; };
  const texts = {};
  for (const key of ['title','subtitle','launcher','welcome','placeholder','clear']) {
    field('gpante-appearance-' + key, 'saved ' + key, key === 'title' ? 80 : 1000);
    texts[key] = new Element(); texts[key].dataset.gpantePreviewText = key;
  }
  field('gpante-appearance-input_label','پرسش شما',80);
  const colors = Object.keys(palette).map(key => field('gpante-color-' + key,inputColors?.[key] ?? palette[key]));
  const mode = field('gpante-color-mode','site');
  const table = field('gpante-appearance-colors','');
  table.dataset.siteColors = JSON.stringify(palette);
  table.dataset.manualColors = JSON.stringify({...palette,primary:'#123456'});
  table.querySelectorAll = () => colors;
  const links = Array.from({length:4},(_,index) => {field('gpante-link-label-' + index,''); field('gpante-link-url-' + index,''); const node = new Element(); node.dataset.gpantePreviewLink = String(index); return node;});
  const preview = new Element();
  preview.dataset.colors = JSON.stringify(initialColors || palette);
  preview.querySelectorAll = selector => selector === '[data-gpante-preview-text]' ? Object.values(texts) : selector === '[data-gpante-preview-link]' ? links : [];
  preview.querySelector = selector => selector === '[data-gpante-preview-input]' ? texts.placeholder : null;
  const form = new Element();
  preview.closest = () => form;
  const reset = new Element(); reset.dataset.gpanteConfirm = 'همهٔ متن‌ها، رنگ‌ها و چهار لینک بازنشانی می‌شوند. ادامه می‌دهید؟';
  const copy = new Element(); copy.dataset.gpanteCopy = 'embed';
  const code = field('embed',''); code.textContent = '<div>public embed</div>';
  const status = new Element();
  copy.parentElement = {querySelector:() => status};
  document.activeElement = copy;
  document.body = {append:() => {}};
  document.createElement = () => new Element();
  document.execCommand = () => true;
  document.getElementById = id => ids[id] || null;
  document.querySelectorAll = selector => selector === '.gpante-admin-preview' ? [preview] : selector === '[data-gpante-confirm]' ? [reset] : selector === '[data-gpante-copy]' ? [copy] : [];
  const context = {document,URL,navigator:{clipboard},window:{confirm},fetch:() => {throw new Error('Admin preview must not make requests');}};
  const inline = readFileSync(require.resolve(root + 'includes/appearance.php'),'utf8').match(/'(document\.addEventListener\("DOMContentLoaded"[^\n]+)'/)[1];
  vm.runInNewContext(inline,context);
  vm.runInNewContext(script(),context);
  document.dispatch('DOMContentLoaded');
  return {ids,texts,preview,form,mode,links,reset,copy,status,document};
}

test('preview updates unsaved plain text and safe colors without rendering HTML or sending requests', () => {
  const ui = fixture();
  ui.ids['gpante-appearance-title'].value = '<img src=x onerror=alert(1)>';
  ui.ids['gpante-color-primary'].value = '#123456';
  ui.ids['gpante-color-primary'].dispatch('input');
  ui.form.dispatch('input');
  assert.equal(ui.texts.title.textContent,'<img src=x onerror=alert(1)>');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],'#123456');
  ui.ids['gpante-appearance-title'].value = '';
  ui.ids['gpante-color-primary'].value = '#fff;background:url(https://evil.invalid/)';
  ui.form.dispatch('input');
  assert.equal(ui.texts.title.textContent,'<img src=x onerror=alert(1)>');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],'#123456');
});

test('preview follows actual mode handler and preserves the unsaved manual palette across toggles', () => {
  const ui = fixture();
  ui.mode.value = 'custom'; ui.mode.dispatch('change'); ui.form.dispatch('change');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],'#123456');
  ui.ids['gpante-color-primary'].value = '#254769'; ui.ids['gpante-color-primary'].dispatch('input'); ui.form.dispatch('input');
  ui.mode.value = 'site'; ui.mode.dispatch('change'); ui.form.dispatch('change');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],palette.primary);
  ui.mode.value = 'custom'; ui.mode.dispatch('change'); ui.form.dispatch('change');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],'#254769');
});

test('invalid initial or edited colors cannot introduce CSS and valid resolved colors remain available', () => {
  const ui = fixture({initialColors:{...palette,primary:'#fff;background:url(https://evil.invalid/)'},inputColors:{primary:'url(https://evil.invalid/)'}});
  ui.form.dispatch('input');
  assert.equal(ui.preview.styles['--gpante-preview-primary'],palette.primary);
  ui.ids['gpante-appearance-title'].value = 'م'.repeat(81);
  ui.form.dispatch('input');
  assert.equal(ui.texts.title.textContent,'saved title');
  const resolved = fixture({initialColors:{...palette,primary:'#123456'},inputColors:{primary:'invalid'}});
  assert.equal(resolved.preview.styles['--gpante-preview-primary'],'#123456');
});

test('preview shortcuts accept HTTPS or internal paths and never acquire navigable links', () => {
  const ui = fixture();
  ui.ids['gpante-link-label-0'].value = 'راهنما';
  for (const url of ['/shop/','https://example.com/help/?a=1']) {
    ui.ids['gpante-link-url-0'].value = url; ui.form.dispatch('input');
    assert.equal(ui.links[0].hidden,false); assert.equal(ui.links[0].attributes.title,url); assert.equal(ui.links[0].attributes.href,undefined);
  }
  for (const url of ['javascript:alert(1)','data:text/html,x','http://example.com/','//example.com/','https://u:p@example.com/','/\\evil','/%0aevil','https://example.com/<script>']) {
    ui.ids['gpante-link-url-0'].value = url; ui.form.dispatch('input'); assert.equal(ui.links[0].hidden,true,url);
  }
});

test('reset is cancelled unless native confirmation explicitly accepts and never changes fields', () => {
  for (const decision of [undefined,() => false,() => true]) {
    const ui = fixture({confirm:decision});
    assert.equal(ui.reset.dispatch('click').defaultPrevented,decision?.() !== true);
    assert.equal(ui.ids['gpante-appearance-title'].value,'saved title');
  }
});

test('copy reports success, falls back after clipboard rejection, and does not expose source in feedback', async () => {
  let copied;
  const ui = fixture({clipboard:{writeText:async text => {copied = text;}}});
  ui.copy.dispatch('click'); await new Promise(resolve => setImmediate(resolve));
  assert.equal(copied,'<div>public embed</div>'); assert.match(ui.status.textContent,/کپی شد/); assert.doesNotMatch(ui.status.textContent,/public embed/);
  const fallback = fixture({clipboard:{writeText:async () => {throw new Error('denied');}}});
  fallback.copy.dispatch('click'); await new Promise(resolve => setImmediate(resolve));
  assert.match(fallback.status.textContent,/کپی شد/); assert.equal(fallback.document.activeElement,fallback.copy);
  fallback.document.execCommand = () => false;
  fallback.copy.dispatch('click'); await new Promise(resolve => setImmediate(resolve));
  assert.match(fallback.status.textContent,/انجام نشد/); assert.doesNotMatch(fallback.status.textContent,/public embed/);
});
