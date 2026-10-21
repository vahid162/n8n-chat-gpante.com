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

function failure(code = 'unavailable', status = 503, retryAfter = 60) {
  return { done: true, status, retryAfter, response: { error: { code }, output:
    status === 429 ? 'تعداد درخواست‌ها به سقف مجاز رسیده است. لطفاً کمی بعد دوباره تلاش کنید.'
      : 'دستیار در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.' } };
}

function validateTrusted(input) {
  if (!input || typeof input !== 'object' || !/^[a-f0-9]{64}$/.test(input.sessionId || '')
    || !/^[a-f0-9]{64}$/.test(input.clientId || '')
    || !['sendMessage', 'loadPreviousSession', 'resetSession'].includes(input.action)) {
    return { ...failure('invalid_input', 400), valid: false };
  }
  const chatInput = typeof input.chatInput === 'string' ? input.chatInput.trim() : '';
  if (input.action === 'sendMessage' && (!chatInput || chatInput.length > 4000)) {
    return { ...failure('invalid_input', 400), valid: false };
  }
  return { valid: true, action: input.action, sessionId: input.sessionId, clientId: input.clientId, chatInput, policyVersion: input.policyVersion === 2 ? 2 : 0 };
}

function admitted(input, result) {
  if (result?.allowed !== true) return failure(result?.reason || 'unavailable',
    ['rate_limit', 'session_busy', 'capacity'].includes(result?.reason) ? 429 : ['client_upgrade','clear_disabled'].includes(result?.reason) ? 403 : 503, result?.retry_after || 60);
  if (input.action === 'resetSession') return { done: true, status: 200, response: { ok: result.ok === true, policy: result.policy } };
  if (!Array.isArray(result.messages)) return failure();
  const history = result.messages.filter(m => m && ['user', 'assistant'].includes(m.role)
    && typeof m.content === 'string' && m.content.length <= 8000)
    .map(m => ({ role: m.role, content: m.role === 'assistant' ? finish({ output: m.content }) : m.content }));
  if (input.action === 'loadPreviousSession') return { done: true, status: 200, response: { messages: history.map((m, i) => ({ ...m, expiresAt: result.messages[i]?.expiresAt })), policy: result.policy } };
  if (!/^[a-f0-9]{32}$/.test(result.requestId || '')) return failure();
  // Keep recent complete turns within the model's input bound; stored history is unchanged.
  while (JSON.stringify(history).length > 24000) history.splice(0, 2);
  return { ...input, policy: result.policy, expiresAt: result.expiresAt, requestId: result.requestId, done: false, callIndex: 0, toolCount: 0,
    messages: [...history, { role: 'user', content: input.chatInput }] };
}

const TOOLS = [
  { name: 'Product_Search', description: 'Search all eligible public products with AND constraints. Sort newest by publication, best_selling by recorded all-time unit sales (positive only), or price_asc. Explicit newest/best_selling can browse without search. categoryId must come from Category_Search or verified tool facts. Pages are not the full catalog.',
    properties: { search: { type: 'string' }, categoryId: { type: 'integer', minimum: 1 }, format: { type: 'string' }, thickness: { type: 'string' }, material: { type: 'string' }, page: { type: 'integer', minimum: 1 }, sort: { type: 'string', enum: ['relevance', 'price_asc', 'newest', 'best_selling'] } } },
  { name: 'Product_Details', description: 'Read current eligible public product facts and prices by productId. No private files or customers.',
    properties: { productId: { type: 'integer', minimum: 1 } }, required: ['productId'] },
  { name: 'Knowledge_Search', description: 'Search actual public articles by meaningful topic keywords, with title relevance before recency. Use this for software topics such as Corel/کورل, tutorials and articles, even when no product exists. source guides reads approved rules/contact only. categoryId applies only to articles. Snippets are not full articles.',
    properties: { source: { type: 'string', enum: ['articles', 'guides'] }, search: { type: 'string' }, categoryId: { type: 'integer', minimum: 1 }, page: { type: 'integer', minimum: 1 } } },
  { name: 'Category_Search', description: 'Discover real public product or article categories with eligible content, including ancestors. Returns IDs and navigation URLs, five per page. source products or articles; parentId 0 lists roots, another ID lists immediate children; omit parentId to search all levels.',
    properties: { source: { type: 'string', enum: ['products', 'articles'] }, search: { type: 'string' }, parentId: { type: 'integer', minimum: 0 }, page: { type: 'integer', minimum: 1 } } }
].map(t => ({ type: 'function', function: { name: t.name, description: t.description,
  parameters: { type: 'object', properties: t.properties, required: t.required || [], additionalProperties: false } } }));

