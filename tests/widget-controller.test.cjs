'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const source = readFileSync(require.resolve('../wordpress-plugin/gpante-support-chat/assets/chat.js'), 'utf8');
const policy = {version:2,userDays:30,archiveDays:30,allowClear:true,revision:1};
const expiry = new Date(Date.now()+86400000).toISOString();
const previousMessages = [
  { role: 'user', content: 'باکس MDF می‌خواهم.' },
  { role: 'assistant', content: 'چه ضخامتی نیاز دارید؟' }
];
const tick = () => new Promise(resolve => setImmediate(resolve));

function createWidget(appearance, withHistory = false) {
  const document = { activeElement: null };
  // Only the DOM operations used by the actual controller are needed here.
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag;
      this.children = [];
      this.dataset = {};
      this.style = {setProperty: (key,value) => { this.styles ||= {}; this.styles[key] = value; }};
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
    get parentElement() { return this.parent; }
    appendChild(child) { child.remove(); this.append(child); return child; }
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
    'confirm', 'clear', 'retry', 'reconnect', 'notice', 'activity', 'activity-text', 'count', 'close', 'cancel', 'shortcuts', 'send-label', 'title', 'subtitle', 'launcher-label', 'input-label', 'status-label', 'status-text'
  ].map(name => [name, new Element()]));
  const { root, panel, launcher } = elements;
  document.body = new Element('body');
  const footer = new Element('footer');
  footer.append(root);
  document.body.append(footer);
  root.dataset.appearanceEndpoint = '/wp-json/gpante-support/v1/appearance';
  root.dataset.endpoint = '/wp-json/gpante-support/v1/chat';
  root.append(panel, launcher);
  panel.append(...Object.entries(elements).filter(([name]) => !['root', 'panel', 'launcher', 'status-label', 'status-text'].includes(name)).map(([, node]) => node));
  elements.status.append(elements['status-label'], elements['status-text']);
  panel.hidden = elements.confirm.hidden = elements.retry.hidden = true;
  root.querySelector = selector => selector === '#gpante-support-panel' ? panel : elements[selector.slice(6, -1)];
  const documentEvents = new Element();
  document.addEventListener = (...args) => documentEvents.addEventListener(...args);
  document.dispatch = (...args) => documentEvents.dispatch(...args);
  document.getElementById = id => id === 'gpante-support' ? root : null;
  const requests = [];
  const appearanceRequests = [];
  const timers = new Map();
  let nextTimer = 0;
  let browserWindow;
  if (withHistory) {
    const events = new Element();
    const states = [{screen:'previous'},{screen:'current',router:'preserved'}];
    let index=1;
    browserWindow={location:{href:'https://gpante.com/current/'},addEventListener:(...args)=>events.addEventListener(...args),dispatch:(...args)=>events.dispatch(...args),
      history:{get state(){return states[index];},get length(){return states.length;},get index(){return index;},
        pushState(state){states.splice(index+1);states.push(state);index++;},
        back(){Promise.resolve().then(()=>{if(index>0){index--;events.dispatch('popstate');}});},
        forward(){Promise.resolve().then(()=>{if(index+1<states.length){index++;events.dispatch('popstate');}});}
      }};
  }
  const context = vm.createContext({
    document, AbortController, URL, navigator: {onLine:true}, ...(browserWindow ? {window:browserWindow} : {}),
    setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, {callback,delay}); return id; },
    clearTimeout(id) { timers.delete(id); },
    fetch(url, options) {
      if (options.method === 'GET') {
        appearanceRequests.push({url,options});
        return appearance instanceof Error ? Promise.reject(appearance) : Promise.resolve({ok:true,json:async()=>appearance || {}});
      }
      return new Promise((resolve, reject) => {
        const request = { url, options, body: JSON.parse(options.body), resolve, reject, timeoutId: [...timers.keys()].at(-1) };
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
  vm.runInContext(source, context, { filename: 'wordpress-plugin/gpante-support-chat/assets/chat.js' });
  return {
    elements, document, requests, appearanceRequests, timers, window:browserWindow,
    network(online) { context.navigator.onLine = online; browserWindow.dispatch(online ? 'online' : 'offline'); },
    initializeAgain() { vm.runInContext(source, context); },
    click(name) { elements[name].dispatch('click'); },
    type(text) { elements.input.value = text; elements.input.dispatch('input'); },
    submit() { return elements.form.dispatch('submit'); },
    messages() {
      return elements.messages.children.map(item => ({
        role: item.className.endsWith('--user') ? 'user' : 'assistant',
        content: item.children[1].textContent
      }));
    },
    async respond(index, data, ok = true, status = ok ? 200 : 429) {
      data = {policy, expiresAt:expiry, ...data};
      if(Array.isArray(data.messages)) data.messages=data.messages.map(m=>({...m,expiresAt:m.expiresAt||expiry}));
      requests[index].resolve({ ok, status, json: async () => data });
      await tick();
      assert.equal(timers.has(requests[index].timeoutId), false, 'each completed request clears its own network timeout');
    },
    async open(messages = previousMessages) {
      this.click('launcher');
      await this.respond(requests.length - 1, { messages });
    }
  };
}

test('header stays online while reply activity is separate and background refresh does not flicker', async () => {
  const widget = createWidget(undefined, true);await widget.open([]);
  assert.equal(widget.elements['status-label'].textContent, 'Online');
  widget.window.dispatch('focus');
  assert.equal(widget.elements.status.dataset.state, 'ready');
  await widget.respond(1, {messages:[]});
  widget.type('پیام');widget.submit();
  assert.equal(widget.elements.status.dataset.state, 'ready');
  assert.equal(widget.elements.activity.hidden, false);
  assert.match(widget.elements['activity-text'].textContent, /پاسخ/);
  await widget.respond(2, {output:'پاسخ'});
  assert.equal(widget.elements.activity.hidden, true);
  assert.equal(widget.elements.notice.hidden, true);
});

test('uncertain send requires a history check; reconnect never resends and retains messages after a failed refresh', async () => {
  const widget = createWidget(undefined, true);await widget.open();
  widget.type('پرسش محفوظ');widget.submit();
  await widget.respond(1, {error:{code:'backend_unavailable'},output:'دریافت پاسخ تأیید نشد.'}, false, 502);
  assert.equal(widget.elements.status.dataset.state, 'offline');
  assert.equal(widget.elements.retry.hidden, true);
  assert.equal(widget.elements.reconnect.hidden, false);
  widget.submit();
  widget.click('reconnect');widget.click('reconnect');
  assert.equal(widget.requests.length, 3, 'the composer also checks history before an uncertain resend');
  assert.equal(widget.requests[2].body.action, 'loadPreviousSession');
  await widget.respond(2, {error:{code:'backend_unavailable'},output:'سرویس در دسترس نیست.'}, false, 502);
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'پرسش محفوظ');
  widget.click('reconnect');
  await widget.respond(3, {messages:[...previousMessages,{role:'user',content:'پرسش محفوظ'},{role:'assistant',content:'پاسخ ثبت‌شده'}]});
  assert.equal(widget.elements.status.dataset.state, 'ready');
  assert.equal(widget.requests.filter(r=>r.body.action==='sendMessage').length, 1);
  assert.equal(widget.messages().at(-1).content, 'پاسخ ثبت‌شده');
  assert.match(widget.elements['status-text'].textContent, /درخواست تازه/);
});

test('safe temporary refusals retain online status and do not expose an unrelated reconnect action', async () => {
  const widget = createWidget();await widget.open();
  widget.type('پرسش');widget.submit();
  await widget.respond(1, {error:{code:'rate_limit'},output:'۱ دقیقه صبر کنید، سپس روی «تلاش دوباره» بزنید.'}, false, 429);
  assert.equal(widget.elements.status.dataset.state, 'ready');
  assert.equal(widget.elements.reconnect.hidden, true);
  assert.equal(widget.elements.retry.hidden, false);
  assert.match(widget.elements['status-text'].textContent, /۱ دقیقه/);
});

function assertControls(widget, busy, composing = !busy) {
  assert.equal(widget.elements.input.disabled, !composing, 'input disabled state');
  for (const name of ['new', 'clear', 'retry']) {
    assert.equal(widget.elements[name].disabled, busy, name + ' disabled state');
  }
  assert.equal(widget.elements.messages.attributes['aria-busy'], String(busy));
  assert.equal(widget.elements.send.disabled, !composing || !widget.elements.input.value.trim());
}

test('connection status confirms valid service readiness and distinguishes a pending reply', async () => {
  const widget = createWidget();
  widget.click('launcher');
  assert.equal(widget.elements.status.dataset.state, 'connecting');
  assert.equal(widget.elements.input.disabled, false);
  await widget.respond(0, {messages:[]});
  assert.equal(widget.elements.status.dataset.state, 'ready');
  assert.equal(widget.elements['status-label'].textContent, 'Online');
  widget.type('پرسش آزمایشی');widget.submit();
  assert.equal(widget.elements.status.dataset.state, 'ready');
  await widget.respond(1, {output:'پاسخ آزمایشی'});
  assert.equal(widget.elements.status.dataset.state, 'ready');
});

test('invalid history never shows ready; background refresh retains a failed send for explicit retry', async () => {
  const widget = createWidget(undefined,true);
  widget.click('launcher');
  await widget.respond(0, {policy:null,messages:[]});
  assert.equal(widget.elements.status.dataset.state, 'offline');
  widget.click('reconnect');await widget.respond(1,{messages:[]});
  widget.type('پرسش محفوظ');widget.submit();
  await widget.respond(2,{output:'خطای موقت'},false);
  widget.window.dispatch('focus');
  assert.equal(widget.elements.status.dataset.state, 'ready');
  await widget.respond(3,{messages:[]});
  assert.equal(widget.elements.status.dataset.state, 'ready');
  assert.equal(widget.elements.input.value,'پرسش محفوظ');
  assert.equal(widget.elements.retry.hidden,false);
  assert.equal(widget.requests.filter(r=>r.body.action==='sendMessage').length,1);
});

test('offline status preserves composing and online checks the service without resending a message', async () => {
  const widget = createWidget(undefined,true);await widget.open([]);
  widget.type('متن محفوظ');widget.network(false);
  assert.equal(widget.elements.status.dataset.state,'offline');
  assert.equal(widget.elements.input.disabled,false);
  assert.equal(widget.requests.length,1);
  widget.network(true);
  assert.equal(widget.elements.status.dataset.state,'connecting');
  await widget.respond(1,{messages:[]});
  assert.equal(widget.elements.status.dataset.state,'ready');
  assert.equal(widget.elements.input.value,'متن محفوظ');
  assert.equal(widget.requests.some(r=>r.body.action==='sendMessage'),false);
  widget.network(false);widget.window.dispatch('focus');
  await widget.respond(2,{messages:[]});
  assert.equal(widget.elements.status.dataset.state,'offline','a response cannot override a known offline browser');
});

test('reconnecting during a pending reply waits for that response without dispatching again', async () => {
  const widget = createWidget(undefined,true);await widget.open([]);
  widget.type('یک پیام');widget.submit();widget.network(false);
  assert.equal(widget.elements.status.dataset.state,'offline');
  assert.equal(widget.elements.input.disabled,true);
  widget.network(true);
  assert.equal(widget.elements.status.dataset.state,'connecting');
  assert.equal(widget.requests.length,2);
  await widget.respond(1,{output:'یک پاسخ'});
  assert.equal(widget.elements.status.dataset.state,'ready');
  assert.equal(widget.requests.filter(r=>r.body.action==='sendMessage').length,1);
});

test('restored assistant details render as list bubbles and buttons while user Markdown stays plain text', async () => {
  const widget = createWidget();
  const content = '**محصول**\n\n| ویژگی | مشخصات |\n| --- | --- |\n| قیمت | رایگان |\n| فرمت | DXF |\n\n[مشاهدهٔ محصول](https://gpante.com/product/a/)';
  await widget.open([{role:'user',content},{role:'assistant',content}]);
  const [user, assistant] = widget.elements.messages.children;
  assert.equal(user.children[1].textContent, content);
  assert.equal(user.children[1].children.length, 0);
  assert(assistant.className.includes('gpante-chat-message--rich'));
  const descendants = element => [element, ...element.children.flatMap(descendants)];
  assert.equal(descendants(assistant).filter(element => element.tagName === 'table').length, 0);
  assert.equal(descendants(assistant).filter(element => element.tagName === 'li').length, 2);
  const link = descendants(assistant).find(element => element.tagName === 'a');
  assert.equal(link.href, 'https://gpante.com/product/a/');
  assert.equal(link.className, 'gpante-chat-link');
  assertControls(widget, false);
});

test('a second script load does not attach duplicate handlers or duplicate a request', async () => {
  const widget = createWidget();
  widget.initializeAgain();
  assert.equal(widget.elements.root.parentElement, widget.document.body);
  widget.click('launcher');
  assert.equal(widget.elements.panel.hidden, false);
  assert.equal(widget.requests.length, 1);
  await widget.respond(0, { messages: previousMessages });
  widget.type('سلام');
  widget.submit();
  assert.equal(widget.requests.length, 2);
  await widget.respond(1, { output: 'سلام' });
});

test('opening allows immediate focused composing while persisted history loads', async () => {
  const widget = createWidget();
  widget.click('launcher');
  assert.equal(widget.elements.panel.hidden, false);
  assert.equal(widget.elements.launcher.attributes['aria-expanded'], 'true');
  assert.equal(widget.document.activeElement, widget.elements.input);
  assertControls(widget, true, true);
  assert.equal(widget.requests.length, 1);
  const request = widget.requests[0];
  assert.equal(request.url, '/wp-json/gpante-support/v1/chat');
  assert.deepEqual(request.body, { action: 'loadPreviousSession', policyVersion:0 });
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.cache, 'no-store');
  widget.type('پیش‌نویس هنگام اتصال');
  assert.equal(widget.elements.send.disabled, false);
  await widget.respond(0, { messages: previousMessages });
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'پیش‌نویس هنگام اتصال');
  assertControls(widget, false);
  assert.equal(widget.document.activeElement, widget.elements.input);
  widget.type('۲٫۷ میلی‌متر');
  assert.equal(widget.elements.send.disabled, false);
  assert.equal(widget.elements.root.dataset.error, 'false');
});

