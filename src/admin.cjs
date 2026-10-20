'use strict';
// Fixed operations only; neither the browser nor the model can provide SQL or destinations.
function adminInput(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  const { operation } = value;
  if (operation === 'getPolicy') return { operation };
  if (operation === 'setPolicy') {
    if (![3, 7, 15, 30].includes(value.userDays) || typeof value.allowClear !== 'boolean'
      || !Number.isSafeInteger(value.revision) || value.revision < 1 || value.revision > 999999999) return {};
    return { operation, userDays: value.userDays, allowClear: value.allowClear, revision: value.revision };
  }
  if (!['listSessions', 'getSession'].includes(operation)) return {};
  const page = value.page ?? 1;
  if (!Number.isSafeInteger(page) || page < 1 || page > 1000) return {};
  if (operation === 'getSession') return /^[a-f0-9]{64}$/.test(value.sessionId || '') ? { operation, page, sessionId: value.sessionId } : {};
  const { search = '', from = '', to = '', status = '', visibility = '' } = value;
  if (typeof search !== 'string' || search.length > 100 || ![from, to].every(s => typeof s === 'string' && (!s || /^\d{4}-\d{2}-\d{2}$/.test(s)))
    || !['', 'pending', 'success', 'error', 'interrupted', 'legacy'].includes(status)
    || !['', 'visible', 'cleared', 'expired'].includes(visibility)) return {};
  return { operation, page, search, from, to, status, visibility };
}
module.exports = { adminInput };
