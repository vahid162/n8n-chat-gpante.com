'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { safeLink, appendContent, safeThumbnail } = require('../wordpress-plugin/gpante-support-chat/assets/chat.js');

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

test('FAQ citation and verified design support render as safe regular navigation buttons', () => {
  const urls = ['https://gpante.com/faq-ai/#faq-format-cdr','https://t.me/gpante_designer'];
  for (const url of urls) assert.equal(safeLink(url), true);
  for (const url of ['https://gpante.com/faq-ai/#faq-cdr\n','https://gpante.com/faq-ai/#faq-cdr%0a',
    'https://t.me/gpante_designer?x=1','https://t.me/other_support','https://gpante.com/product/a/#faq-cdr']) assert.equal(safeLink(url), false, url);
  global.document = { createTextNode: value => node('#text', value), createElement: tag => node(tag) };
  try {
    const target = node('div');
    appendContent(target, `[پاسخ FAQ](${urls[0]})\n\n[تلگرام طراحی](${urls[1]})`);
    const links = all(target).filter(value => value.type === 'a');
    assert.deepEqual(links.map(value => value.href), urls);
    assert(links.every(value => value.className === 'gpante-chat-link' && value.rel === 'noopener noreferrer' && value.target === '_blank'));
  } finally { delete global.document; }
});

test('only the approved designer phone renders a native call beside the contact page', () => {
  const phone = 'tel:+989179509010';
  const contact = 'https://gpante.com/contact-us/';
  assert.equal(safeLink(phone), true);
  const rejected = ['tel:09179509010', 'tel:+989179509011', 'TEL:+989179509010',
    'tel:+9891795090100', 'tel:+989179509010;ext=1', 'tel:+989179509010?x=1',
    'tel:+989179509010#x', 'tel:+989179509010/path', 'tel:%2B989179509010',
    'tel:+989179509010%0a', 'tel:+989179509010\n', 'tel: +989179509010'];
  for (const value of rejected) assert.equal(safeLink(value), false, value);
  global.document = { createTextNode: value => node('#text', value), createElement: tag => node(tag) };
  try {
    for (const content of [`[09179509010](${phone}) [تماس با ما](${contact})`, `${phone} ${contact}`]) {
      const target = node('div');
      appendContent(target, content);
      const links = all(target).filter(value => value.type === 'a');
      assert.deepEqual(links.map(value => value.href), [phone, contact]);
      assert.equal(links[0].textContent, '09179509010');
      assert.equal(links[0].target, undefined, 'native calls do not open an empty tab');
      assert.equal(links[0].title, 'تماس مستقیم با طراح');
      assert.equal(links[1].target, '_blank');
      assert.equal(target.children.length, 2, 'each standalone destination keeps its own readable bubble');
    }
    const target = node('div');
    appendContent(target, rejected.map(value => `[نامعتبر](${value})`).join('\n'));
    assert.equal(all(target).filter(value => value.type === 'a').length, 0);
    const plain = node('div');appendContent(plain, '09179509010');
    assert.equal(all(plain).filter(value => value.type === 'a').length, 0, 'plain untrusted numbers are not auto-linked');
  } finally { delete global.document; }
});

test('exact public account, cart and checkout landing pages render as links without exposing private destinations', () => {
  const urls = ['https://gpante.com/my-account/', 'https://gpante.com/cart/', 'https://gpante.com/checkout/'];
  for (const url of urls) {
    assert.equal(safeLink(url), true, url);
    for (const suffix of ['downloads/', 'order-received/123/', '?key=private', '#private']) assert.equal(safeLink(url + suffix), false, url + suffix);
    assert.equal(safeLink(url.slice(0, -1)), false, 'only the canonical landing page is allowed');
  }
  global.document = {createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target = node('div');
    appendContent(target, urls.map(url => `[صفحه](${url})`).join('\n'));
    assert.deepEqual(all(target).filter(value => value.type === 'a').map(value => value.href), urls);
  } finally { delete global.document; }
});

function node(type, text = '') { return { type, textContent: text, children: [],
    append(...items) { this.children.push(...items); },
    setAttribute(name, value) { this[name] = value; },
    addEventListener(name, callback) { (this.listeners ||= {})[name] = callback; },
    set innerHTML(value) { throw new Error('HTML rendering is forbidden'); }
  }; }
const all = root => [root, ...root.children.flatMap(all)];
const text = root => root.textContent + root.children.map(text).join('');