test('early submit waits for the existing history request and dispatches exactly once with validated policy', async () => {
  const widget = createWidget();
  widget.click('launcher');
  widget.type('پیام هنگام اتصال');
  widget.submit();
  widget.submit();
  widget.elements.input.dispatch('keydown', { key: 'Enter' });
  assert.equal(widget.requests.length, 1, 'no send or duplicate history before session readiness');
  assert.equal(widget.elements.input.value, 'پیام هنگام اتصال');
  assertControls(widget, true);
  assert.match(widget.elements['activity-text'].textContent, /آمادهٔ ارسال/u);
  widget.click('close');
  widget.click('launcher');
  assert.equal(widget.requests.length, 1, 'rapid reopen keeps the queued operation');
  await widget.respond(0, { messages: previousMessages, policy: {...policy, allowClear: false} });
  assert.equal(widget.requests.length, 2);
  assert.deepEqual(widget.requests[1].body, {action:'sendMessage', policyVersion:2, chatInput:'پیام هنگام اتصال'});
  assert.equal(widget.elements.new.hidden, true, 'current policy is applied before dispatch');
  assert.deepEqual(widget.messages(), [...previousMessages, {role:'user',content:'پیام هنگام اتصال'}]);
  widget.submit();
  assert.equal(widget.requests.length, 2);
  await widget.respond(1, { output:'پاسخ پس از اتصال' });
  assert.equal(widget.messages().at(-1).content, 'پاسخ پس از اتصال');
  assertControls(widget, false);
});

