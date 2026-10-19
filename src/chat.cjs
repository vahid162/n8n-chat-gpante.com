'use strict';

function validate(input) {
  const sessionId = typeof input.sessionId === 'string' ? input.sessionId : '';
  const chatInput = typeof input.chatInput === 'string' ? input.chatInput.trim() : '';
  if (!/^[A-Za-z0-9_-]{16,128}$/.test(sessionId)) {
    return { valid: false, output: 'نشست گفتگو معتبر نیست؛ لطفاً گفتگوی تازه‌ای باز کنید.' };
  }
  if (input.action && input.action !== 'sendMessage') {
    return { valid: false, output: 'این نوع درخواست در گفتگو پشتیبانی نمی‌شود.' };
  }
  if (!chatInput || chatInput.length > 4000) {
    return { valid: false, output: 'لطفاً پرسش خود را در یک پیام متنی با حداکثر ۴۰۰۰ نویسه بنویسید.' };
  }
  return { valid: true, sessionId, chatInput };
}

function budget(input, result) {
  if (result.allowed === true) return { ...input, allowed: true };
  const retry = Math.max(1, Math.min(86400, Number(result.retry_after) || 60));
  return {
    allowed: false,
    output: result.reason === 'session_limit'
      ? `تعداد پیام‌های این نشست به سقف فعلی رسیده است. لطفاً حدود ${retry} ثانیه بعد دوباره تلاش کنید.`
      : 'پاسخ‌گویی خودکار در حال حاضر در دسترس نیست؛ لطفاً کمی بعد دوباره تلاش کنید.'
  };
}

function safeReplyLink(address) {
  try {
    if (!/^https:\/\/gpante\.com\/(?:[^\s?#\\]*)$/.test(address)) return false;
    const pathname = decodeURIComponent(address.slice('https://gpante.com'.length));
    return !/^\/(wp-json|wp-content|wp-admin|download|wc-api)(\/|$)/i.test(pathname)
      && !/[\\\x00-\x20?#]/.test(pathname) && !/(?:^|\/)\.{1,2}(?:\/|$)/.test(pathname);
  } catch { return false; }
}

function finish(result) {
  if (result.error || typeof result.output !== 'string' || !result.output.trim()) {
    return 'در دریافت پاسخ مشکلی پیش آمد. لطفاً کمی بعد دوباره تلاش کنید. برای پیگیری می‌توانید از صفحهٔ تماس با ما در gpante.com استفاده کنید.';
  }
  return result.output
    .replace(/<think\b[^>]*>[\s\S]*?(?:<\/think>|$)/gi, '')
    .replace(/<[^>]*>/g, '')
    .replace(/!+\[/g, '[')
    // Only inline links are supported; prevent reference definitions from linking.
    .replace(/\]:/g, '] :')
    .replace(/\]\(([^)]*)\)/g, (match, destination) => {
      const address = destination.trim().split(/\s+/)[0];
      return safeReplyLink(address) ? `](${address})` : ']';
    })
    .replace(/(?:https?:)?\/\/[^\s<>()[\]]+/gi, address => safeReplyLink(address) ? address : '')
    .trim().slice(0, 8000) || 'لطفاً پرسش خود را روشن‌تر بنویسید.';
}

module.exports = { validate, budget, finish };
