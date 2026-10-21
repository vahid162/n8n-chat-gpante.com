// Kept import-free so the same source can run in an n8n Code node.
const ATTRIBUTE_TAXONOMY = { format: 'pa_file-format', thickness: 'pa_thickness', material: 'pa_materials-required' };
const MAX_TOOL_OUTPUT = 16000;
function normalize(value) {
  if (typeof value !== 'string') throw new Error('Expected text.');
  return value.normalize('NFKC')
    .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
    .replace(/٫/g, '.').replace(/[\u200B-\u200F\u202A-\u202E\u2066-\u2069]/g, ' ')
    .toLowerCase().replace(/\s+/g, ' ').trim();
}

function plainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.prototype.toString.call(value) === '[object Object]';
}

function positiveInteger(value, label) {
  const raw = typeof value === 'number' ? String(value) : typeof value === 'string' ? normalize(value) : '';
  if (!/^[1-9]\d*$/.test(raw) || !Number.isSafeInteger(Number(raw)) || Number(raw) > 2147483647) throw new Error(`Invalid ${label}.`);
  return Number(raw);
}

function inputText(value, label, allowEmpty = false) {
  if (typeof value !== 'string' || value.length > 320) throw new Error(`Invalid ${label}.`);
  const result = normalize(value);
  if (result.length > 160 || (!allowEmpty && !result)) throw new Error(`Invalid ${label}.`);
  return result;
}

function searchText(value) {
  if (typeof value !== 'string' || /[\u0000-\u001F\u007F]/.test(value)) throw new Error('Invalid search.');
  const result = inputText(value, 'search', true);
  if (!/^[\p{L}\p{N} ._-]*$/u.test(result) || result && result.split(' ').length > 8) throw new Error('Invalid search.');
  return result;
}

function attributeTerm(kind, value) {
  const raw = typeof value === 'number' && Number.isFinite(value) ? String(value) : value;
  let key = inputText(raw, kind);
  const formats = {
    ai: 'ai', bmp: 'bmp', dwg: 'dwg', dxf: 'dxf', eps: 'eps', pdf: 'pdf', plt: 'plt', png: 'png', svg: 'svg',
    'all-cdr': 'all-cdr', 'cdr-v3': 'cdr-v3', 'کورل 13': 'cdr-v3',
    'cdr-v15': 'cdr-v15', 'cdr15': 'cdr-v15', 'cdr 15': 'cdr-v15', 'cdr v15': 'cdr-v15', 'کورل 15': 'cdr-v15',
    'cdr-v20': 'cdr-v20', 'cdr20': 'cdr-v20', 'cdr 20': 'cdr-v20', 'cdr v20': 'cdr-v20', 'کورل 20': 'cdr-v20',
  };
  const thicknesses = {
    '13': '13-mm', '16': '16-mm', '2.3': '2-3-mm', '2.45': '2-45-mm', '2.5': '2-5',
    '2.6': '2-6-%d9%85%db%8c%d9%84%db%8c%d9%85%d8%aa%d8%b1', '2.7': '2-7', '2.8': '2-8',
    '3': '3-mm', '4': '4-mm', '5.4': '5-4', '5.5': '5-5-%d9%85%db%8c%d9%84%db%8c%d9%85%d8%aa%d8%b1',
    '5.6': '5-6', '6': '6-mm', '8': '8-mm', 'آزاد': 'free', free: 'free',
  };
  const materials = {
    mdf: 'mdf', 'ام دی اف': 'mdf', 'ام دی آف': 'mdf', 'پلکسی': 'plexiglass', plexiglass: 'plexiglass',
    'mdf-plexiglass': 'mdf-plexiglass', 'mdf یا پلکسی': 'mdf-plexiglass', 'mdf-plxi': 'mdf-plxi',
    'mdf-%d9%88-%d9%be%d9%84%da%a9%d8%b3%db%8c': 'mdf-%d9%88-%d9%be%d9%84%da%a9%d8%b3%db%8c',
    'mdf و پلکسی': 'mdf-%d9%88-%d9%be%d9%84%da%a9%d8%b3%db%8c',
    'چوب': '%da%86%d9%88%d8%a8', '%da%86%d9%88%d8%a8': '%da%86%d9%88%d8%a8',
    strip: 'strip', 'طلق': 'strip', 'strip-or-plexiglass': 'strip-or-plexiglass',
    'multi-style': 'multi-style', 'مولتی استایل': 'multi-style', 'metal-bar': 'metal-bar', 'میله فلزی': 'metal-bar',
  };
  const terms = { format: formats, thickness: thicknesses, material: materials }[kind];
  if (Object.values(terms).includes(key)) return key;
  if (kind === 'thickness') {
    key = key.replace(/\s*(?:mm|میلی\s*متر|میلیمتر)$/, '').trim().replace(/[,-]/g, '.');
    if (/^\d+(?:\.\d+)?$/.test(key)) key = String(Number(key));
  }
  if (!Object.hasOwn(terms, key)) throw new Error(`Unsupported ${kind}; use a verified catalog term.`);
  return terms[key];
}