test('recommendations have separate bubbles, one-line facts and a verified thumbnail beside each title', () => {
  global.document = {createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target=node('div');
    const reply='این دو گزینه مناسب‌اند.\n\n**محصول اول**\nتوضیح اول\n\n| ویژگی | مشخصات |\n| --- | --- |\n| فرمت | DXF |\n| قیمت | رایگان |\n\n[مشاهدهٔ محصول](https://gpante.com/product/a/)\n\n**محصول دوم**\nتوضیح دوم\n\n- متریال: MDF\n- ضخامت: ۳ میلی‌متر\n\n[مشاهدهٔ محصول](https://gpante.com/product/b/)\n\nبرای انتخاب راهنمایی می‌کنم.';
    const previews=['a','b'].map(key=>({url:'https://gpante.com/product/'+key+'/',title:'عنوان '+key,thumbnail:'https://gpante.com/wp-content/uploads/2026/10/'+key+'-150x150.webp'}));
    appendContent(target,reply,previews);
    assert.equal(target.children.length,4,'intro, each recommendation and follow-up are distinct bubbles');
    assert.equal(all(target).filter(n=>n.type==='table').length,0);
    assert.equal(all(target).filter(n=>n.type==='li').length,4);
    const images=all(target).filter(n=>n.type==='img');
    assert.deepEqual(images.map(n=>n.src),previews.map(p=>p.thumbnail));
    assert(images.every(n=>n.alt===''&&n.loading==='lazy'));
    assert(text(target.children[1]).includes('DXF')&&text(target.children[1]).includes('رایگان'));
    assert(text(target.children[2]).includes('MDF')&&text(target.children[2]).includes('۳ میلی‌متر'));
    assert.equal(all(target.children[1]).filter(n=>n.type==='a')[0].href,previews[0].url);
  } finally {delete global.document;}
});

test('model image markup and untrusted preview URLs never become images; a failed public image leaves readable text', () => {
  for (const url of ['https://evil.example/a.jpg','https://gpante.com/wp-content/paid.jpg','https://gpante.com/wp-content/uploads/../secret.jpg','https://gpante.com/wp-content/uploads/%2e%2e/secret.jpg','https://gpante.com/wp-content/uploads/a.svg','https://gpante.com/wp-content/uploads/a.jpg?secret=1']) assert.equal(safeThumbnail(url),false,url);
  global.document={createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target=node('div');
    appendContent(target,'**محصول**\nتوضیح\n\n[مشاهده](https://gpante.com/product/a/)',[{url:'https://gpante.com/product/b/',title:'نامرتبط',thumbnail:'https://gpante.com/wp-content/uploads/b.jpg'}]);
    assert.equal(all(target).filter(n=>n.type==='img').length,0,'a different product cannot supply this thumbnail');
    const valid=node('div');appendContent(valid,'[مشاهده](https://gpante.com/product/a/)',[{url:'https://gpante.com/product/a/',title:'محصول',thumbnail:'https://gpante.com/wp-content/uploads/a.jpg'}]);
    const img=all(valid).find(n=>n.type==='img');img.listeners.error();
    assert.equal(img.hidden,true);assert(text(valid).includes('محصول'));
  } finally {delete global.document;}
});

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
    const children = all(target);
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

test('legacy details become a semantic list without executing HTML, while links stay allowlisted', () => {
  global.document = { createTextNode: value => node('#text', value), createElement: tag => node(tag) };
  try {
    const target = node('div');
    assert.equal(appendContent(target, '**باکس آجیل**\n\n| ویژگی | مشخصات |\n| --- | --- |\n| قیمت | رایگان |\n| فرمت | DXF \\| CDR |\n| توضیح | <img src=x onerror=alert(1)> |\n| لینک نامعتبر | [فایل](https://gpante.com/wp-content/paid.zip) |\n\n[مشاهدهٔ محصول](https://gpante.com/product/a/)'), true);
    const nodes = all(target);
    assert.equal(nodes.filter(n => n.type === 'table').length, 0);
    assert.equal(nodes.filter(n => n.type === 'ul').length, 1);
    assert.equal(nodes.filter(n => n.type === 'li').length, 4);
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

test('legacy comparison headers and values survive as a list without a wide table', () => {
  global.document={createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target=node('div');
    appendContent(target,'| محصول | قیمت | فرمت |\n| --- | --- | --- |\n| باکس | رایگان | DXF |');
    for(const value of ['محصول','قیمت','فرمت','باکس','رایگان','DXF'])assert(text(target).includes(value));
    assert.equal(all(target).filter(n=>n.type==='table').length,0);
    assert.equal(all(target).filter(n=>n.type==='li').length,1);
  }finally{delete global.document;}
});

test('standalone product links in the same paragraph receive distinct bubbles without borrowing shared facts', () => {
  global.document={createTextNode:value=>node('#text',value),createElement:tag=>node(tag)};
  try {
    const target=node('div');
    appendContent(target,'**پیشنهادها**\nتوضیح مشترک\n\n[اول](https://gpante.com/product/a/) [دوم](https://gpante.com/product/b/)');
    assert.equal(target.children.length,3);
    assert(text(target.children[0]).includes('توضیح مشترک'));
    assert.equal(all(target.children[1]).filter(n=>n.type==='a').length,1);
    assert.equal(all(target.children[2]).filter(n=>n.type==='a').length,1);
  }finally{delete global.document;}
});
