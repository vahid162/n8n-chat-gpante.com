'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const source = readFileSync(require.resolve('../integration/wordpress/assets/chat.js'), 'utf8');
const previousMessages = [
  { role: 'user', content: 'باکس MDF می‌خواهم.' },
  { role: 'assistant', content: 'چه ضخامتی نیاز دارید؟' }
];
const tick = () => new Promise(resolve => setImmediate(resolve));

function createWidget() {
  const document = { activeElement: null };
  // Only the DOM operations used by the actual controller are needed here.
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag;
      this.children = [];
      this.dataset = {};
      this.attributes = {};
      this.listeners = {};
      this.hidden = false;
      this.disabled = false;
      this.value = '';
      this.className = '';
      this.text = '';
    }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this.replaceChildren(); this.text = String(value); }
    set innerHTML(_) { throw new Error('The controller must not render HTML.'); }
    append(...children) {
      for (const child of children) { child.parent = this; this.children.push(child); }
    }
    replaceChildren(...children) {
      for (const child of this.children) child.parent = null;
      this.children = [];
      this.text = '';
      this.append(...children);
    }
    remove() {
      if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1);
      this.parent = null;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    contains(element) { return this === element || this.children.some(child => child.contains(element)); }
    focus() { document.activeElement = this; }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    dispatch(name, values = {}) {
      const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...values };
      for (const listener of this.listeners[name] || []) listener(event);
      return event;
    }
  }
  document.createElement = tag => new Element(tag);
  document.createTextNode = text => { const node = new Element('#text'); node.textContent = text; return node; };
  const elements = Object.fromEntries([
    'root', 'panel', 'launcher', 'messages', 'status', 'input', 'form', 'send', 'new',
    'confirm', 'clear', 'retry', 'count', 'close', 'cancel'
  ].map(name => [name, new Element()]));
  const { root, panel, launcher } = elements;
  root.dataset.endpoint = '/wp-json/gpante-support/v1/chat';
  root.append(panel, launcher);
  panel.append(...Object.entries(elements).filter(([name]) => !['root', 'panel', 'launcher'].includes(name)).map(([, node]) => node));
  panel.hidden = elements.confirm.hidden = elements.retry.hidden = true;
  root.querySelector = selector => selector === '#gpante-support-panel' ? panel : elements[selector.slice(6, -1)];
  document.getElementById = id => id === 'gpante-support' ? root : null;
  const requests = [];
  const timers = new Map();
  let nextTimer = 0;
  const context = vm.createContext({
    document, AbortController,
    setTimeout(callback) { const id = ++nextTimer; timers.set(id, callback); return id; },
    clearTimeout(id) { timers.delete(id); },
    fetch(url, options) {
      return new Promise((resolve, reject) => {
        const request = { url, options, body: JSON.parse(options.body), resolve, reject };
        requests.push(request);
        options.signal.addEventListener('abort', () => {
          const error = new Error('Aborted');
          error.name = 'AbortError';
          reject(error);
        }, { once: true });
      });
    }
  });
  // No CommonJS module is provided: this executes initialization and event handlers.
  vm.runInContext(source, context, { filename: 'integration/wordpress/assets/chat.js' });
  return {
    elements, document, requests, timers,
    click(name) { elements[name].dispatch('click'); },
    type(text) { elements.input.value = text; elements.input.dispatch('input'); },
    submit() { return elements.form.dispatch('submit'); },
    messages() {
      return elements.messages.children.map(item => ({
        role: item.className.endsWith('--user') ? 'user' : 'assistant',
        content: item.children[1].textContent
      }));
    },
    async respond(index, data, ok = true) {
      requests[index].resolve({ ok, json: async () => data });
      await tick();
      assert.equal(timers.size, 0, 'each completed request clears its timeout');
    },
    async open(messages = previousMessages) {
      this.click('launcher');
      await this.respond(requests.length - 1, { messages });
    }
  };
}

function assertControls(widget, busy) {
  for (const name of ['input', 'new', 'clear', 'retry']) {
    assert.equal(widget.elements[name].disabled, busy, name + ' disabled state');
  }
  assert.equal(widget.elements.messages.attributes['aria-busy'], String(busy));
  assert.equal(widget.elements.send.disabled, busy || !widget.elements.input.value.trim());
}

test('opening loads persisted history through the browser controller and enables composing', async () => {
  const widget = createWidget();
  widget.click('launcher');
  assert.equal(widget.elements.panel.hidden, false);
  assert.equal(widget.elements.launcher.attributes['aria-expanded'], 'true');
  assert.equal(widget.document.activeElement, widget.elements.panel);
  assertControls(widget, true);
  assert.equal(widget.requests.length, 1);
  const request = widget.requests[0];
  assert.equal(request.url, '/wp-json/gpante-support/v1/chat');
  assert.deepEqual(request.body, { action: 'loadPreviousSession' });
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.cache, 'no-store');
  await widget.respond(0, { messages: previousMessages });
  assert.deepEqual(widget.messages(), previousMessages);
  assertControls(widget, false);
  assert.equal(widget.document.activeElement, widget.elements.input);
  widget.type('۲٫۷ میلی‌متر');
  assert.equal(widget.elements.send.disabled, false);
  assert.equal(widget.elements.root.dataset.error, 'false');
});