function prepare(kind, rawQuery) {
  if (!['search', 'details', 'knowledge', 'categories'].includes(kind)) throw new Error('Unsupported tool kind.');
  let query = rawQuery;
  if (typeof query === 'string') {
    if (query.length > 6000) throw new Error('Query is too long.');
    try { query = JSON.parse(query); } catch { throw new Error('Query must be a JSON object.'); }
  }
  if (!plainObject(query)) throw new Error('Query must be an object.');
  const allowed = { search: ['search', 'format', 'thickness', 'material', 'page', 'sort', 'categoryId'], details: ['productId'],
    knowledge: ['search', 'source', 'page', 'categoryId'], categories: ['search', 'source', 'parentId', 'page'] }[kind];
  if (Object.keys(query).some((key) => !allowed.includes(key))) throw new Error('Unsupported query field.');
  const page = query.page === undefined ? 1 : positiveInteger(query.page, 'page');
  const context = { kind, page, perPage: kind === 'details' ? 1 : kind === 'knowledge' ? 3 : 5, constraints: {} };
  const qs = { page };
  if (query.categoryId !== undefined) context.categoryId = qs.categoryId = positiveInteger(query.categoryId, 'categoryId');
  if (kind === 'categories') {
    context.source = qs.source = query.source === undefined ? 'products' : inputText(query.source, 'source');
    if (!['products', 'articles'].includes(context.source)) throw new Error('Unsupported category source.');
    if (query.search !== undefined) qs.search = searchText(query.search);
    if (query.parentId !== undefined) {
      const root = ['string', 'number'].includes(typeof query.parentId) && normalize(String(query.parentId)) === '0';
      context.parentId = qs.parentId = root ? 0 : positiveInteger(query.parentId, 'parentId');
    }
    return { url: 'https://gpante.com/wp-json/gpante-support/v1/categories', qs, context };
  }
  if (kind === 'knowledge') {
    const source = query.source === undefined ? 'articles' : inputText(query.source, 'source');
    if (!['articles', 'guides'].includes(source)) throw new Error('Unsupported knowledge source.');
    if (source === 'guides' && context.categoryId !== undefined) throw new Error('Guides do not support article categories.');
    context.source = source;
    qs.source = source;
    if (query.search !== undefined) qs.search = searchText(query.search);
    return { url: 'https://gpante.com/wp-json/gpante-support/v1/knowledge', qs, context };
  }
  if (kind === 'details') {
    context.productId = positiveInteger(query.productId, 'productId');
    delete qs.page;
    qs.productId = context.productId;
  } else {
    qs.search = query.search === undefined ? '' : searchText(query.search);
    context.sort = query.sort === undefined ? 'relevance' : inputText(query.sort, 'sort');
    if (!['relevance', 'price_asc', 'newest', 'best_selling'].includes(context.sort)) throw new Error('Unsupported sort.');
    qs.sort = context.sort;
    for (const key of ['format', 'thickness', 'material']) {
      if (query[key] !== undefined) {
        const slug = attributeTerm(key, query[key]);
        context.constraints[key] = slug;
        qs[key] = slug;
      }
    }
    if (!qs.search && !Object.keys(context.constraints).length && !context.categoryId
      && !['newest', 'best_selling'].includes(context.sort)) throw new Error('Supply search text, a category, an attribute or an explicit browse order.');
  }
  return { url: 'https://gpante.com/wp-json/gpante-support/v1/products', qs, context };
}

