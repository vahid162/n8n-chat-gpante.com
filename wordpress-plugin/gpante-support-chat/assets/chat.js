(function () {
  'use strict';

  function safeLink(address) {
    if (typeof address !== 'string' || !/^https:\/\/gpante\.com\/[^\s?#\\]*$/u.test(address)) return false;
    try {
      // Validate before URL normalization, which would hide traversal segments.
      const path = decodeURIComponent(address.slice('https://gpante.com'.length));
      return !/[\\\x00-\x20\x7f%?#]/u.test(path) && !path.includes('//')
        && !/(?:^|\/)\.{1,2}(?:\/|$)/u.test(path)
        && !/^\/(?:wp-json|wp-content|wp-includes|wp-admin|wp-login\.php|wp-signup\.php|xmlrpc\.php|download|downloads|wc-api|my-account|checkout|cart)(?:\/|$)/iu.test(path);
    } catch (_) {
      return false;
    }
  }

  function shortcutUrl(address, base = 'https://gpante.com/') {
    if (typeof address !== 'string' || address.length > 2048
      || /[\\\x00-\x20\x7f]|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i.test(address)
      || !/^(?:https:\/\/|\/(?!\/))/.test(address)) return null;
    try {
      const url = new URL(address, base);
      return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
    } catch (_) { return null; }
  }

  function appendText(container, text) {
    let end = 0;
    for (const match of text.matchAll(/\*\*([^*\n]{1,300})\*\*/gu)) {
      container.append(document.createTextNode(text.slice(end, match.index)));
      const strong = document.createElement('strong');
      strong.textContent = match[1];
      container.append(strong);
      end = match.index + match[0].length;
    }
    container.append(document.createTextNode(text.slice(end)));
  }

  function appendInline(container, content) {
    const pattern = /\[([^\]\n]{1,300})\]\((https:\/\/[^\s)]+)\)|https:\/\/[^\s<>()\[\]]+/gu;
    let end = 0;
    let previousLink = false;
    for (const match of content.matchAll(pattern)) {
      const address = match[2] || match[0].replace(/[.,،؛]+$/u, '');
      const allowed = safeLink(address);
      const between = content.slice(end, match.index);
      if (!previousLink || !allowed || between.trim()) appendText(container, between);
      if (allowed) {
        const link = document.createElement('a');
        link.href = address;
        link.className = 'gpante-chat-link';
        link.textContent = match[1]?.replace(/\*\*/g, '') || (address.startsWith('https://gpante.com/product/') ? 'مشاهدهٔ محصول' : 'مشاهدهٔ صفحه');
        link.title = 'بازکردن در برگهٔ تازه';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        container.append(link);
        if (!match[2]) container.append(document.createTextNode(match[0].slice(address.length)));
      } else {
        container.append(document.createTextNode(match[0]));
      }
      previousLink = allowed;
      end = match.index + match[0].length;
    }
    appendText(container, content.slice(end));
  }

  function tableCells(line) {
    const value = line.trim();
    if (!value.startsWith('|') || !value.endsWith('|')) return null;
    const cells = [];
    let cell = '';
    for (let i = 1; i < value.length - 1; i++) {
      if (value[i] === '\\' && value[i + 1] === '|') { cell += '|'; i++; }
      else if (value[i] === '|') { cells.push(cell.trim()); cell = ''; }
      else cell += value[i];
    }
    cells.push(cell.trim());
    return cells.length >= 2 && cells.length <= 4 ? cells : null;
  }

  function readTable(lines, start) {
    const header = tableCells(lines[start]);
    const separator = tableCells(lines[start + 1] || '');
    if (!header || !separator || separator.length !== header.length || header.some(cell => !cell)
      || separator.some(cell => !/^:?-{3,}:?$/.test(cell))) return null;
    const rows = [];
    let next = start + 2;
    while (next < lines.length && lines[next].trim().startsWith('|')) {
      const cells = tableCells(lines[next]);
      if (!cells || cells.length !== header.length || rows.length >= 20) return null;
      rows.push(cells);
      next++;
    }
    return rows.length ? { header, rows, next } : null;
  }

  function appendTable(container, data) {
    const wrapper = document.createElement('div');
    wrapper.className = 'gpante-chat-table-wrap';
    if (data.header.length > 2) {
      wrapper.tabIndex = 0;
      wrapper.setAttribute('role', 'region');
      wrapper.setAttribute('aria-label', 'جدول مشخصات؛ برای دیدن ستون‌ها به طرفین پیمایش کنید');
    }
    const table = document.createElement('table');
    table.className = 'gpante-chat-table';
    const caption = document.createElement('caption');
    caption.className = 'gpante-chat-sr-only';
    caption.textContent = 'مشخصات و جزئیات';
    table.append(caption);
    const head = document.createElement('thead');
    const body = document.createElement('tbody');
    for (const [index, cells] of [data.header, ...data.rows].entries()) {
      const row = document.createElement('tr');
      for (const [column, value] of cells.entries()) {
        const cell = document.createElement(index === 0 || column === 0 ? 'th' : 'td');
        if (index === 0 || column === 0) cell.scope = index === 0 ? 'col' : 'row';
        cell.setAttribute('dir', 'auto');
        appendInline(cell, value);
        row.append(cell);
      }
      (index === 0 ? head : body).append(row);
    }
    table.append(head, body);
    wrapper.append(table);
    container.append(wrapper);
  }

  function appendContent(container, content) {
    const lines = content.replace(/\r\n?/g, '\n').split('\n');
    let buffer = [];
    let rich = false;
    const flush = () => {
      const text = buffer.join('\n').trim();
      buffer = [];
      if (!text) return;
      const paragraph = document.createElement('p');
      appendInline(paragraph, text);
      container.append(paragraph);
    };
    for (let i = 0; i < lines.length;) {
      const table = readTable(lines, i);
      if (table) {
        flush();
        appendTable(container, table);
        rich = true;
        i = table.next;
      } else {
        if (lines[i].trim()) buffer.push(lines[i]);
        else flush();
        i++;
      }
    }
    flush();
    return rich;
  }

  // The same rendering functions are exercised by the dependency-free Node checks.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { safeLink, appendContent, shortcutUrl };
    return;
  }

  const root = document.getElementById('gpante-support');
  if (!root || root.dataset.gpanteInitialized === 'true') return;
  root.dataset.gpanteInitialized = 'true';
  // Footer/page-builder transforms must not contain the fixed chat panel.
  if (document.body && root.parentElement !== document.body) document.body.appendChild(root);
  const find = selector => root.querySelector(selector);
  const panel = find('#gpante-support-panel');
  const launcher = find('[data-launcher]');
  const messages = find('[data-messages]');
  const scrollArea = find('[data-scroll]') || messages;
  const shortcuts = find('[data-shortcuts]');
  const status = find('[data-status]');
  const input = find('[data-input]');
  const form = find('[data-form]');
  const send = find('[data-send]');
  const fresh = find('[data-new]');
  const confirmation = find('[data-confirm]');
  const clear = find('[data-clear]');
  const retry = find('[data-retry]');
  let busy = null;
  let historyRequest = null;
  let pendingSend = false;
  let requestHadFocus = false;
  let failed = null;
  let policy = null;
  let historyTimer = null;
  let welcomeItem = null;
  const appearanceTextLimits = {title:80,subtitle:160,launcher:60,welcome:1000,input_label:80,placeholder:250,send:40,clear:100};
  let appearanceTexts = {title:'دستیار پانته',welcome:'سلام! دنبال چه طرح یا فایلی هستید؟ موضوع، متریال، ضخامت و فرمت دلخواهتان را بنویسید تا دقیق‌تر راهنمایی‌تان کنم.'};

  function applyAppearance(value) {
    const colors = ['primary','on_primary','surface','text','messages','user','assistant','accent'];
    if (value?.version !== 1 || !value.texts || !value.colors
      || Object.entries(appearanceTextLimits).some(([key,limit]) => typeof value.texts[key] !== 'string'
        || !value.texts[key].trim() || [...value.texts[key]].length > limit)
      || colors.some(key => typeof value.colors[key] !== 'string' || !/^#[0-9a-f]{6}$/i.test(value.colors[key]))) return;
    appearanceTexts = Object.fromEntries(Object.keys(appearanceTextLimits).map(key => [key,value.texts[key]]));
    const fields = {title:'title',subtitle:'subtitle',launcher:'launcher-label',input_label:'input-label',send:'send-label',clear:'new'};
    for (const [key,selector] of Object.entries(fields)) {
      const element = find('[data-' + selector + ']');
      if (element) element.textContent = appearanceTexts[key];
    }
    input.setAttribute('placeholder', appearanceTexts.placeholder);
    send.setAttribute('aria-label', appearanceTexts.send);
    send.setAttribute('title', appearanceTexts.send);
    launcher.setAttribute('aria-label', appearanceTexts.launcher);
    for (const key of colors) root.style.setProperty('--gpante-' + key.replace('_','-'), value.colors[key]);
    for (const item of messages.children) {
      if (item.dataset.role === 'assistant') item.children[0].textContent = appearanceTexts.title;
    }
    if (welcomeItem && messages.contains(welcomeItem)) {
      welcomeItem.children[1].replaceChildren();
      appendContent(welcomeItem.children[1], appearanceTexts.welcome);
    }
    if (shortcuts) {
      shortcuts.replaceChildren();
      for (const link of Array.isArray(value.links) ? value.links.slice(0,4) : []) {
        const url = shortcutUrl(link?.url, typeof window !== 'undefined' ? window.location.href : undefined);
        if (!url || typeof link?.label !== 'string' || !link.label.trim() || [...link.label].length > 80) continue;
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.textContent = link.label;
        shortcuts.append(anchor);
      }
      shortcuts.hidden = !shortcuts.children.length;
    }
  }

  try { applyAppearance(JSON.parse(root.dataset.appearance || '{}')); } catch (_) { /* Keep server-rendered defaults. */ }
  // Read once per page, even behind a full-page cache. This never calls n8n or the model.
  if (root.dataset.appearanceEndpoint) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 6000);
    fetch(root.dataset.appearanceEndpoint, {
      method:'GET', mode:'same-origin', credentials:'omit', redirect:'error', cache:'no-store',
      headers:{Accept:'application/json'}, signal:controller.signal
    }).then(response => response.ok ? response.json() : null).then(applyAppearance)
      .catch(() => {}).finally(() => clearTimeout(timer));
  }

  function updateInput() {
    find('[data-count]').textContent = input.value.length.toLocaleString('fa-IR') + ' / ۴۰۰۰';
    input.disabled = pendingSend || Boolean(busy && busy !== 'history');
    send.disabled = input.disabled || !input.value.trim();
    if (fresh) fresh.disabled = Boolean(busy || pendingSend);
    if (clear) clear.disabled = Boolean(busy || pendingSend);
    retry.disabled = Boolean(busy || pendingSend);
  }

  function setStatus(text, error) {
    status.textContent = text;
    root.dataset.error = error ? 'true' : 'false';
  }

  function setBusy(action) {
    if (action) requestHadFocus = panel.contains(document.activeElement);
    busy = action;
    messages.setAttribute('aria-busy', String(Boolean(action)));
    updateInput();
    // Chrome moves focus to body when its active control is disabled or hidden.
    if (!action && requestHadFocus && !panel.hidden && document.activeElement === document.body) input.focus({ preventScroll: true });
  }

  function addMessage(role, text, scroll = true, expiresAt) {
    const item = document.createElement('div');
    item.dataset.role = role;
    if (expiresAt) item.dataset.expiresAt = expiresAt;
    item.className = 'gpante-chat-message gpante-chat-message--' + role;
    const label = document.createElement('small');
    label.textContent = role === 'user' ? 'شما' : appearanceTexts.title;
    const body = document.createElement('div');
    if (role === 'assistant') {
      body.className = 'gpante-chat-reply';
      if (appendContent(body, text)) item.className += ' gpante-chat-message--rich';
    }
    else body.textContent = text;
    item.append(label, body);
    messages.append(item);
    if (scroll) scrollArea.scrollTop = scrollArea.scrollHeight;
    return item;
  }

  function welcome() {
    welcomeItem = addMessage('assistant', appearanceTexts.welcome);
    welcomeItem.className += ' gpante-chat-message--welcome';
  }

  function validPolicy(value) {
    return value?.version === 2 && value.archiveDays === 30 && [3, 7, 15, 30].includes(value.userDays)
      && typeof value.allowClear === 'boolean' && Number.isSafeInteger(value.revision) && value.revision > 0;
  }

  function applyPolicy(value) {
    if (!validPolicy(value)) throw new Error('سیاست گفتگو دریافت نشد. لطفاً صفحه را تازه کنید.');
    policy = value;
    if (fresh) fresh.hidden = !value.allowClear;
    if (!value.allowClear && confirmation) confirmation.hidden = true;
  }

  function scheduleHistoryCheck() {
    clearTimeout(historyTimer);
    if (panel.hidden || !policy) return;
    let delay = 60000;
    for (const item of [...messages.children]) {
      const deadline = Date.parse(item.dataset.expiresAt || '');
      if (!Number.isFinite(deadline)) continue;
      if (deadline <= Date.now()) item.remove();
      else delay = Math.min(delay, deadline - Date.now());
    }
    // Exact known deadlines are local; central policy changes are rechecked each minute while open.
    historyTimer = setTimeout(() => {
      for (const item of [...messages.children]) {
        if (Date.parse(item.dataset.expiresAt || '') <= Date.now()) item.remove();
      }
      if (!panel.hidden && !busy) loadHistory(true);
      else scheduleHistoryCheck();
    }, Math.max(1, delay));
  }

  async function api(action, text) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 160000);
    try {
      const body = { action, policyVersion: policy?.version || 0 };
      if (action === 'sendMessage') body.chatInput = text;
      const response = await fetch(root.dataset.endpoint, {
        method: 'POST', mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body), signal: controller.signal
      });
      const data = await response.json();
      if (!response.ok || !data || data.error) {
        throw new Error(typeof data?.output === 'string' ? data.output.slice(0, 500) : 'دستیار در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.');
      }
      applyPolicy(data.policy);
      if (action === 'sendMessage' && (typeof data.output !== 'string' || !data.output.trim() || data.output.length > 8000 || !Number.isFinite(Date.parse(data.expiresAt)))) throw new Error('پاسخ معتبری دریافت نشد. لطفاً دوباره تلاش کنید.');
      if (action === 'loadPreviousSession' && (!Array.isArray(data.messages) || data.messages.length > 100 || data.messages.some(message =>
        !message || !['user', 'assistant'].includes(message.role) || typeof message.content !== 'string' || message.content.length > 8000 || !Number.isFinite(Date.parse(message.expiresAt))))) throw new Error('تاریخچه در دسترس نیست. لطفاً دوباره تلاش کنید.');
      if (action === 'resetSession' && data.ok !== true) throw new Error('پاک‌کردن تاریخچه انجام نشد. لطفاً دوباره تلاش کنید.');
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('دریافت پاسخ طول کشید. می‌توانید کمی بعد دوباره تلاش کنید.');
      if (error instanceof TypeError || error instanceof SyntaxError) throw new Error('ارتباط با دستیار برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  function failure(error, action, text) {
    failed = { action, text, message: error.message };
    setStatus(error.message + (input.value.trim() ? ' متن نوشته‌شده در کادر محفوظ است؛ نیازی به نوشتن دوبارهٔ آن نیست.' : ''), true);
    retry.textContent = action === 'resetSession' ? 'تلاش دوباره برای پاک‌کردن' : 'تلاش دوباره';
    retry.hidden = false;
  }

  function loadHistory(quiet = false) {
    // Reuse the first request so a queued send keeps its signed session cookie.
    if (!historyRequest) historyRequest = readHistory(quiet).finally(() => { historyRequest = null; });
    return historyRequest;
  }

  async function readHistory(quiet) {
    const previousFailure = failed;
    clearTimeout(historyTimer);
    if (!quiet) messages.replaceChildren();
    retry.hidden = true;
    setBusy('history');
    setStatus('در حال خواندن گفت‌وگوی پیشین…');
    try {
      const data = await api('loadPreviousSession');
      messages.replaceChildren();
      if (data.messages.length) {
        data.messages.forEach(message => addMessage(message.role, message.content, false, message.expiresAt));
        scrollArea.scrollTop = scrollArea.scrollHeight;
      }
      else welcome();
      if (previousFailure && failed === previousFailure && previousFailure.action !== 'loadPreviousSession'
        && (previousFailure.action !== 'resetSession' || policy.allowClear)) {
        failure(new Error(previousFailure.message), previousFailure.action, previousFailure.text);
      } else {
        failed = null;
        setStatus('پرسشتان را بنویسید.');
      }
      return true;
    } catch (error) {
      messages.replaceChildren();
      policy = null;
      if (fresh) fresh.hidden = true;
      failure(error, 'loadPreviousSession');
      return false;
    } finally {
      setBusy(null);
      scheduleHistoryCheck();
      if (!panel.hidden && document.activeElement === panel) input.focus({ preventScroll: true });
    }
  }

  async function submitMessage() {
    const text = input.value.trim();
    if (pendingSend || (busy && busy !== 'history') || !text) return;
    if (text.length > 4000) {
      setStatus('لطفاً پرسش را با حداکثر ۴۰۰۰ نویسه بنویسید.', true);
      return;
    }
    if (busy === 'history' || !policy) {
      const hadFocus = panel.contains(document.activeElement);
      pendingSend = true;
      updateInput();
      const ready = loadHistory();
      setStatus('پیامتان آمادهٔ ارسال است؛ در حال برقراری ارتباط…');
      const loaded = await ready;
      pendingSend = false;
      updateInput();
      if (hadFocus && !panel.hidden && document.activeElement === document.body) input.focus({ preventScroll: true });
      if (!loaded) {
        failed = { ...failed, action: 'sendMessage', text };
        return;
      }
    }
    retry.hidden = true;
    if (confirmation) confirmation.hidden = true;
    const item = addMessage('user', text);
    input.value = '';
    setBusy('send');
    setStatus('در حال بررسی پرسش شما… ممکن است پاسخ کمی زمان ببرد.');
    try {
      const data = await api('sendMessage', text);
      item.dataset.expiresAt = data.expiresAt;
      addMessage('assistant', data.output, true, data.expiresAt);
      failed = null;
      setStatus('می‌توانید پرسش بعدی را بنویسید.');
    } catch (error) {
      item.remove();
      input.value = text;
      failure(error, 'sendMessage', text);
    } finally {
      setBusy(null);
      scheduleHistoryCheck();
      if (!panel.hidden && panel.contains(document.activeElement)) input.focus({ preventScroll: true });
    }
  }

  async function clearHistory() {
    if (busy || !policy?.allowClear) return;
    setBusy('reset');
    if (confirmation) confirmation.hidden = true;
    retry.hidden = true;
    setStatus('در حال پاک‌کردن تاریخچه…');
    try {
      await api('resetSession');
      messages.replaceChildren();
      welcome();
      input.value = '';
      failed = null;
      setStatus('تاریخچهٔ قابل مشاهدهٔ شما پاک شد. آرشیو مدیریتی تا پایان مهلت ۳۰روزه باقی می‌ماند.');
    } catch (error) {
      failure(error, 'resetSession');
    } finally {
      setBusy(null);
      scheduleHistoryCheck();
      if (!panel.hidden && panel.contains(document.activeElement)) input.focus({ preventScroll: true });
    }
  }

  const browserHistory = typeof window !== 'undefined' ? window.history : null;
  const storedToken = browserHistory?.state?.gpanteSupportChat;
  const historyToken = typeof storedToken === 'string' && storedToken.startsWith('gpante-')
    ? storedToken : 'gpante-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
  let historyClosing = false;
  let reopenRequested = false;

  function closePanel(restoreFocus = true, releaseHistory = true) {
    if (panel.hidden) return;
    panel.hidden = true;
    clearTimeout(historyTimer);
    if (confirmation) confirmation.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    if (restoreFocus) launcher.focus({ preventScroll: true });
    if (releaseHistory && browserHistory?.state?.gpanteSupportChat === historyToken) {
      historyClosing = true;
      browserHistory.back();
    }
  }

  function openPanel(pushHistory = true) {
    if (historyClosing) { reopenRequested = true; return; }
    if (!panel.hidden) return;
    if (pushHistory && browserHistory && browserHistory.state?.gpanteSupportChat !== historyToken) {
      try { browserHistory.pushState({ ...browserHistory.state, gpanteSupportChat: historyToken }, ''); }
      catch (_) { /* The panel remains usable when an embed disallows history writes. */ }
    }
    panel.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    panel.focus({ preventScroll: true });
    if (!busy) loadHistory();
    if (!input.disabled) input.focus({ preventScroll: true });
  }

  launcher.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
  find('[data-close]').addEventListener('click', () => closePanel());
  document.addEventListener('click', event => {
    if (!panel.hidden && !root.contains(event.target)) closePanel(false);
  });
  if (typeof window !== 'undefined') window.addEventListener('popstate', () => {
    historyClosing = false;
    if (browserHistory?.state?.gpanteSupportChat === historyToken) openPanel(false);
    else closePanel(true, false);
    if (reopenRequested) { reopenRequested = false; openPanel(); }
  });
  root.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !panel.hidden) {
      event.preventDefault();
      if (confirmation && !confirmation.hidden) {
        if (confirmation) confirmation.hidden = true;
        if (fresh) fresh.focus();
      } else closePanel();
    }
  });
  form.addEventListener('submit', event => { event.preventDefault(); submitMessage(); });
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      submitMessage();
    }
  });
  input.addEventListener('input', () => {
    updateInput();
    if (failed?.action === 'sendMessage') {
      failed = null;
      retry.hidden = true;
    }
  });
  if (fresh && confirmation && clear) fresh.addEventListener('click', () => { if (!policy?.allowClear) return; confirmation.hidden = false; clear.focus(); });
  const cancel = find('[data-cancel]');
  if (cancel && confirmation && fresh) cancel.addEventListener('click', () => { confirmation.hidden = true; fresh.focus(); });
  if (clear) clear.addEventListener('click', clearHistory);
  retry.addEventListener('click', () => {
    if (!failed || busy) return;
    if (failed.action === 'sendMessage') {
      input.value = failed.text;
      submitMessage();
    } else if (failed.action === 'resetSession') clearHistory();
    else loadHistory();
  });
  if (typeof window !== 'undefined') window.addEventListener('focus', () => { if (!panel.hidden && !busy) loadHistory(true); });
  updateInput();
  // Restore an open history entry on reload/forward rather than leaving a dead Back step.
  if (browserHistory?.state?.gpanteSupportChat === historyToken) openPanel(false);
}());