test('failed initialization preserves the early draft; reconnect checks history and a separate retry sends it', async () => {
  const widget = createWidget();
  widget.click('launcher');widget.type('پرسش محفوظ');widget.submit();
  await widget.respond(0, {error:true,output:'ارتباط برقرار نشد.'}, false, 502);
  assert.equal(widget.requests.length, 1);
  assert.equal(widget.elements.input.value, 'پرسش محفوظ');
  assert.equal(widget.elements.reconnect.hidden, false);
  assert.equal(widget.elements.retry.hidden, true);
  assertControls(widget, false);
  widget.click('reconnect');widget.click('reconnect');
  assert.equal(widget.requests.length, 2);
  assert.equal(widget.requests[1].body.action, 'loadPreviousSession');
  await widget.respond(1, {messages:[]});
  assert.equal(widget.requests.length, 2, 'connection recovery does not send the former queued draft');
  assert.equal(widget.elements.retry.hidden, false);
  widget.click('retry');
  assert.equal(widget.requests[2].body.chatInput, 'پرسش محفوظ');
  await widget.respond(2, {output:'پاسخ معتبر'});
  assert.equal(widget.messages().at(-1).content, 'پاسخ معتبر');
});

test('editing after failed initialization replaces the queued draft without an automatic resend', async () => {
  const widget = createWidget();
  widget.click('launcher');widget.type('متن قبلی');widget.submit();
  await widget.respond(0, {error:true,output:'قطع ارتباط'}, false);
  widget.type('متن تازه');
  assert.equal(widget.elements.retry.hidden, true);
  widget.submit();
  assert.equal(widget.requests[1].body.action, 'loadPreviousSession');
  await widget.respond(1, {messages:[]});
  assert.equal(widget.requests[2].body.chatInput, 'متن تازه');
  await widget.respond(2, {output:'پاسخ تازه'});
  assert(!widget.messages().some(m=>m.content==='متن قبلی'));
});