function publicText(value, limit) {
  if (typeof value !== 'string') return { text: '', truncated: false };
  const entities = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', zwnj: '\u200c', ndash: '–', mdash: '—', hellip: '…' };
  const strip = (text) => text
    .replace(/<(script|style|iframe|object|template)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]*>/g, ' ')
    .replace(/\[[^\]\r\n]*\]/g, ' ');
  let text = strip(value).replace(/&(#x[\da-f]+|#\d+|[a-z]+);/gi, (entity, key) => {
    if (key.startsWith('#')) {
      const code = key[1].toLowerCase() === 'x' ? parseInt(key.slice(2), 16) : Number(key.slice(1));
      return Number.isInteger(code) && code > 0 && code <= 0x10ffff && !(code >= 0xd800 && code <= 0xdfff) ? String.fromCodePoint(code) : ' ';
    }
    return entities[key.toLowerCase()] === undefined ? ' ' : entities[key.toLowerCase()];
  });
  text = strip(text).replace(/(?:https?:\/\/|www\.)[^\s<>]+/gi, ' ')
    .replace(/[\u0000-\u001F\u007F\u202A-\u202E\u2066-\u2069]/g, ' ').replace(/\s+/g, ' ').trim();
  return { text: text.slice(0, limit), truncated: text.length > limit };
}

