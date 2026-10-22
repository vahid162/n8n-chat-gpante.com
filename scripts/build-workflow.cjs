'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const library = name => read(name).replace(/^module\.exports\s*=.*;\s*$/m, '');
const stableId = name => createHash('sha256').update('gpante-support-chat/' + name).digest('hex').slice(0, 32);
function node(name, type, typeVersion, parameters, position, extra = {}) {
  return { id: stableId(name), name, type, typeVersion, parameters, position, ...extra };
}
function edge(to, type = 'main') { return { node: to, type, index: 0 }; }
function gate(name, field, position) {
  return node(name, 'n8n-nodes-base.if', 2.2, { conditions: {
    options: { caseSensitive: true, leftValue: '', typeValidation: 'strict', version: 2 },
    conditions: [{ id: stableId(name + '/condition'), leftValue: `={{ $json.${field} }}`, rightValue: '',
      operator: { type: 'boolean', operation: 'true', singleValue: true } }], combinator: 'and' }, options: {} }, position);
}
function build() {
  const chat = library('src/chat.cjs');
  const catalog = library('src/catalog.cjs');
  const code = (name, source) => node(name, 'n8n-nodes-base.code', 2, { jsCode: source }, [0, 0]);
  const pg = (name, query, replacement) => node(name, 'n8n-nodes-base.postgres', 2.6,
    { operation: 'executeQuery', query, options: { queryReplacement: replacement, connectionTimeout: 10 } }, [0, 0], { onError: 'continueRegularOutput' });
  const http = (name, parameters, timeout) => node(name, 'n8n-nodes-base.httpRequest', 4.4,
    { ...parameters, options: { timeout, redirect: { redirect: { followRedirects: false } },
      response: { response: { fullResponse: true, neverError: true, responseFormat: 'json' } } } }, [0, 0], { onError: 'continueRegularOutput' });
  const nodes = [
    node('Chat Entry', 'n8n-nodes-base.webhook', 2.2, { httpMethod: 'POST', path: 'gpante-ai-support-v1', authentication: 'headerAuth', responseMode: 'responseNode', options: {} }, [0, 0], { webhookId: 'b4f78fd3-66b8-4a6b-9f98-3461dad39e6c' }),
    code('Validate Trusted Input', chat + '\nreturn [{json: validateTrusted($input.first().json.body)}];'),
    gate('Input Is Valid', 'valid', [0, 0]),
    pg('Admit Request', 'SELECT gpante_chat.admit_v3($1::text,$2::text,$3::text,$4::text,$5::integer) AS result', '={{ [$json.sessionId,$json.clientId,$json.action,$json.chatInput,$json.policyVersion] }}'),
    code('Apply Admission', chat + "\nreturn [{json: admitted($('Validate Trusted Input').first().json,$input.first().json.result)}];"),
    gate('Action Is Complete', 'done', [0, 0]),
    code('Finalize Request', "const state=$input.first().json; return [{json:state}];"),
    pg('Finish Request', 'SELECT gpante_chat.finish_v3($1::text,$2::text,$3::text,$4::text) AS result', '={{ [$json.requestId,$json.success ? $json.output : null,$json.success ? "success" : "error",$json.response?.error?.code || null] }}'),
    code('Apply Persistence', chat + "\nconst state=$('Finalize Request').first().json; return [{json: state.success && $input.first().json.result?.saved !== true ? failure('persistence_unavailable') : state}];"),
    node('Return Chat Reply', 'n8n-nodes-base.respondToWebhook', 1.5, { respondWith: 'json', responseBody: '={{ $json.response }}', options: { responseCode: '={{ $json.status || 503 }}', responseHeaders: { entries: [{ name: 'Cache-Control', value: 'no-store, private' }, { name: 'Retry-After', value: '={{ String($json.retryAfter || 0) }}' }] } } }, [0, 0]),
    node('Release Notes', 'n8n-nodes-base.stickyNote', 1, { content: '## gpante.com — AI Support\nنسخهٔ ۱٫۴٫۱ — پیش‌نویس احراز هویت‌شده\n\nبودجهٔ پروژه: ۲ دلار در ماه UTC، تاریخچه: ۳۰ روز.\nرزرو محافظه‌کارانهٔ هر فراخوانی: ۰٫۳۲ دلار؛ تسویه بر اساس usage.\nحداکثر ۴ تماس مدل، ۳ ابزار ترتیبی و ۲ گفتگوی هم‌زمان.\nآرشیو مدیریتی مستقل ۳۰روزه؛ سیاست تاریخچه ۳، ۷، ۱۵ یا ۳۰ روز. حذف کاربر فقط دسترسی/زمینه را می‌بندد.\nFAQ رسمی Elementor با اولویت پرسش عملی خرید و بازنویسی وفادار به منبع، ارجاع طراحی به پشتیبانی و راهنمای عمومی مرتبط؛ دسته، فروش کل دوره و مقاله‌های قبلی محفوظ‌اند. سقف‌ها و آرشیو قبلی حفظ شده‌اند.', height: 330, width: 540 }, [0, 400])
  ];
  const connections = {};
  const link = (from, to, branch = 0) => {
    connections[from] ||= { main: [] };
    connections[from].main[branch] ||= [];
    connections[from].main[branch].push(edge(to));
  };
  link('Chat Entry', 'Validate Trusted Input'); link('Validate Trusted Input', 'Input Is Valid');
  link('Input Is Valid', 'Admit Request'); link('Input Is Valid', 'Return Chat Reply', 1);
  link('Admit Request', 'Apply Admission'); link('Apply Admission', 'Action Is Complete');
  link('Action Is Complete', 'Return Chat Reply'); link('Action Is Complete', 'Prepare Model 1', 1);
  link('Finalize Request', 'Finish Request'); link('Finish Request', 'Apply Persistence'); link('Apply Persistence', 'Return Chat Reply');
  for (let i = 1; i <= 4; i++) {
    const n = suffix => `${suffix} ${i}`;
    nodes.push(
      code(n('Prepare Model'), chat + `\nreturn [{json:modelRequest($input.first().json,${JSON.stringify(read('src/system-message.txt'))})}];`),
      gate(n('Context Is Invalid'), 'done', [0, 0]),
      pg(n('Reserve Model'), 'SELECT gpante_chat.reserve_call($1::text,$2::integer) AS result', '={{ [$json.requestId,$json.callIndex] }}'),
      code(n('Apply Reservation'), chat + `\nreturn [{json:reserved($('${n('Prepare Model')}').first().json,$input.first().json.result)}];`),
      gate(n('Reservation Failed'), 'done', [0, 0]),
      http(n('Call DeepSeek'), { method: 'POST', url: 'https://api.deepseek.com/chat/completions', authentication: 'predefinedCredentialType', nodeCredentialType: 'deepSeekApi', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json.body) }}' }, 25000),
      code(n('Parse Model'), chat + `\nreturn [{json:modelResult($('${n('Apply Reservation')}').first().json,$input.first().json)}];`),
      pg(n('Settle Model'), 'SELECT gpante_chat.settle_call($1::text,$2::integer,$3::integer,$4::integer,$5::integer) AS result', '={{ [$json.requestId,$json.callIndex,$json.usage.prompt,$json.usage.completion,$json.usage.total] }}'),
      code(n('Apply Settlement'), chat + `\nreturn [{json:settled($('${n('Parse Model')}').first().json,$input.first().json.result)}];`),
      gate(n('Reply Is Ready'), 'done', [0, 0])
    );
    link(n('Prepare Model'), n('Context Is Invalid')); link(n('Context Is Invalid'), 'Finalize Request');
    link(n('Context Is Invalid'), n('Reserve Model'), 1); link(n('Reserve Model'), n('Apply Reservation'));
    link(n('Apply Reservation'), n('Reservation Failed')); link(n('Reservation Failed'), 'Finalize Request');
    link(n('Reservation Failed'), n('Call DeepSeek'), 1); link(n('Call DeepSeek'), n('Parse Model'));
    link(n('Parse Model'), n('Settle Model')); link(n('Settle Model'), n('Apply Settlement'));
    link(n('Apply Settlement'), n('Reply Is Ready')); link(n('Reply Is Ready'), 'Finalize Request');
    if (i === 4) { link(n('Reply Is Ready'), 'Finalize Request', 1); continue; }
    nodes.push(
      code(n('Prepare Tool'), catalog + `\nconst state=$input.first().json; try { const kind={Product_Search:'search',Product_Details:'details',Knowledge_Search:'knowledge',Category_Search:'categories'}[state.pendingTool.function.name]; return [{json:{...state,toolValid:true,toolRequest:prepare(kind,state.pendingTool.function.arguments)}}]; } catch { return [{json:{...state,toolValid:false}}]; }`),
      gate(n('Tool Is Valid'), 'toolValid', [0, 0]),
      http(n('Read Public Source'), { method: 'GET', url: '={{ $json.toolRequest.url }}', sendQuery: true, specifyQuery: 'json', jsonQuery: '={{ JSON.stringify($json.toolRequest.qs) }}' }, 10000),
      code(n('Record Tool Facts'), catalog + '\n' + chat + `\nconst state={...$('${n('Prepare Tool')}').itemMatching(0).json}; const facts=shape(state.toolRequest.context,$input.first().json); delete state.toolRequest; return [{json:toolResult(state,facts)}];`),
      code(n('Record Tool Error'), chat + "\nreturn [{json:toolResult($input.first().json,{ok:false,status:'invalid_query',results:[]})}];"),
      gate(n('Tools Remain'), 'toolsPending', [0, 0])
    );
    link(n('Reply Is Ready'), n('Prepare Tool'), 1); link(n('Prepare Tool'), n('Tool Is Valid'));
    link(n('Tool Is Valid'), n('Read Public Source')); link(n('Tool Is Valid'), n('Record Tool Error'), 1);
    link(n('Read Public Source'), n('Record Tool Facts'));
    link(n('Record Tool Facts'), n('Tools Remain')); link(n('Record Tool Error'), n('Tools Remain'));
    link(n('Tools Remain'), n('Prepare Tool')); link(n('Tools Remain'), `Prepare Model ${i + 1}`, 1);
  }
  // Model rounds are static; each tool queue shrinks within the three-tool request cap.
  nodes.filter(n => !n.name.startsWith('Tools Remain ')).forEach((n, index) => { if (n.type !== 'n8n-nodes-base.stickyNote') n.position = [Math.floor(index / 8) * 1800 + (index % 8) * 220, Math.floor(index / 8) * 320]; });
  nodes.filter(n => n.name.startsWith('Tools Remain ')).forEach(n => {
    const position = nodes.find(other => other.name === n.name.replace('Tools Remain', 'Record Tool Error')).position;
    n.position = [position[0] + 220, position[1] + 140];
  });
  return { name: 'gpante.com — AI Support — Main', active: false,
    description: 'نسخهٔ ۱٫۴٫۱: FAQ رسمی با انتخاب منبع درست خرید و بازنویسی وفادار، ارجاع طراحی به پشتیبانی و پاسخ عمومی مفید؛ دسته‌ها، محصولات و مقاله‌های قبلی محفوظ‌اند. اجرای ترتیبی سه ابزار/چهار تماس، سقف ۲ دلار/ماه UTC و آرشیو ۳۰روزه محفوظ‌اند.',
    nodes, connections, settings: { executionOrder: 'v1', timezone: 'Asia/Tehran', executionTimeout: 150,
      saveDataSuccessExecution: 'none', saveDataErrorExecution: 'none', saveManualExecutions: false, callerPolicy: 'workflowsFromSameOwner' } };
}
function cleanup() {
  return { name: 'gpante.com — AI Support — Retention', active: false,
    description: 'نسخهٔ ۱٫۲٫۰: پاک‌سازی ساعتی تاریخچهٔ منقضی ۳۰روزه؛ رزروهای مالی هرگز با انقضا آزاد نمی‌شوند.',
    nodes: [node('Every Hour', 'n8n-nodes-base.scheduleTrigger', 1.3, { rule: { interval: [{ field: 'hours', hoursInterval: 1 }] } }, [0, 0]),
      node('Remove Expired History', 'n8n-nodes-base.postgres', 2.6, { operation: 'executeQuery', query: 'SELECT gpante_chat.cleanup() AS result', options: {} }, [260, 0])],
    connections: { 'Every Hour': { main: [[edge('Remove Expired History')]] } },
    // This workflow returns only cleanup counts, so retain its production runs for maintenance checks.
    settings: { executionOrder: 'v1', timezone: 'UTC', executionTimeout: 60, saveDataSuccessExecution: 'all', saveDataErrorExecution: 'all', saveManualExecutions: false } };
}
function admin() {
  const source = library('src/admin.cjs');
  return { name: 'gpante.com — AI Support — Admin', active: false,
    description: 'نسخهٔ ۱٫۲٫۰: مدیریت خصوصی آرشیو ۳۰روزه و سیاست تاریخچه، مستقل از مدل و بودجه.',
    nodes: [
      node('Admin Entry','n8n-nodes-base.webhook',2.2,{httpMethod:'POST',path:'gpante-ai-support-admin-v1',authentication:'headerAuth',responseMode:'responseNode',options:{}},[0,0],{webhookId:stableId('Admin Entry')}),
      node('Validate Admin Input','n8n-nodes-base.code',2,{jsCode:source+'\nreturn [{json:adminInput($input.first().json.body)}];'},[250,0]),
      node('Read Chat Administration','n8n-nodes-base.postgres',2.6,{operation:'executeQuery',query:'SELECT gpante_chat.admin_api($1::jsonb) AS result',options:{queryReplacement:'={{ [JSON.stringify($json)] }}',connectionTimeout:10}},[500,0],{onError:'continueRegularOutput'}),
      node('Bound Admin Reply','n8n-nodes-base.code',2,{jsCode:"const r=$input.first().json.result;return [{json:{data:r && typeof r==='object' ? r : {error:'backend_unavailable'},status:r?.error==='policy_conflict'?409:r?.error?400:r?200:503}}];"},[750,0]),
      node('Return Admin Reply','n8n-nodes-base.respondToWebhook',1.5,{respondWith:'json',responseBody:'={{ $json.data }}',options:{responseCode:'={{ $json.status }}',responseHeaders:{entries:[{name:'Cache-Control',value:'no-store, private'}]}}},[1000,0])
    ],connections:{'Admin Entry':{main:[[edge('Validate Admin Input')]]},'Validate Admin Input':{main:[[edge('Read Chat Administration')]]},'Read Chat Administration':{main:[[edge('Bound Admin Reply')]]},'Bound Admin Reply':{main:[[edge('Return Admin Reply')]]}},
    settings:{executionOrder:'v1',timezone:'UTC',executionTimeout:20,saveDataSuccessExecution:'none',saveDataErrorExecution:'none',saveManualExecutions:false,callerPolicy:'workflowsFromSameOwner'} };
}
if (require.main === module) {
  fs.mkdirSync(path.join(root, 'workflows'), { recursive: true });
  for (const [filename, workflow] of [['gpante-support-main.json', build()], ['gpante-support-retention.json', cleanup()], ['gpante-support-admin.json', admin()]]) {
    fs.writeFileSync(path.join(root, 'workflows', filename), JSON.stringify(workflow, null, 2) + '\n');
    console.log('Built public workflow template: ' + filename);
  }
}
module.exports = { build, cleanup, admin };