test('background history refresh keeps composing active and serializes an early send without stealing outside focus', async () => {
  const widget = createWidget(undefined, true);
  await widget.open();
  widget.type('پیام هنگام بازخوانی');
  widget.window.dispatch('focus');
  assert.equal(widget.requests[1].body.action, 'loadPreviousSession');
  assertControls(widget, true, true);
  widget.submit();
  const outside = widget.document.createElement('a');
  widget.document.activeElement = outside;
  await widget.respond(1, {messages:previousMessages});
  assert.equal(widget.requests.length, 3);
  assert.equal(widget.requests[2].body.chatInput, 'پیام هنگام بازخوانی');
  assert.equal(widget.document.activeElement, outside);
  await widget.respond(2, {output:'پاسخ'});
  assert.equal(widget.document.activeElement, outside);
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
  assert.deepEqual(widget.requests[1].body, { action: 'sendMessage', policyVersion:2, chatInput: 'ضخامت ۲٫۷ و فایل DXF' });
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

test('temporary refusal shows the supplied wait and next step, preserves the draft and never resends automatically', async () => {
  const widget = createWidget();
  await widget.open();
  widget.type('پرسش هنگام شلوغی');
  widget.submit();
  const guidance = 'دستیار مشغول است؛ ۱۰ ثانیه صبر کنید، سپس روی «تلاش دوباره» بزنید.';
  await widget.respond(1, {error:{code:'capacity'},output:guidance}, false);
  assert.equal(widget.elements['status-text'].textContent.startsWith(guidance), true);
  assert.match(widget.elements['status-text'].textContent, /متن نوشته‌شده در کادر محفوظ/);
  assert.equal(widget.elements.input.value, 'پرسش هنگام شلوغی');
  assert.equal(widget.elements.input.disabled, false, 'composing remains available');
  assert.equal(widget.elements.retry.hidden, false);
  assert.deepEqual(widget.messages(), previousMessages, 'refusal does not add a successful user turn');
  await tick();
  assert.equal(widget.requests.length, 2, 'no automatic retry or timer dispatch');
  widget.type('پرسش اصلاح‌شده');
  assert.equal(widget.elements.retry.hidden, true, 'editing discards the stale retry');
  widget.submit();
  assert.equal(widget.requests.length, 3);
  assert.equal(widget.requests[2].body.chatInput, 'پرسش اصلاح‌شده');
  await widget.respond(2, {output:'پاسخ تازه'});
  assert.equal(widget.elements.retry.hidden, true);
  assert.equal(widget.elements.root.dataset.error, 'false');
});

test('history refresh, focus and reopen retain the failed action while policy continues refreshing', async () => {
  const widget = createWidget(undefined, true);
  await widget.open();
  widget.type('پرسش منتظر تلاش دوباره');
  widget.submit();
  const refusal = {error:{code:'rate_limit'},output:'۱ دقیقه صبر کنید، سپس روی «تلاش دوباره» بزنید.'};
  await widget.respond(1, refusal, false);
  const guidance = widget.elements['status-text'].textContent;
  const [id, timer] = [...widget.timers].find(([,timer]) => timer.delay === 60000);
  widget.timers.delete(id); timer.callback();
  assert.equal(widget.requests[2].body.action, 'loadPreviousSession');
  await widget.respond(2, {messages:previousMessages,policy:{...policy,userDays:7,allowClear:false,revision:2}});
  assert.equal(widget.elements['status-text'].textContent, guidance);
  assert.equal(widget.elements.retry.hidden, false);
  assert.equal(widget.elements.new.hidden, true, 'fresh central policy remains authoritative');
  widget.window.dispatch('focus');
  await widget.respond(3, {messages:previousMessages});
  widget.click('close'); await tick();
  widget.click('launcher');
  await widget.respond(4, {messages:previousMessages});
  assert.equal(widget.elements['status-text'].textContent, guidance);
  assert.equal(widget.elements.retry.hidden, false);
  assert.equal(widget.elements.input.value, 'پرسش منتظر تلاش دوباره');
  assert.equal(widget.requests.filter(r => r.body.action === 'sendMessage').length, 1);
  widget.click('retry');
  assert.equal(widget.requests[5].body.chatInput, 'پرسش منتظر تلاش دوباره');
  await widget.respond(5, {output:'پاسخ پس از تلاش صریح'});
  assert.equal(widget.elements.retry.hidden, true);
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
  assert.deepEqual(widget.requests[1].body, { action: 'resetSession', policyVersion:2 });
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
  assert.equal(widget.elements.reconnect.hidden, false);
  assert.equal(widget.elements['status-text'].textContent, 'تاریخچه در دسترس نیست.');
  assertControls(widget, false);
  widget.click('reconnect');
  assertControls(widget, true, true);
  assert.deepEqual(widget.requests[1].body, { action: 'loadPreviousSession', policyVersion:0 });
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
  timeout.callback();
  await tick();
  assert.equal(widget.requests[1].options.signal.aborted, true);
  assert.equal(widget.timers.size, 0);
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'درخواست طولانی');
  assert.equal(widget.elements.reconnect.hidden, false);
  assert.match(widget.elements['status-text'].textContent, /زمان دریافت پاسخ/u);
  assert.equal(widget.document.activeElement, widget.elements.launcher, 'a hidden panel does not steal focus');
  assertControls(widget, false);
  widget.click('launcher');
  await widget.respond(2, { messages: previousMessages });
  assert.equal(widget.document.activeElement, widget.elements.input);
  assert.equal(widget.elements.input.value, 'درخواست طولانی');
  assertControls(widget, false);
});

test('keyboard composing preserves Shift+Enter and IME; Escape cancels reset before closing the panel', async () => {
  const widget = createWidget();
  await widget.open([]);
  widget.type('پرسش فارسی');
  assert.equal(widget.elements.input.dispatch('keydown', { key: 'Enter', shiftKey: true }).defaultPrevented, false);
  assert.equal(widget.elements.input.dispatch('keydown', { key: 'Enter', isComposing: true }).defaultPrevented, false);
  assert.equal(widget.requests.length, 1, 'newlines and composition do not send');
  assert.equal(widget.elements.input.dispatch('keydown', { key: 'Enter' }).defaultPrevented, true);
  assert.equal(widget.requests.length, 2);
  await widget.respond(1, { output: 'پاسخ آزمایشی فارسی' });
  widget.click('new');
  assert.equal(widget.elements.confirm.hidden, false);
  assert.equal(widget.elements.root.dispatch('keydown', { key: 'Escape' }).defaultPrevented, true);
  assert.equal(widget.elements.confirm.hidden, true);
  assert.equal(widget.elements.panel.hidden, false);
  assert.equal(widget.document.activeElement, widget.elements.new);
  assert.equal(widget.requests.length, 2, 'canceling reset does not delete server history');
  widget.elements.root.dispatch('keydown', { key: 'Escape' });
  assert.equal(widget.elements.panel.hidden, true);
  assert.equal(widget.document.activeElement, widget.elements.launcher);
});

test('invalid history and reply fail visibly; oversized input does not dispatch a request', async () => {
  const widget = createWidget();
  widget.click('launcher');
  await widget.respond(0, { messages: [{ role: 'system', content: 'internal' }] });
  assert.deepEqual(widget.messages(), []);
  assert.equal(widget.elements.root.dataset.error, 'true');
  assert.equal(widget.elements.reconnect.hidden, false);
  widget.click('reconnect');
  await widget.respond(1, { messages: previousMessages });
  widget.type('م'.repeat(4001));
  widget.submit();
  assert.equal(widget.requests.length, 2);
  assert.equal(widget.elements.root.dataset.error, 'true');
  widget.type('پرسش معتبر');
  widget.submit();
  await widget.respond(2, { output: '' });
  assert.deepEqual(widget.messages(), previousMessages);
  assert.equal(widget.elements.input.value, 'پرسش معتبر');
  assert.equal(widget.elements.reconnect.hidden, false);
  assert.equal(widget.elements.root.dataset.error, 'true');
  widget.click('reconnect');
  await widget.respond(3, { messages: previousMessages });
  assert.equal(widget.requests.length, 4, 'invalid reply recovery checks history only');
  widget.click('retry');
  await widget.respond(4, { output: 'پاسخ معتبر' });
  assert.equal(widget.messages().at(-1).content, 'پاسخ معتبر');
  assert.equal(widget.elements.retry.hidden, true);
  assertControls(widget, false);
});

// Real Chrome revealed that hiding/disabling the focused control blurs it to body.
test('completed requests restore focus after native blur but preserve focus deliberately moved outside', async () => {
  const widget = createWidget();
  await widget.open();
  widget.type('سلام'); widget.submit();
  widget.document.activeElement = widget.document.body;
  await widget.respond(1, {output:'سلام'});
  assert.equal(widget.document.activeElement, widget.elements.input);
  widget.click('new'); widget.click('clear');
  widget.document.activeElement = widget.document.body;
  await widget.respond(2, {ok:true});
  assert.equal(widget.document.activeElement, widget.elements.input);
  widget.type('دوباره'); widget.submit();
  const outside = widget.document.createElement('a');
  widget.document.activeElement = outside;
  await widget.respond(3, {output:'درود'});
  assert.equal(widget.document.activeElement, outside);
});


test('central policy hides clear and prevents direct UI dispatch; deadlines remove displayed text', async () => {
  const widget = createWidget();
  widget.click('launcher');
  await widget.respond(0,{messages:[{role:'user',content:'already expired',expiresAt:new Date(Date.now()-1000).toISOString()}],policy:{...policy,userDays:3,allowClear:false}});
  assert.equal(widget.elements.new.hidden,true);
  assert.equal(widget.elements.policy,undefined,'removed notice has no DOM element');
  assert.equal(widget.messages().length,0,'expired DOM removed immediately');
  const before=widget.requests.length;
  widget.click('new');widget.click('clear');
  assert.equal(widget.requests.length,before,'disabled clear cannot dispatch even via direct handler');
  widget.click('close');
  assert.equal(widget.timers.size,0,'closing stops background history reads');
});

test('missing policy allows a draft but never sends or clears until initialization validates it', async () => {
  const widget = createWidget();
  widget.click('launcher');
  await widget.respond(0,{messages:[],policy:null});
  assert.equal(widget.elements.input.disabled,false);
  widget.type('question');widget.submit();widget.click('clear');
  assert.equal(widget.requests.length,2);
  assert.equal(widget.requests[1].body.action,'loadPreviousSession');
  await widget.respond(1,{messages:[],policy:null});
  assert.equal(widget.requests.length,2,'invalid policy never dispatches sendMessage');
  assert.equal(widget.elements.input.value,'question');
  assert.equal(widget.elements.input.disabled,false);
  assert.equal(widget.elements.new.hidden,true);
});


function customAppearance() {
  return {version:1,texts:{title:'راهنمای فروشگاه',subtitle:'پاسخ پرسش‌ها',launcher:'پرسش دارید؟',welcome:'سلام؛ چطور کمک کنم؟',input_label:'متن پرسش',placeholder:'موضوع را بنویسید',send:'بفرست',clear:'گفتگوی تازه'},colors:{primary:'#123456',on_primary:'#ffffff',surface:'#ffffff',text:'#111111',messages:'#eeeeee',user:'#dddddd',assistant:'#ffffff',accent:'#aa5500'}};
}

test('fresh appearance bypasses a cached page, updates safe text/colors and preserves policy enforcement', async () => {
  const config = customAppearance();
  const widget = createWidget(config);
  await widget.open([]);
  assert.equal(widget.appearanceRequests.length,1);
  const request=widget.appearanceRequests[0];
  assert.equal(request.options.cache,'no-store');
  assert.equal(request.options.credentials,'omit');
  assert.equal(request.url,'/wp-json/gpante-support/v1/appearance');
  assert.equal(widget.elements.title.textContent,config.texts.title);
  assert.equal(widget.elements['launcher-label'].textContent,config.texts.launcher);
  assert.equal(widget.elements.launcher.attributes['aria-label'],config.texts.launcher);
  assert.equal(widget.elements.input.attributes.placeholder,config.texts.placeholder);
  assert.equal(widget.elements.root.styles['--gpante-primary'],'#123456');
  assert.deepEqual(widget.messages(),[{role:'assistant',content:config.texts.welcome}]);
  assert.equal(widget.elements.messages.children[0].children[0].textContent,config.texts.title);
  assert.equal(widget.elements.policy,undefined);
  widget.initializeAgain();
  assert.equal(widget.appearanceRequests.length,1,'one public presentation read per page');
  assert.equal([...widget.timers.values()].filter(t=>t.delay===6000).length,0);
});

test('malformed colors, future schema and failed appearance request preserve usable defaults', async () => {
  for (const value of [new TypeError('offline'),{...customAppearance(),version:99},{...customAppearance(),colors:{...customAppearance().colors,primary:'#123456;position:fixed'}}]) {
    const widget=createWidget(value);
    await widget.open([]);
    assert.match(widget.messages()[0].content,/سلام/);
    assert.equal(widget.elements.root.styles,undefined);
    widget.type('سلام');
    assert.equal(widget.elements.send.disabled,false,'presentation failure does not disable chat');
  }
});

test('appearance strings are plain text and cannot render markup or override central policy', async () => {
  const config=customAppearance();
  config.texts.title='<img src=x onerror=alert(1)>';
  config.texts.welcome='<script>bad()</script>';
  config.texts.policy='آرشیوی وجود ندارد';
  const widget=createWidget(config);
  await widget.open([]);
  assert.equal(widget.elements.title.textContent,config.texts.title);
  assert.equal(widget.elements.title.children.length,0);
  assert.equal(widget.messages()[0].content,config.texts.welcome);
  assert.equal(widget.elements.policy,undefined);
  assert.equal(widget.elements.new.hidden,false,'custom copy does not override central clear policy');
});

test('a slow appearance response updates an already visible welcome without blocking history', async () => {
  let resolve;
  const pending=new Promise(done=>{resolve=done;});
  const widget=createWidget(pending);
  await widget.open([]);
  assert.match(widget.messages()[0].content,/سلام!/);
  assert.equal(widget.elements.input.disabled,false);
  resolve(customAppearance());
  await tick();
  assert.equal(widget.messages()[0].content,customAppearance().texts.welcome);
  assert.equal(widget.elements.messages.children[0].children[0].textContent,customAppearance().texts.title);
  assert.equal(widget.elements.policy,undefined);
});


test('configured shortcuts render only nonempty valid labels, support HTTPS links and stay plain text', async () => {
  const config=customAppearance();
  config.links=[{label:'پیدا کردن طرح',url:'/shop/'},{label:'',url:'https://example.com/'},{label:'<b>راهنما</b>',url:'https://example.com/help/'},{label:'bad',url:'javascript:alert(1)'}];
  const widget=createWidget(config);
  await widget.open([]);
  assert.equal(widget.elements.shortcuts.hidden,false);
  assert.equal(widget.elements.shortcuts.children.length,2);
  assert.equal(widget.elements.shortcuts.children[0].href,'https://gpante.com/shop/');
  assert.equal(widget.elements.shortcuts.children[1].href,'https://example.com/help/');
  assert.equal(widget.elements.shortcuts.children[1].textContent,'<b>راهنما</b>');
  assert.equal(widget.elements.shortcuts.children[1].children.length,0);
  assert.equal(widget.elements.send.attributes['aria-label'],config.texts.send);
  assert.equal(widget.elements['send-label'].textContent,config.texts.send);
});

test('all blank shortcut labels keep the navigation hidden', async () => {
  const config=customAppearance();
  config.links=Array.from({length:4},()=>({label:'  ',url:'https://example.com/'}));
  const widget=createWidget(config);await widget.open([]);
  assert.equal(widget.elements.shortcuts.hidden,true);
  assert.equal(widget.elements.shortcuts.children.length,0);
});

test('Back closes only the open chat entry, second Back reaches the previous page, Forward reopens once', async () => {
  const widget=createWidget(undefined,true);
  await widget.open();
  assert.equal(widget.window.history.index,2);
  assert.equal(widget.window.history.state.router,'preserved');
  widget.type('پیش‌نویس محفوظ');
  widget.window.history.back();await tick();
  assert.equal(widget.elements.panel.hidden,true);
  assert.equal(widget.elements.input.value,'پیش‌نویس محفوظ');
  assert.equal(widget.window.history.index,1);
  widget.window.history.back();await tick();
  assert.equal(widget.window.history.index,0,'no dead chat entry');
  widget.window.history.forward();await tick();
  assert.equal(widget.elements.panel.hidden,true);
  widget.window.history.forward();await tick();
  assert.equal(widget.elements.panel.hidden,false);
  assert.equal(widget.window.history.length,3,'Forward does not push another entry');
  await widget.respond(1,{messages:previousMessages});
});

test('X, Escape and rapid reopen consume exactly one owned entry without closing a newer open', async () => {
  const widget=createWidget(undefined,true);await widget.open();
  widget.click('close');
  widget.click('launcher');
  await tick();
  assert.equal(widget.elements.panel.hidden,false);
  assert.equal(widget.window.history.index,2);
  await widget.respond(1,{messages:previousMessages});
  widget.elements.root.dispatch('keydown',{key:'Escape'});await tick();
  assert.equal(widget.window.history.index,1);
  assert.equal(widget.elements.panel.hidden,true);
  assert.equal(widget.document.activeElement,widget.elements.launcher);
});

test('outside click closes chat without stealing focus or discarding an in-flight response', async () => {
  const widget=createWidget(undefined,true);await widget.open();
  widget.document.dispatch('click',{target:widget.elements.input});
  assert.equal(widget.elements.panel.hidden,false,'inside controls stay usable');
  widget.type('سلام');widget.submit();
  const outside=widget.document.createElement('button');outside.focus();
  widget.document.dispatch('click',{target:outside});await tick();
  assert.equal(widget.elements.panel.hidden,true);
  assert.equal(widget.document.activeElement,outside);
  assert.equal(widget.window.history.index,1);
  await widget.respond(1,{output:'درود'});
  assert.equal(widget.elements.panel.hidden,true);
  assert.equal(widget.document.activeElement,outside);
});

test('history API failure does not disable the chat or outside close', async () => {
  const widget=createWidget(undefined,true);
  widget.window.history.pushState=()=>{throw new Error('unsupported embed');};
  await widget.open();
  assert.equal(widget.elements.input.disabled,false);
  widget.document.dispatch('click',{target:widget.document.body});
  assert.equal(widget.elements.panel.hidden,true);
  assert.equal(widget.window.history.index,1);
});


test('welcome has a separate full-width presentation marker without changing assistant role', async () => {
  const config=customAppearance();
  const widget=createWidget(config);await widget.open([]);
  const welcome=widget.elements.messages.children[0];
  assert.equal(welcome.dataset.role,'assistant');
  assert.match(welcome.className,/gpante-chat-message--welcome/);
  widget.type('سلام');widget.submit();
  await widget.respond(1,{output:'درود'});
  const [intro,user,assistant]=widget.elements.messages.children;
  assert.equal(intro,welcome);
  assert.equal(user.dataset.role,'user');
  assert.equal(assistant.dataset.role,'assistant');
  assert.doesNotMatch(assistant.className,/--welcome/,'ordinary replies stay normal bubbles');
});


test('budget refusal does not disconnect or encourage an immediate retry, and clear reconnect never deletes', async () => {
  const widget=createWidget();await widget.open();
  widget.type('پرسش');widget.submit();
  await widget.respond(1,{error:{code:'monthly_budget'},output:'سهمیهٔ این ماه به پایان رسیده است.'},false,502);
  assert.equal(widget.elements.status.dataset.state,'ready');
  assert.equal(widget.elements.retry.hidden,true);
  assert.equal(widget.elements.reconnect.hidden,true);
  widget.click('new');widget.click('clear');
  await widget.respond(2,{error:{code:'backend_unavailable'},output:'پاک‌کردن تأیید نشد.'},false,502);
  widget.click('reconnect');
  await widget.respond(3,{messages:previousMessages});
  assert.equal(widget.requests.filter(r=>r.body.action==='resetSession').length,1);
  assert.equal(widget.elements.retry.textContent,'تلاش دوباره برای پاک‌کردن');
  widget.click('retry');await widget.respond(4,{ok:true});
  assert.equal(widget.requests.filter(r=>r.body.action==='resetSession').length,2);
});


test('failed history refresh preserves unexpired messages but still removes known expired text without network retries', async () => {
  const widget=createWidget(undefined,true);await widget.open();
  widget.window.dispatch('focus');
  widget.elements.messages.children[0].dataset.expiresAt=new Date(Date.now()-1000).toISOString();
  widget.elements.messages.children[1].dataset.expiresAt=new Date(Date.now()+30*86400000).toISOString();
  await widget.respond(1,{error:{code:'backend_unavailable'},output:'Unavailable'},false,502);
  assert.deepEqual(widget.messages(),[previousMessages[1]]);
  assert([...widget.timers.values()].some(t=>t.delay>60000),'remaining message expiry has a local timer even without valid policy');
  assert([...widget.timers.values()].every(t=>t.delay<=2147483647),'a 30-day deadline must not overflow browser timeouts into an immediate loop');
  widget.click('close');await tick();
  assert.equal(widget.timers.size,0);
});


test('fresh and restored assistant products receive the same thumbnails while user text remains plain', async () => {
  const widget=createWidget();
  const content='**طرح باکس**\nتوضیح کوتاه\n\n[مشاهده](https://gpante.com/product/a/)';
  const products=[{url:'https://gpante.com/product/a/',title:'عنوان واقعی',thumbnail:'https://gpante.com/wp-content/uploads/a-150x150.webp'}];
  const descendants=e=>[e,...e.children.flatMap(descendants)];
  await widget.open([{role:'user',content,products},{role:'assistant',content,products}]);
  assert.equal(descendants(widget.elements.messages.children[0]).filter(e=>e.tagName==='img').length,0);
  assert.equal(descendants(widget.elements.messages.children[1]).filter(e=>e.tagName==='img').length,1);
  widget.type('دیگری');widget.submit();await widget.respond(1,{output:content,products});
  assert.equal(descendants(widget.elements.messages.children.at(-1)).filter(e=>e.tagName==='img').length,1);
});