function modelRequest(state, system) {
  if (state.done || state.callIndex >= 4) return { ...state, ...failure('iteration_limit') };
  if (state.pendingTool) return { ...state, ...failure('tool_sequence') };
  const body = { model: 'deepseek-flash', thinking: { type: 'disabled' }, stream: false,
    max_tokens: 1536, temperature: 0.2,
    messages: [{ role: 'system', content: system }, ...state.messages], tools: TOOLS,
    tool_choice: state.callIndex === 3 || state.toolCount >= 3 ? 'none' : 'auto' };
  if (JSON.stringify(body).length > 96000) return { ...state, ...failure('context_limit') };
  return { ...state, callIndex: state.callIndex + 1, body };
}

function reserved(state, result) {
  const next = { ...state }; delete next.body;
  return result?.allowed === true ? state : { ...next, ...failure(result?.reason || 'unavailable') };
}

function modelResult(state, response) {
  const next = { ...state }; delete next.body;
  let body = response?.body;
  try { if (typeof body === 'string') body = JSON.parse(body); } catch { body = null; }
  const usage = body?.usage;
  const integers = usage && [usage.prompt_tokens, usage.completion_tokens, usage.total_tokens].every(Number.isSafeInteger);
  next.usage = integers && usage.prompt_tokens >= 0 && usage.prompt_tokens <= 1048576
    && usage.completion_tokens >= 0 && usage.completion_tokens <= 1536
    && usage.total_tokens === usage.prompt_tokens + usage.completion_tokens
    ? { prompt: usage.prompt_tokens, completion: usage.completion_tokens, total: usage.total_tokens }
    : { prompt: null, completion: null, total: null };
  const choice = body?.choices?.[0];
  // Persist only fixed reason codes; provider text and payloads never become diagnostics.
  const rejected = code => ({ ...next, ...failure(code) });
  if (response?.statusCode !== 200) return rejected('model_http_error');
  if (!choice?.message) return rejected('model_message_missing');
  if (next.usage.prompt === null) return rejected('model_usage_invalid');
  const message = choice.message;
  if (choice.finish_reason === 'stop' && typeof message.content === 'string' && message.content.trim() && !message.tool_calls?.length) {
    const output = finish({ output: message.content });
    return { ...next, done: true, success: true, output, status: 200, response: { output, policy: state.policy, expiresAt: state.expiresAt } };
  }
  const calls = message.tool_calls;
  if (choice.finish_reason !== 'tool_calls') {
    const reasons = { length: 'model_finish_length', content_filter: 'model_content_filter',
      insufficient_system_resource: 'model_provider_resource', aborted: 'model_provider_aborted' };
    return rejected(choice.finish_reason === 'stop'
      ? calls?.length ? 'model_finish_mismatch' : 'model_empty_content'
      : typeof choice.finish_reason === 'string' && Object.hasOwn(reasons, choice.finish_reason) ? reasons[choice.finish_reason] : 'model_finish_unexpected');
  }
  if (!Array.isArray(calls) || !calls.length) return rejected('model_tool_calls_missing');
  if (state.callIndex >= 4 || calls.length > 3 || state.toolCount + calls.length > 3) return rejected('model_tool_limit');
  if (calls.some(c => !c || typeof c.id !== 'string' || !/^[\w-]{1,128}$/.test(c.id))) return rejected('model_tool_invalid_id');
  if (new Set(calls.map(c => c.id)).size !== calls.length) return rejected('model_tool_duplicate_id');
  if (calls.some(c => c.type !== 'function')) return rejected('model_tool_invalid_type');
  if (calls.some(c => !TOOLS.some(t => t.function.name === c.function?.name))) return rejected('model_tool_unknown_name');
  if (calls.some(c => typeof c.function.arguments !== 'string' || c.function.arguments.length > 6000)) return rejected('model_tool_invalid_arguments');
  return { ...next, toolCount: state.toolCount + calls.length, pendingTool: calls[0], pendingTools: calls.slice(1),
    messages: [...state.messages, { role: 'assistant', content: typeof message.content === 'string' ? message.content.slice(0, 8000) : null, tool_calls: calls }] };
}

function settled(state, result) {
  if (result?.confirmed !== true) return state.done && state.response?.error
    ? { ...state, success: false } : { ...state, ...failure('accounting_unavailable'), success: false };
  return state;
}

function toolResult(state, facts) {
  const next = { ...state }; delete next.pendingTool; delete next.pendingTools; delete next.usage;
  const remaining = state.pendingTools || [];
  if (remaining.length) { next.pendingTool = remaining[0]; next.pendingTools = remaining.slice(1); }
  next.toolsPending = remaining.length > 0;
  return { ...next, messages: [...state.messages, { role: 'tool', tool_call_id: state.pendingTool.id, content: JSON.stringify(facts) }] };
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

module.exports = { validate, budget, finish, failure, validateTrusted, admitted, modelRequest, reserved, modelResult, settled, toolResult };
