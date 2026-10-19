'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { safeLink, appendContent } = require('../integration/wordpress/assets/chat.js');

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

test('assistant content stays text except independently validated anchors', () => {
  const node = (type, text = '') => ({ type, textContent: text, children: [],
    append(...items) { this.children.push(...items); },
    set innerHTML(value) { throw new Error('HTML rendering is forbidden'); }
  });
  global.document = { createTextNode: text => node('#text', text), createElement: tag => node(tag) };
  const target = node('div');
  appendContent(target, '<img src=x onerror=alert(1)> [محصول](https://gpante.com/product/a/) https://evil.example/ [خصوصی](https://gpante.com/wp-content/paid.zip)');
  const links = target.children.filter(child => child.type === 'a');
  assert.equal(links.length, 1);
  assert.equal(links[0].href, 'https://gpante.com/product/a/');
  assert.equal(links[0].textContent, 'محصول');
  assert.equal(links[0].rel, 'noopener noreferrer');
  assert.equal(links[0].target, '_blank');
  assert(target.children.some(child => child.type === '#text' && child.textContent.includes('<img')));
  assert.equal(target.children.some(child => child.type === 'img'), false);
  delete global.document;
});
