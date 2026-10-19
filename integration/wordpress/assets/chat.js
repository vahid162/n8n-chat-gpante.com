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

  function appendContent(container, content) {
    const pattern = /\[([^\]\n]{1,300})\]\((https:\/\/[^\s)]+)\)|https:\/\/[^\s<>()\[\]]+/gu;
    let end = 0;
    for (const match of content.matchAll(pattern)) {
      container.append(document.createTextNode(content.slice(end, match.index)));
      const address = match[2] || match[0].replace(/[.,،؛]+$/u, '');
      if (safeLink(address)) {
        const link = document.createElement('a');
        link.href = address;
        link.textContent = match[1] || address;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        container.append(link);
        if (!match[2]) container.append(document.createTextNode(match[0].slice(address.length)));
      } else {
        container.append(document.createTextNode(match[0]));
      }
      end = match.index + match[0].length;
    }
    container.append(document.createTextNode(content.slice(end)));
  }

  // The same rendering functions are exercised by the dependency-free Node checks.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { safeLink, appendContent };
    return;
  }

  const root = document.getElementById('gpante-support');
  if (!root) return;
  const find = selector => root.querySelector(selector);
  const panel = find('#gpante-support-panel');
  const launcher = find('[data-launcher]');
  const messages = find('[data-messages]');
  const status = find('[data-status]');
  const input = find('[data-input]');
  const form = find('[data-form]');
  const send = find('[data-send]');
  const fresh = find('[data-new]');
  const confirmation = find('[data-confirm]');
  const clear = find('[data-clear]');
  const retry = find('[data-retry]');
  let busy = false;
  let failed = null;

  function updateInput() {
    find('[data-count]').textContent = input.value.length.toLocaleString('fa-IR') + ' / ۴۰۰۰';
    send.disabled = busy || !input.value.trim();
  }

  function setStatus(text, error) {
    status.textContent = text;
    root.dataset.error = error ? 'true' : 'false';
  }

  function setBusy(value) {
    busy = value;
    input.disabled = value;
    fresh.disabled = value;
    clear.disabled = value;
    retry.disabled = value;
    messages.setAttribute('aria-busy', String(value));
    updateInput();
  }

  function addMessage(role, text, scroll = true) {
    const item = document.createElement('div');
    item.className = 'gpante-chat-message gpante-chat-message--' + role;
    const label = document.createElement('small');
    label.textContent = role === 'user' ? 'شما' : 'دستیار پانته';
    const body = document.createElement('div');
    if (role === 'assistant') appendContent(body, text);
    else body.textContent = text;
    item.append(label, body);
    messages.append(item);
    if (scroll) messages.scrollTop = messages.scrollHeight;
    return item;
  }

  function welcome() {
    addMessage('assistant', 'سلام! دنبال چه طرح یا فایلی هستید؟ موضوع، متریال، ضخامت و فرمت دلخواهتان را بنویسید تا دقیق‌تر راهنمایی‌تان کنم.');
  }

  async function api(action, text) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 160000);
    try {
      const body = { action };
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
      if (action === 'sendMessage' && (typeof data.output !== 'string' || !data.output.trim() || data.output.length > 8000)) throw new Error('پاسخ معتبری دریافت نشد. لطفاً دوباره تلاش کنید.');
      if (action === 'loadPreviousSession' && (!Array.isArray(data.messages) || data.messages.length > 100 || data.messages.some(message =>
        !message || !['user', 'assistant'].includes(message.role) || typeof message.content !== 'string' || message.content.length > 8000))) throw new Error('تاریخچه در دسترس نیست. لطفاً دوباره تلاش کنید.');
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
    failed = { action, text };
    setStatus(error.message, true);
    retry.textContent = action === 'resetSession' ? 'تلاش دوباره برای پاک‌کردن' : 'تلاش دوباره';
    retry.hidden = false;
  }

  async function loadHistory() {
    retry.hidden = true;
    setBusy(true);
    setStatus('در حال خواندن گفت‌وگوی پیشین…');
    try {
      const data = await api('loadPreviousSession');
      messages.replaceChildren();
      if (data.messages.length) {
        data.messages.forEach(message => addMessage(message.role, message.content, false));
        messages.scrollTop = messages.scrollHeight;
      }
      else welcome();
      failed = null;
      setStatus('پرسشتان را بنویسید.');
    } catch (error) {
      failure(error, 'loadPreviousSession');
    } finally {
      setBusy(false);
      if (!panel.hidden && document.activeElement === panel) input.focus({ preventScroll: true });
    }
  }

  async function submitMessage() {
    const text = input.value.trim();
    if (busy || !text) return;
    if (text.length > 4000) {
      setStatus('لطفاً پرسش را با حداکثر ۴۰۰۰ نویسه بنویسید.', true);
      return;
    }
    retry.hidden = true;
    confirmation.hidden = true;
    const item = addMessage('user', text);
    input.value = '';
    setBusy(true);
    setStatus('در حال بررسی پرسش شما… ممکن است پاسخ کمی زمان ببرد.');
    try {
      const data = await api('sendMessage', text);
      addMessage('assistant', data.output);
      failed = null;
      setStatus('می‌توانید پرسش بعدی را بنویسید.');
    } catch (error) {
      item.remove();
      input.value = text;
      failure(error, 'sendMessage', text);
    } finally {
      setBusy(false);
      if (!panel.hidden && panel.contains(document.activeElement)) input.focus({ preventScroll: true });
    }
  }

  async function clearHistory() {
    if (busy) return;
    confirmation.hidden = true;
    retry.hidden = true;
    setBusy(true);
    setStatus('در حال پاک‌کردن تاریخچه…');
    try {
      await api('resetSession');
      messages.replaceChildren();
      welcome();
      input.value = '';
      failed = null;
      setStatus('تاریخچهٔ این گفت‌وگو پاک شد.');
    } catch (error) {
      failure(error, 'resetSession');
    } finally {
      setBusy(false);
      if (!panel.hidden && panel.contains(document.activeElement)) input.focus({ preventScroll: true });
    }
  }

  function closePanel() {
    panel.hidden = true;
    confirmation.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus({ preventScroll: true });
  }

  launcher.addEventListener('click', () => {
    if (!panel.hidden) return closePanel();
    panel.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    panel.focus({ preventScroll: true });
    if (!busy) loadHistory();
  });
  find('[data-close]').addEventListener('click', closePanel);
  root.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !panel.hidden) {
      event.preventDefault();
      if (!confirmation.hidden) {
        confirmation.hidden = true;
        fresh.focus();
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
  fresh.addEventListener('click', () => { confirmation.hidden = false; clear.focus(); });
  find('[data-cancel]').addEventListener('click', () => { confirmation.hidden = true; fresh.focus(); });
  clear.addEventListener('click', clearHistory);
  retry.addEventListener('click', () => {
    if (!failed || busy) return;
    if (failed.action === 'sendMessage') {
      input.value = failed.text;
      submitMessage();
    } else if (failed.action === 'resetSession') clearHistory();
    else loadHistory();
  });
  updateInput();
}());