test('busy send blocks duplicates; failure restores the draft and one explicit retry succeeds', async () => {
  const widget = createWidget();
  await widget.open();
  widget.type('  ضخامت ۲٫۷ و فایل DXF  ');
  assert.equal(widget.submit().defaultPrevented, true);
  assertControls(widget, true);
  assert.equal(widget.elements.input.value, '');
  assert.deepEqual(widget.messages().at(-1), { role: 'user', content: 'ضخامت ۲٫۷ و فایل DXF' });
  widget.submit();
  widget.elements.input.dispatch('keydown', { key: 'Enter', shiftKey: false, isComposing: false });
  widget.click('retry');
  assert.equal(widget.requests.length, 2, 'duplicate events do not send another request');
  assert.deepEqual(widget.requests[1].body, { action: 'sendMessage', chatInput: 'ضخامت ۲٫۷ و فایل DXF' });
  await widget.respond(1, { error: 'unavailable', output: 'لطفاً دوباره تلاش کنید.' }, false);
  assert.deepEqual(widget.messages(), previousMessages, 'the temporary user bubble is removed');
  assert.equal(widget.elements.input.value, 'ضخامت ۲٫۷ و فایل DXF');
  assert.equal(widget.elements.retry.hidden, false);
  assert.equal(widget.elements.root.dataset.error, 'true');
  assertControls(widget, false);
  widget.click('retry');
  widget.click('retry');
  assert.equal(widget.requests.length, 3, 'one retry sends exactly one request');
  assert.deepEqual(widget.requests[2].body, widget.requests[1].body);
  await widget.respond(2, { output: 'این مشخصات را بررسی می‌کنم.' });
  assert.deepEqual(widget.messages(), [...previousMessages,
    { role: 'user', content: 'ضخامت ۲٫۷ و فایل DXF' },
    { role: 'assistant', content: 'این مشخصات را بررسی می‌کنم.' }
  ]);
  assert.equal(widget.elements.input.value, '');
  assert.equal(widget.elements.retry.hidden, true);
  assert.equal(widget.elements.root.dataset.error, 'false');
  assertControls(widget, false);
  widget.type('پرسش بعدی');
  assert.equal(widget.elements.send.disabled, false);
});

test('failed reset preserves history and draft; a successful reset replaces them with the welcome', async () => {
  const widget = createWidget();
  await widget.open();
  widget.type('پرسش نوشته‌شده');
  widget.click('new');
  assert.equal(widget.elements.confirm.hidden, false);
  assert.equal(widget.document.activeElement, widget.elements.clear);
  widget.click('clear');
  assert.equal(widget.elements.confirm.hidden, true);
  assertControls(widget, true);
  assert.deepEqual(widget.messages(), previousMessages);
  assert.deepEqual(widget.requests[1].body, { action: 'resetSession' });
  await widget.respond(1, { ok: false });
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'پرسش نوشته‌شده');
  assert.equal(widget.elements.retry.hidden, false);
  assert.equal(widget.elements.retry.textContent, 'تلاش دوباره برای پاک‌کردن');
  assertControls(widget, false);
  widget.click('retry');
  assert.equal(widget.requests.length, 3);
  await widget.respond(2, { ok: true });
  assert.equal(widget.messages().length, 1);
  assert.equal(widget.messages()[0].role, 'assistant');
  assert.match(widget.messages()[0].content, /^سلام! دنبال چه طرح یا فایلی هستید؟/u);
  assert.equal(widget.elements.input.value, '');
  assert.equal(widget.elements.retry.hidden, true);
  assert.equal(widget.elements.root.dataset.error, 'false');
  assertControls(widget, false);
  widget.type('شروع دوباره');
  assert.equal(widget.elements.send.disabled, false);
});

test('failed history load permits a retry and restores the normal controls after success', async () => {
  const widget = createWidget();
  widget.click('launcher');
  await widget.respond(0, { error: 'unavailable', output: 'تاریخچه در دسترس نیست.' }, false);
  assert.equal(widget.elements.retry.hidden, false);
  assert.equal(widget.elements.status.textContent, 'تاریخچه در دسترس نیست.');
  assertControls(widget, false);
  widget.click('retry');
  assertControls(widget, true);
  assert.deepEqual(widget.requests[1].body, { action: 'loadPreviousSession' });
  await widget.respond(1, { messages: previousMessages });
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.retry.hidden, true);
  assertControls(widget, false);
});

test('closing and reopening during a request keeps one history load; an aborted send keeps focus outside', async () => {
  const widget = createWidget();
  widget.click('launcher');
  widget.click('close');
  assert.equal(widget.document.activeElement, widget.elements.launcher);
  widget.click('launcher');
  assert.equal(widget.requests.length, 1, 'reopening while busy does not duplicate history loading');
  await widget.respond(0, { messages: previousMessages });
  assert.equal(widget.document.activeElement, widget.elements.input);
  widget.type('درخواست طولانی');
  widget.submit();
  widget.click('close');
  assert.equal(widget.elements.panel.hidden, true);
  assert.equal(widget.elements.launcher.attributes['aria-expanded'], 'false');
  assert.equal(widget.document.activeElement, widget.elements.launcher);
  const [timeout] = widget.timers.values();
  timeout();
  await tick();
  assert.equal(widget.requests[1].options.signal.aborted, true);
  assert.equal(widget.timers.size, 0);
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'درخواست طولانی');
  assert.equal(widget.elements.retry.hidden, false);
  assert.match(widget.elements.status.textContent, /دریافت پاسخ طول کشید/u);
  assert.equal(widget.document.activeElement, widget.elements.launcher, 'a hidden panel does not steal focus');
  assertControls(widget, false);
  widget.click('launcher');
  await widget.respond(2, { messages: previousMessages });
  assert.equal(widget.document.activeElement, widget.elements.input);
  assert.equal(widget.elements.input.value, 'درخواست طولانی');
  assertControls(widget, false);
});