function safeLink(value, mode) {
  if (typeof value !== 'string' || value.length > 2048) return null;
  try {
    // Fixed-origin parsing avoids relying on URL globals absent in some Code runtimes.
    const match = /^https:\/\/gpante\.com(\/[^?#]*)$/.exec(value);
    if (!match) return null;
    const path = decodeURIComponent(match[1]);
    if (/[\u0000-\u0020\\]/.test(path) || /(?:^|\/)\.{1,2}(?:\/|$)/.test(path)
      || /(?:^|\/)wp-(?:admin|json|login|content)(?:\/|\.|$)/i.test(path) && mode !== 'image') return null;
    if (mode === 'product' && !/^\/product\/[^/]+\/?$/.test(path)) return null;
    if (mode === 'image' && (!path.startsWith('/wp-content/uploads/') || !/\.(?:png|jpe?g|webp|gif|avif)$/i.test(path))) return null;
    if (mode === 'page' && (/\.[a-z\d]{1,8}$/i.test(path) || path === '/')) return null;
    return value;
  } catch { return null; }
}

function publicPrice(value) {
  if (value === undefined || value === null) return null;
  if (!plainObject(value)) throw new Error('Invalid price.');
  if (value.price === undefined || value.price === '') return null;
  if (typeof value.price !== 'string' || !/^\d{1,64}$/.test(value.price)
    || !Number.isInteger(value.currency_minor_unit) || value.currency_minor_unit < 0 || value.currency_minor_unit > 9
    || typeof value.currency_code !== 'string' || !/^[A-Z]{2,12}$/.test(value.currency_code)) throw new Error('Invalid price.');
  const minorAmount = value.price.replace(/^0+(?=\d)/, '');
  const unit = value.currency_minor_unit;
  const padded = minorAmount.padStart(unit + 1, '0');
  return { amount: unit ? `${padded.slice(0, -unit)}.${padded.slice(-unit)}` : padded, minorAmount, currency: value.currency_code, minorUnit: unit };
}

function categoryIds(item, context) {
  const ids = item.category_ids === undefined ? [] : item.category_ids;
  if (!Array.isArray(ids) || ids.length > 100 || ids.some(id => !Number.isSafeInteger(id) || id < 1 || id > 2147483647)
    || new Set(ids).size !== ids.length) throw new Error('Invalid categories.');
  if (context.categoryId && !ids.includes(context.categoryId)) {
    const error = new Error('Source did not honor requested category.');
    error.code = 'constraint_mismatch';
    throw error;
  }
  return ids;
}

function publicProduct(item, context) {
  if (!plainObject(item) || !Number.isSafeInteger(item.id) || item.id < 1
    || typeof item.name !== 'string' || !item.name.trim() || !safeLink(item.permalink, 'product')
    || !['visible', 'catalog', 'search'].includes(item.catalog_visibility)
    || item.is_password_protected !== false || item.status !== 'publish'
    || typeof item.type !== 'string' || !item.type || item.type === 'variation') throw new Error('Invalid product.');
  if (context.kind === 'details' && item.id !== context.productId) throw new Error('Wrong product.');
  if (item.attributes !== undefined && !Array.isArray(item.attributes)) throw new Error('Invalid attributes.');
  const attributes = (item.attributes || []).map((attribute) => {
    if (!plainObject(attribute) || typeof attribute.name !== 'string' || !Array.isArray(attribute.terms)) throw new Error('Invalid attribute.');
    return {
      name: publicText(attribute.name, 100).text,
      taxonomy: typeof attribute.taxonomy === 'string' ? attribute.taxonomy.slice(0, 100) : '',
      terms: attribute.terms.map((term) => {
        if (!plainObject(term) || typeof term.name !== 'string' || typeof term.slug !== 'string') throw new Error('Invalid term.');
        return { name: publicText(term.name, 100).text, slug: term.slug.slice(0, 200) };
      }).slice(0, 40),
    };
  }).slice(0, 30);
  for (const [key, slug] of Object.entries(context.constraints)) {
    if (!attributes.some((attribute) => attribute.taxonomy === ATTRIBUTE_TAXONOMY[key]
      && attribute.terms.some((term) => normalize(term.slug) === normalize(slug)
        // The provider verifies the actual OR-term name before expanding compatibility.
        || key === 'material' && ['mdf', 'plexiglass'].includes(slug)
          && term.slug === 'mdf-plexiglass' && normalize(term.name) === 'mdf یا پلکسی'))) {
      const error = new Error('Source did not honor requested attributes.');
      error.code = 'constraint_mismatch';
      throw error;
    }
  }
  if (item.images !== undefined && !Array.isArray(item.images)) throw new Error('Invalid images.');
  const images = (item.images || []).filter((entry) => plainObject(entry) && safeLink(entry.src, 'image')).slice(0, 3)
    .map((entry) => ({ url: safeLink(entry.src, 'image'), alt: publicText(entry.alt || '', 160).text }));
  const summary = publicText(item.short_description || '', 800);
  const publishedAt = typeof item.published_at === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(item.published_at)
    && Number.isFinite(Date.parse(item.published_at)) && new Date(item.published_at).toISOString().replace('.000Z', 'Z') === item.published_at ? item.published_at : null;
  return { id: item.id, name: publicText(item.name, 240).text, url: safeLink(item.permalink, 'product'),
    summary: summary.text, truncated: summary.truncated, price: publicPrice(item.prices), attributes, images,
    categoryIds: categoryIds(item, context), publishedAt,
    inStock: typeof item.is_in_stock === 'boolean' ? item.is_in_stock : null,
    onSale: typeof item.on_sale === 'boolean' ? item.on_sale : null };
}

function publicArticle(item, context) {
  const snippet = item && (context.source === 'guides' ? item.content : item.excerpt);
  if (!plainObject(item) || !Number.isSafeInteger(item.id) || item.id < 1 || !safeLink(item.link, 'page')
    || item.status !== 'publish' || item.is_password_protected !== false
    || context.source === 'guides' && ![4752, 4743].includes(item.id)
    || !plainObject(item.title) || typeof item.title.rendered !== 'string'
    || !plainObject(snippet) || typeof snippet.rendered !== 'string'
    || item.title.protected === true || snippet.protected === true) throw new Error('Invalid public article.');
  const title = publicText(item.title.rendered, 240);
  const excerpt = publicText(snippet.rendered, 1200);
  return { id: item.id, title: title.text, url: safeLink(item.link, 'page'), excerpt: excerpt.text,
    categoryIds: categoryIds(item, context), truncated: title.truncated || excerpt.truncated || snippet.truncated === true };
}

function publicCategory(item, context) {
  if (!plainObject(item) || !Number.isSafeInteger(item.id) || item.id < 1 || item.id > 2147483647
    || typeof item.name !== 'string' || !publicText(item.name, 160).text || !safeLink(item.link, 'page')
    || !Number.isSafeInteger(item.parent) || item.parent < 0 || item.parent > 2147483647
    || item.source !== context.source || context.parentId !== undefined && item.parent !== context.parentId) throw new Error('Invalid public category.');
  return { id: item.id, name: publicText(item.name, 160).text, parent: item.parent, url: safeLink(item.link, 'page') };
}

function shape(context, httpResponse) {
  const kind = context && context.kind;
  const fail = (status, extra = {}) => ({ ok: false, status, kind, results: [], ...extra });
  if (!plainObject(context) || !['search', 'details', 'knowledge', 'categories'].includes(kind)
    || !Number.isSafeInteger(context.page) || context.page < 1 || ![1, 3, 5].includes(context.perPage)
    || !plainObject(context.constraints)) return fail('invalid_response');
  if (!plainObject(httpResponse) || !Number.isInteger(httpResponse.statusCode)) return fail('transport_error');
  const statusCode = httpResponse.statusCode;
  const headers = {};
  if (plainObject(httpResponse.headers)) {
    for (const [key, value] of Object.entries(httpResponse.headers)) headers[key.toLowerCase()] = value;
  }
  if (statusCode === 429) {
    const rawRetry = headers['retry-after'];
    const retryAfter = typeof rawRetry === 'string' && (/^\d{1,9}$/.test(rawRetry) || /^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/.test(rawRetry)) ? rawRetry : null;
    return fail('rate_limited', { httpStatus: statusCode, retryAfter });
  }
  if (statusCode < 200 || statusCode >= 300) return fail('api_error', { httpStatus: statusCode });
  let body = httpResponse.body;
  if (typeof body === 'string') {
    if (body.length > 2000000) return fail('invalid_response');
    try { body = JSON.parse(body); } catch { return fail('invalid_response'); }
  }
  if (!Array.isArray(body) || body.length > context.perPage) return fail('invalid_response');
  const numberHeader = (name) => {
    const value = headers[name];
    if (value === undefined) return null;
    const raw = String(value);
    if (!/^\d+$/.test(raw) || !Number.isSafeInteger(Number(raw))) throw new Error('Invalid pagination.');
    return Number(raw);
  };
  let total, totalPages;
  try {
    total = numberHeader('x-wp-total');
    totalPages = numberHeader('x-wp-totalpages');
  } catch { return fail('invalid_response'); }
  if (total !== null && totalPages !== null && Math.ceil(total / context.perPage) !== totalPages) return fail('invalid_response');
  if (total !== null && total < body.length) return fail('invalid_response');
  const expectedCount = total === null ? null : Math.min(context.perPage, Math.max(0, total - (context.page - 1) * context.perPage));
  if (expectedCount !== null && body.length > expectedCount || totalPages !== null && context.page > totalPages && body.length) return fail('invalid_response');
  if (expectedCount !== null && body.length < expectedCount
    || !body.length && totalPages !== null && context.page <= totalPages) return fail('incomplete_response');
  const hasMore = totalPages !== null ? context.page < totalPages
    : total !== null ? context.page * context.perPage < total : body.length < context.perPage ? false : null;
  let results;
  try {
    results = body.map((item) => kind === 'categories' ? publicCategory(item, context) : kind === 'knowledge' ? publicArticle(item, context) : publicProduct(item, context));
    if (new Set(results.map((item) => item.id)).size !== results.length) return fail('invalid_response');
  } catch (error) { return fail(error.code === 'constraint_mismatch' ? error.code : 'invalid_response'); }
  if (kind === 'search' && ['newest', 'best_selling'].includes(context.sort)) {
    if (headers['x-gpante-sort'] !== context.sort
      || context.sort === 'best_selling' && headers['x-gpante-sales-period'] !== 'all_time'
      || context.sort === 'newest' && results.some(item => !item.publishedAt)) return fail('sort_unverifiable');
    if (context.sort === 'newest' && results.some((item, index) => index > 0
      && (item.publishedAt > results[index - 1].publishedAt || item.publishedAt === results[index - 1].publishedAt && item.id > results[index - 1].id))) return fail('sort_mismatch');
  }
  if (kind === 'search' && context.sort === 'price_asc') {
    if (results.some((item) => item.price === null)
      || results.some((item) => item.price.currency !== results[0].price.currency || item.price.minorUnit !== results[0].price.minorUnit)) return fail('sort_unverifiable');
    for (let index = 1; index < results.length; index += 1) {
      const previous = results[index - 1].price.minorAmount;
      const current = results[index].price.minorAmount;
      if (previous.length > current.length || previous.length === current.length && previous > current) return fail('sort_mismatch');
    }
  }
  const result = {
    ok: true, status: !results.length ? 'no_matches' : hasMore === false ? 'ok' : 'partial', kind, results,
    pagination: { page: context.page, perPage: context.perPage, total, totalPages, hasMore, scope: 'server_filtered_page' },
    ...(context.categoryId ? { categoryId: context.categoryId } : {}),
    ...(context.source ? { source: context.source } : {}),
    ...(kind === 'search' && context.sort === 'best_selling' ? { salesPeriod: 'all_time' } : {}),
    ...(kind === 'search' ? { sort: context.sort, ...(Object.keys(context.constraints).length
      ? { constraints: context.constraints, attributeMatching: 'verified_taxonomy_compatibility' } : {}) } : {}),
  };
  return JSON.stringify(result).length > MAX_TOOL_OUTPUT ? fail('response_too_large') : result;
}

module.exports = { normalize, prepare, shape };
