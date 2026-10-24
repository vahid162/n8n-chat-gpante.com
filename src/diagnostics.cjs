'use strict';

// Operational metadata only; source bodies, URLs and transcripts never enter this store.
function diagnosticQuery(value) {
  return typeof value === 'string' ? value.toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[۰-۹٠-٩]/g, c => String('۰۱۲۳۴۵۶۷۸۹'.includes(c) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(c) : '٠١٢٣٤٥٦٧٨٩'.indexOf(c)))
    .replace(/https?:\/\/\S+|\S+@\S+|(?:sk|pk|api|token|bearer)_[a-z0-9_-]{8,}|[a-z0-9_-]{24,}|\+?[0-9][0-9 ()-]{6,}[0-9]/g, '[redacted]')
    .replace(/[^\p{L}\p{N}\s.[\]_-]/gu, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) : '';
}

function toolDiagnostic(state, facts) {
  const tool = state.pendingTool?.function?.name;
  if (!['Product_Search', 'Product_Details', 'Knowledge_Search', 'Knowledge_Read', 'Category_Search'].includes(tool)) return null;
  let args; try { args = JSON.parse(state.pendingTool.function.arguments); } catch { args = {}; }
  const source = facts.source || args.source || (tool.startsWith('Product_') ? 'products' : tool === 'Knowledge_Read' ? 'site' : 'categories');
  const result = { event: 'source', tool, source: ['products', 'articles', 'site', 'faq', 'guides', 'categories'].includes(source) ? source : 'unknown',
    status: /^[a-z_]{1,40}$/.test(facts.status || '') ? facts.status : 'unknown', query: diagnosticQuery(args.search),
    filters: {}, results: [] };
  for (const [key, field] of [['format', 'format'], ['material', 'material'], ['thickness', 'thickness'], ['order', 'sort'], ['category', 'categoryId']]) {
    if (typeof args[field] === 'string' || Number.isSafeInteger(args[field])) result.filters[key] = diagnosticQuery(String(args[field])).slice(0, 40);
  }
  for (const [i, item] of (Array.isArray(facts.results) ? facts.results : []).slice(0, 5).entries()) {
    if (!(Number.isSafeInteger(item?.id) && item.id > 0) && !(typeof item?.id === 'string' && /^[a-z0-9_-]{1,80}$/.test(item.id))) continue;
    result.results.push({ id: item.id, rank: i + 1, kind: ['product', 'page', 'post', 'article', 'faq'].includes(item.kind) ? item.kind : 'unknown' });
  }
  if (facts.coverage) result.coverage = facts.coverage.complete === true ? 'complete' : 'incomplete';
  return result;
}

function appendDiagnostic(state, event) {
  const events = [...(Array.isArray(state.diagnostics) ? state.diagnostics : [])];
  if (event && events.length < 12 && JSON.stringify([...events, event]).length <= 4000) events.push(event);
  return { ...state, diagnostics: events };
}

function modelDiagnostic(state) {
  const code = state.response?.error?.code || state.groundingError;
  return typeof code === 'string' && /^[a-z_]{1,64}$/.test(code)
    ? appendDiagnostic(state, { event: 'rejection', code }) : state;
}

module.exports = { diagnosticQuery, toolDiagnostic, appendDiagnostic, modelDiagnostic };
