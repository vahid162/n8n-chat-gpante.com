(function () {
  'use strict';

  function safeLink(address) {
    if (address === 'https://t.me/gpante_designer' || typeof address === 'string' && !/\s/.test(address) && /^https:\/\/gpante\.com\/faq-ai\/#faq-[a-z0-9][a-z0-9-]{0,79}$/.test(address)) return true;
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

  function safeThumbnail(address) {
    if (typeof address !== 'string' || address.length > 2048
      || !/^https:\/\/gpante\.com\/wp-content\/uploads\/[^\s?#\\]+\.(?:jpe?g|png|webp|avif|gif)$/iu.test(address)) return false;
    try {
      const path = decodeURIComponent(address.slice('https://gpante.com'.length));
      return !/[\\\x00-\x20\x7f%?#]/u.test(path) && !path.includes('//') && !/(?:^|\/)\.{1,2}(?:\/|$)/u.test(path);
    } catch (_) { return false; }
  }

  function navigationItems(text) {
    const pattern = /\[[^\]\n]{1,300}\]\((https:\/\/[^\s)]+)\)|(https:\/\/[^\s<>()\[\]]+)/gu;
    const items = [...text.matchAll(pattern)];
    return items.length && !text.replace(pattern, '').trim() && items.every(item => safeLink(item[1] || item[2]))
      ? items.map(item => item[0]) : null;
  }

  function replySections(content) {
    const lines = content.replace(/\r\n?/g, '\n').split('\n');
    const sections = [];
    let blocks = [], buffer = [], grouped = false;
    const finishSection = () => { if (blocks.length) sections.push(blocks); blocks = []; grouped = false; };
    const flush = () => {
      const text = buffer.join('\n').trim();
      buffer = [];
      if (!text) return;
      if (!grouped) finishSection();
      const navigation = navigationItems(text);
      if (navigation) {
        if (navigation.length > 1) finishSection();
        for (const line of navigation) { blocks.push({type:'text',text:line}); finishSection(); }
      } else blocks.push({type:'text',text});
    };
    for (let i = 0; i < lines.length;) {
      const heading = lines[i].trim().match(/^(?:#{1,3}\s+(.+)|(?:[0-9۰-۹]+[.)]\s*)?\*\*([^*\n]{1,300})\*\*)$/u);
      const table = readTable(lines, i);
      if (heading) {
        flush();finishSection();
        blocks.push({type:'heading',text:heading[1] || heading[2]});grouped = true;i++;
      } else if (table) {
        flush();
        if (!grouped) finishSection();
        blocks.push({type:'facts',data:table});grouped = true;i = table.next;
      } else {
        if (lines[i].trim()) buffer.push(lines[i]); else flush();
        i++;
      }
    }
    flush();finishSection();
    return sections;
  }

  function appendFacts(container, data) {
    const list = document.createElement('ul');
    list.className = 'gpante-chat-facts';
    for (const cells of data.rows) {
      const item = document.createElement('li');
      const label = document.createElement('strong');
      label.textContent = (cells.length > 2 ? data.header[0] + ': ' : '') + cells[0].replace(/\*\*/g, '') + ': ';
      item.append(label);
      appendInline(item, cells.length === 2 ? cells[1] : cells.slice(1).map((value, i) => data.header[i + 1] + ': ' + value).join(' / '));
      list.append(item);
    }
    container.append(list);
  }

  function appendParagraph(container, text) {
    let buffer = [], list = null;
    const flush = () => {
      if (!buffer.length) return;
      const paragraph = document.createElement('p');
      appendInline(paragraph, buffer.join('\n'));container.append(paragraph);buffer = [];
    };
    for (const line of text.split('\n')) {
      const bullet = line.match(/^\s*(?:[-*•]|[0-9۰-۹]+[.)])\s+(.+)$/u);
      if (bullet) {
        flush();
        if (!list) { list = document.createElement('ul');list.className = 'gpante-chat-facts';container.append(list); }
        const item = document.createElement('li');appendInline(item, bullet[1]);list.append(item);
      } else { list = null;buffer.push(line); }
    }
    flush();
  }

  function appendContent(container, content, products = []) {
    const previews = new Map();
    for (const product of Array.isArray(products) ? products.slice(0,30) : []) {
      if (safeLink(product?.url) && product.url.startsWith('https://gpante.com/product/')
        && safeThumbnail(product.thumbnail) && typeof product.title === 'string' && product.title.trim() && product.title.length <= 300) previews.set(product.url, product);
    }
    let rich = false;
    for (const blocks of replySections(content)) {
      const text = blocks.map(block => block.text || block.data.rows.flat().join(' ')).join('\n');
      const links = [...new Set([...text.matchAll(/https:\/\/[^\s<>()\[\]]+/gu)].map(match => match[0].replace(/[.,،؛]+$/u, '')).filter(safeLink))];
      const product = links.length === 1 ? previews.get(links[0]) : null;
      const heading = blocks.find(block => block.type === 'heading');
      const section = document.createElement('div');
      section.className = 'gpante-chat-segment';
      if (product || heading || blocks.some(block => block.type === 'facts')) { section.className += ' gpante-chat-segment--card';rich = true; }
      let subtitle;
      if (product || heading) {
        const header = document.createElement('div');header.className = 'gpante-chat-card-heading';
        if (product) {
          const image = document.createElement('img');
          image.className = 'gpante-chat-thumbnail';image.src = product.thumbnail;image.alt = '';
          image.width = image.height = 48;image.loading = 'lazy';image.decoding = 'async';image.referrerPolicy = 'no-referrer';
          image.addEventListener('error', () => { image.hidden = true; });header.append(image);
        }
        const copy = document.createElement('div');copy.className = 'gpante-chat-card-copy';
        const title = document.createElement('strong');title.className = 'gpante-chat-card-title';title.textContent = product?.title || heading.text;copy.append(title);
        subtitle = blocks.find(block => block.type === 'text' && !/https:\/\/|^\s*(?:[-*•]|[0-9۰-۹]+[.)])\s/u.test(block.text));
        if (subtitle) appendParagraph(copy, subtitle.text);
        header.append(copy);section.append(header);
      }
      for (const block of blocks) {
        if (block === subtitle || block.type === 'heading') continue;
        if (block.type === 'facts') appendFacts(section, block.data); else appendParagraph(section, block.text);
      }
      container.append(section);
    }
    return rich;
  }

  // The same rendering functions are exercised by the dependency-free Node checks.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { safeLink, safeThumbnail, appendContent, shortcutUrl };
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
  const statusLabel = find('[data-status-label]');
  const statusText = find('[data-status-text]');
  const notice = find('[data-notice]');
  const activity = find('[data-activity]');
  const activityText = find('[data-activity-text]');
  const reconnect = find('[data-reconnect]');
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
    retry.disabled = reconnect.disabled = Boolean(busy || pendingSend);
  }

  function setConnection(state) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) state = 'offline';
    const labels = {connecting:'Connecting',ready:'Online',offline:'Disconnected'};
    if (status.dataset.state !== state || statusLabel.textContent !== labels[state]) {
      status.dataset.state = state;
      statusLabel.textContent = labels[state];
      status.title = {connecting:'در حال بررسی ارتباط با سرویس چت',ready:'آخرین ارتباط با سرویس چت موفق بود',offline:'ارتباط با سرویس چت تأیید نشده است'}[state];
    }
  }

  function showNotice(text = '', error = false) {
    if (!text && typeof navigator !== 'undefined' && navigator.onLine === false) { offlineNotice(); return; }
    if (statusText.textContent !== text) statusText.textContent = text;
    notice.hidden = !text;
    root.dataset.error = error ? 'true' : 'false';
    if (!text) retry.hidden = reconnect.hidden = true;
  }

  function setActivity(text = '') {
    if (activityText.textContent !== text) activityText.textContent = text;
    activity.hidden = !text;
    if (text) scrollArea.scrollTop = scrollArea.scrollHeight;
  }

  function setBusy(action) {
    if (action) requestHadFocus = panel.contains(document.activeElement);
    busy = action;
    if (!action) setActivity();
    messages.setAttribute('aria-busy', String(Boolean(action)));
    updateInput();
    // Chrome moves focus to body when its active control is disabled or hidden.
    if (!action && requestHadFocus && !panel.hidden && document.activeElement === document.body) input.focus({ preventScroll: true });
  }

  function addMessage(role, text, scroll = true, expiresAt, products) {
    const item = document.createElement('div');
    item.dataset.role = role;
    if (expiresAt) item.dataset.expiresAt = expiresAt;
    item.className = 'gpante-chat-message gpante-chat-message--' + role;
    const label = document.createElement('small');
    label.textContent = role === 'user' ? 'شما' : appearanceTexts.title;
    const body = document.createElement('div');
    if (role === 'assistant') {
      body.className = 'gpante-chat-reply';
      if (appendContent(body, text, products)) item.className += ' gpante-chat-message--rich';
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
    if (panel.hidden) return;
    let delay = policy ? 60000 : Infinity;
    for (const item of [...messages.children]) {
      const deadline = Date.parse(item.dataset.expiresAt || '');
      if (!Number.isFinite(deadline)) continue;
      if (deadline <= Date.now()) item.remove();
      else delay = Math.min(delay, deadline - Date.now());
    }
    if (!Number.isFinite(delay)) return;
    // Known expiry still applies when a failed refresh invalidates the server policy.
    historyTimer = setTimeout(() => {
      for (const item of [...messages.children]) {
        if (Date.parse(item.dataset.expiresAt || '') <= Date.now()) item.remove();
      }
      if (!panel.hidden && !busy && policy) loadHistory(true);
      else scheduleHistoryCheck();
    }, Math.max(1, Math.min(delay, 2147483647)));
  }

  function apiError(message, code, uncertain = false) {
    return Object.assign(new Error(message), {code, uncertain});
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
        const reasons = ['rate_limit','session_busy','capacity','request_limit','monthly_budget','pricing_review'];
        const code = reasons.includes(data?.error?.code) ? data.error.code
          : response.status === 429 ? 'request_limit' : response.status >= 400 && response.status < 500 ? 'request_rejected' : 'backend_unavailable';
        throw apiError(typeof data?.output === 'string' ? data.output.slice(0, 500) : 'دستیار در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.',
          code, action === 'sendMessage' && code === 'backend_unavailable');
      }
      if (!validPolicy(data.policy)) throw apiError('سیاست گفتگو دریافت نشد. لطفاً صفحه را تازه کنید.', 'invalid_response', action === 'sendMessage');
      applyPolicy(data.policy);
      if (action === 'sendMessage' && (typeof data.output !== 'string' || !data.output.trim() || data.output.length > 8000 || !Number.isFinite(Date.parse(data.expiresAt)))) throw apiError('پاسخ معتبری دریافت نشد.', 'invalid_response', true);
      if (action === 'loadPreviousSession' && (!Array.isArray(data.messages) || data.messages.length > 100 || data.messages.some(message =>
        !message || !['user', 'assistant'].includes(message.role) || typeof message.content !== 'string' || message.content.length > 8000 || !Number.isFinite(Date.parse(message.expiresAt))))) throw apiError('تاریخچه در دسترس نیست. لطفاً دوباره تلاش کنید.', 'invalid_response');
      if (action === 'resetSession' && data.ok !== true) throw apiError('پاک‌کردن تاریخچه انجام نشد. لطفاً دوباره تلاش کنید.', 'operation_failed');
      setConnection('ready');
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw apiError('زمان دریافت پاسخ به پایان رسید.', 'timeout', action === 'sendMessage');
      if (['TypeError','SyntaxError'].includes(error.name)) throw apiError('پاسخی معتبر از سرویس چت دریافت نشد.', 'network', action === 'sendMessage');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  function renderFailure() {
    if (!failed) return;
    let text = failed.error.uncertain && failed.checked ? 'نتیجهٔ ارسال قبلی تأیید نشد.' : failed.error.message;
    if (failed.error.uncertain) text += failed.checked
      ? ' تاریخچه به‌روز شد؛ اگر پاسخ در پیام‌ها نیست، می‌توانید دوباره بفرستید. ارسال دوباره ممکن است درخواست تازه‌ای ایجاد کند.'
      : ' دریافت پاسخ تأیید نشد؛ ابتدا اتصال و تاریخچه را بررسی کنید. ممکن است پاسخ روی سرور ثبت شده باشد.';
    if (input.value.trim()) text += ' متن نوشته‌شده در کادر محفوظ است؛ نیازی به نوشتن دوبارهٔ آن نیست.';
    showNotice(text, true);
    reconnect.hidden = status.dataset.state === 'ready' && Boolean(policy) && (!failed.error.uncertain || failed.checked) && failed.action !== 'loadPreviousSession';
    retry.hidden = !reconnect.hidden || failed.action === 'loadPreviousSession'
      || ['monthly_budget','pricing_review'].includes(failed.error.code) || (failed.action === 'resetSession' && !policy?.allowClear);
    retry.textContent = failed.action === 'resetSession' ? 'تلاش دوباره برای پاک‌کردن' : 'تلاش دوباره';
  }

  function failure(error, action, text) {
    failed = {action, text, error, checked:false};
    if (['network','timeout','backend_unavailable','invalid_response'].includes(error.code)
      || (action === 'loadPreviousSession' && status.dataset.state !== 'ready')) setConnection('offline');
    renderFailure();
  }

  function loadHistory(quiet = false) {
    // Reuse the first request so a queued send keeps its signed session cookie.
    if (!historyRequest) historyRequest = readHistory(quiet).finally(() => { historyRequest = null; });
    return historyRequest;
  }

  async function readHistory(quiet) {
    const previousFailure = failed;
    setBusy('history');
    scheduleHistoryCheck();
    if (!quiet || status.dataset.state !== 'ready') setConnection('connecting');
    try {
      const data = await api('loadPreviousSession');
      messages.replaceChildren();
      if (data.messages.length) {
        data.messages.forEach(message => addMessage(message.role, message.content, false, message.expiresAt, message.products));
        scrollArea.scrollTop = scrollArea.scrollHeight;
      }
      else welcome();
      if (previousFailure && failed === previousFailure && previousFailure.action !== 'loadPreviousSession'
        && (previousFailure.action !== 'resetSession' || policy.allowClear)) {
        previousFailure.checked = true;
        renderFailure();
      } else {
        failed = null;
        showNotice();
      }
      return true;
    } catch (error) {
      // A failed refresh must not erase already displayed messages or the unresolved action.
      policy = null;
      if (fresh) fresh.hidden = true;
      if (previousFailure && failed === previousFailure && previousFailure.action !== 'loadPreviousSession') {
        setConnection('offline');
        renderFailure();
      } else failure(error, 'loadPreviousSession');
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
      showNotice('لطفاً پرسش را با حداکثر ۴۰۰۰ نویسه بنویسید.', true);
      return;
    }
    if (failed?.error.uncertain && !failed.checked) {
      loadHistory(true);
      return;
    }
    if (busy === 'history' || !policy || status.dataset.state !== 'ready') {
      const hadFocus = panel.contains(document.activeElement);
      pendingSend = true;
      updateInput();
      const ready = loadHistory();
      setActivity('پیامتان آمادهٔ ارسال است؛ پس از تأیید ارتباط، یک‌بار ارسال می‌شود.');
      const loaded = await ready;
      pendingSend = false;
      updateInput();
      if (hadFocus && !panel.hidden && document.activeElement === document.body) input.focus({ preventScroll: true });
      if (!loaded) {
        failed = { ...failed, action: 'sendMessage', text };
        renderFailure();
        return;
      }
    }
    failed = null;
    showNotice();
    if (confirmation) confirmation.hidden = true;
    const item = addMessage('user', text);
    input.value = '';
    setBusy('send');
    setActivity('در حال آماده‌کردن پاسخ…');
    try {
      const data = await api('sendMessage', text);
      item.dataset.expiresAt = data.expiresAt;
      addMessage('assistant', data.output, true, data.expiresAt, data.products);
      failed = null;
      showNotice();
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
    showNotice();
    setActivity('در حال پاک‌کردن گفتگو…');
    try {
      await api('resetSession');
      messages.replaceChildren();
      welcome();
      input.value = '';
      failed = null;
      showNotice('تاریخچهٔ قابل مشاهدهٔ شما پاک شد. آرشیو مدیریتی تا پایان مهلت ۳۰روزه باقی می‌ماند.');
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
    if (!busy) loadHistory(true);
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
    if (failed?.action === 'sendMessage' && !failed.error.uncertain) {
      failed = null;
      showNotice();
      if (status.dataset.state === 'offline') offlineNotice();
    }
  });
  if (fresh && confirmation && clear) fresh.addEventListener('click', () => { if (!policy?.allowClear) return; confirmation.hidden = false; clear.focus(); });
  const cancel = find('[data-cancel]');
  if (cancel && confirmation && fresh) cancel.addEventListener('click', () => { confirmation.hidden = true; fresh.focus(); });
  if (clear) clear.addEventListener('click', clearHistory);
  retry.addEventListener('click', () => {
    if (!failed || busy) return;
    if (failed.action === 'sendMessage') {
      if (!input.value.trim()) input.value = failed.text;
      submitMessage();
    } else if (failed.action === 'resetSession') clearHistory();
    else loadHistory();
  });
  if (typeof window !== 'undefined') window.addEventListener('focus', () => { if (!panel.hidden && !busy) loadHistory(true); });
  reconnect.addEventListener('click', () => { if (!busy && !pendingSend) loadHistory(true); });
  function offlineNotice() {
    setConnection('offline');
    showNotice(busy === 'send' ? 'ارتباط قطع شد؛ دریافت نتیجهٔ پیام هنوز تأیید نشده است. ابتدا منتظر نتیجهٔ درخواست بمانید.'
      : 'ارتباط برقرار نیست. اتصال اینترنت را بررسی کنید؛ متن نوشته‌شده در کادر حفظ می‌شود.', true);
    retry.hidden = true;
    reconnect.hidden = false;
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('offline', offlineNotice);
    window.addEventListener('online', () => {
      if (panel.hidden) return;
      if (!busy) loadHistory(true);
      else setConnection('connecting');
    });
  }
  setConnection('connecting');
  setActivity();
  showNotice();
  updateInput();
  // Restore an open history entry on reload/forward rather than leaving a dead Back step.
  if (browserHistory?.state?.gpanteSupportChat === historyToken) openPanel(false);
}());
