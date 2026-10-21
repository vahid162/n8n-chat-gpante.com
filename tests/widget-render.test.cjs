'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { safeLink, appendContent } = require('../wordpress-plugin/gpante-support-chat/assets/chat.js');

test('only explicit public HTTPS site links survive raw and encoded path attacks', () => {
  for (const value of ['https://gpante.com/product/sample/', 'https://gpante.com/قوانین-و-مقررات/', 'https://gpante.com/product/%D8%A8%D8%A7%DA%A9%D8%B3/']) {
    assert.equal(safeLink(value), true, value);
  }
  for (const value of [
    'javascript:alert(1)', 'http://gpante.com/product/a/', '//gpante.com/product/a/',
    'https://gpante.com@evil.example/', 'https://gpante.com.evil.example/', 'https://gpante.com:443/',
    'https://gpante.com/product/a/?token=private', 'https://gpante.com/product/a/#private',
    'https://gpante.com/wp-json/test', 'https://gpante.com/%77p-json/test',
    'https://gpante.com/wp-content/paid.zip', 'https://gpante.com/wp-admin/',
    'https://gpante.com/wp-login.php', 'https://gpante.com/my-account/downloads/',
    'https://gpante.com/download/paid', 'https://gpante.com/wc-api/test',
    'https://gpante.com/product/../wp-json/test', 'https://gpante.com/product/%2e%2e/wp-json/test',
    'https://gpante.com/product/%252e%252e/wp-json/test', 'https://gpante.com/%2577p-json/test',
    'https://gpante.com/product/%2f..%2fwp-json/test', 'https://gpante.com/product/..\\wp-json/test',
    'https://gpante.com/product/a/%3Fdownload=1', 'https://gpante.com/product/a/%23secret',
    'https://gpante.com/product/%0aevil', 'https://gpante.com/product/%', 'https://gpante.com//wp-json/test'
  ]) assert.equal(safeLink(value), false, value);
});

function node(type, text = '') { return { type, textContent: text, children: [],
    append(...items) { this.children.push(...items); },
    setAttribute(name, value) { this[name] = value; },
    set innerHTML(value) { throw new Error('HTML rendering is forbidden'); }
  }; }
const all = root => [root, ...root.children.flatMap(all)];
const text = root => root.textContent + root.children.map(text).join('');

test('assistant content stays text except independently validated button links', () => {
  global.document = { createTextNode: text => node('#text', text), createElement: tag => node(tag) };
  const target = node('div');
  appendContent(target, '<img src=x onerror=alert(1)> [محصول](https://gpante.com/product/a/) https://evil.example/ [خصوصی](https://gpante.com/wp-content/paid.zip)');
  const links = all(target).filter(child => child.type === 'a');
  assert.equal(links.length, 1);
  assert.equal(links[0].href, 'https://gpante.com/product/a/');
  assert.equal(links[0].textContent, 'محصول');
  assert.equal(links[0].rel, 'noopener noreferrer');
  assert.equal(links[0].target, '_blank');
  assert.equal(links[0].className, 'gpante-chat-link');
  assert(text(target).includes('<img'));
  assert.equal(all(target).some(child => child.type === 'img'), false);
  delete global.document;
});

test('consecutive navigation buttons omit blank separator lines without losing prose or unsafe-link text', () => {
  global.document = {createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target = node('div');
    appendContent(target, '[محصول](https://gpante.com/product/a/) \n [مقاله](https://gpante.com/article/)\nhttps://gpante.com/guide/');
    const children = target.children[0].children;
    assert.equal(children.filter(n=>n.type==='a').length,3);
    assert.equal(children.some(n=>n.type==='#text'&&n.textContent.length>0&&!n.textContent.trim()),false);
    for (const value of ['https://evil.example/ https://other.example/', '[نامعتبر](https://gpante.com/wp-content/paid.zip) متن باقی‌مانده']) {
      const plain=node('div');appendContent(plain,value);assert.equal(text(plain),value);
    }
    const prose=node('div');
    appendContent(prose,'[محصول](https://gpante.com/product/a/)\nتوضیح مفید\n[مقاله](https://gpante.com/article/)');
    assert(text(prose).includes('\nتوضیح مفید\n'));
  } finally {delete global.document;}
});

test('known details become an accessible table without executing HTML, while links stay allowlisted', () => {
  global.document = { createTextNode: value => node('#text', value), createElement: tag => node(tag) };
  try {
    const target = node('div');
    assert.equal(appendContent(target, '**باکس آجیل**\n\n| ویژگی | مشخصات |\n| --- | --- |\n| قیمت | رایگان |\n| فرمت | DXF \\| CDR |\n| توضیح | <img src=x onerror=alert(1)> |\n| لینک نامعتبر | [فایل](https://gpante.com/wp-content/paid.zip) |\n\n[مشاهدهٔ محصول](https://gpante.com/product/a/)'), true);
    const nodes = all(target);
    assert.equal(nodes.filter(n => n.type === 'table').length, 1);
    assert.equal(nodes.filter(n => n.type === 'tr').length, 5);
    assert.equal(nodes.filter(n => n.type === 'th' && n.scope === 'col').length, 2);
    assert.equal(nodes.filter(n => n.type === 'th' && n.scope === 'row').length, 4);
    assert.equal(nodes.filter(n => n.type === 'caption').length, 1);
    assert(nodes.some(n => n.type === 'strong' && n.textContent === 'باکس آجیل'));
    assert(text(target).includes('DXF | CDR'));
    assert(text(target).includes('<img src=x onerror=alert(1)>'));
    assert.equal(nodes.filter(n => ['img', 'script', 'iframe'].includes(n.type)).length, 0);
    assert.equal(nodes.filter(n => n.type === 'a').length, 1);
    assert.equal(nodes.find(n => n.type === 'a').textContent, 'مشاهدهٔ محصول');
  } finally { delete global.document; }
});

test('malformed or oversized tables preserve every value as text, and plain replies still work', () => {
  global.document = { createTextNode: value => node('#text', value), createElement: tag => node(tag) };
  try {
    const malformed = '| ویژگی | مقدار |\n| --- | --- |\n| ردیف | مقدار | اضافی |';
    const oversized = '| ویژگی | مقدار |\n| --- | --- |\n' + Array.from({length:21}, (_, i) => '| ردیف | value-' + i + ' |').join('\n');
    for (const value of [malformed, oversized, 'سلام\nچطور می‌توانم کمک کنم؟']) {
      const target = node('div');
      assert.equal(appendContent(target, value), false);
      assert.equal(text(target), value);
      assert.equal(all(target).filter(n => n.type === 'table').length, 0);
    }
    const raw = node('div');
    appendContent(raw, 'https://gpante.com/product/a/،');
    assert.equal(all(raw).find(n => n.type === 'a').textContent, 'مشاهدهٔ محصول');
    assert(text(raw).endsWith('،'));
  } finally { delete global.document; }
});
