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
function embeddedTool(kind) {
  const nodes = [
    node('Tool Input', 'n8n-nodes-base.executeWorkflowTrigger', 1.1, { inputSource: 'passthrough' }, [0, 0]),
    node('Prepare Tool Request', 'n8n-nodes-base.code', 2, {
      jsCode: library('src/catalog.cjs') + `\nreturn [{json: prepare(${JSON.stringify(kind)}, $input.first().json.query)}];`
    }, [240, 0]),
    node('Read Public Source', 'n8n-nodes-base.httpRequest', 4.4, {
      method: 'GET', url: '={{ $json.url }}', sendQuery: true,
      specifyQuery: 'json', jsonQuery: '={{ JSON.stringify($json.qs) }}',
      options: { timeout: 15000, redirect: { redirect: { followRedirects: false } },
        response: { response: { fullResponse: true, neverError: true, responseFormat: 'text', outputPropertyName: 'body' } } }
    }, [480, 0], { onError: 'continueRegularOutput' }),
    node('Return Public Facts', 'n8n-nodes-base.code', 2, {
      jsCode: library('src/catalog.cjs') + "\nreturn [{json: shape($('Prepare Tool Request').item.json.context, $input.first().json)}];"
    }, [720, 0])
  ];
  const role = { search: 'Product Search', details: 'Product Details', knowledge: 'Knowledge Search' }[kind];
  return { name: `gpante.com — AI Support — ${role}`, nodes,
    connections: { 'Tool Input': { main: [[edge('Prepare Tool Request')]] },
      'Prepare Tool Request': { main: [[edge('Read Public Source')]] },
      'Read Public Source': { main: [[edge('Return Public Facts')]] } },
    settings: { executionOrder: 'v1', saveDataSuccessExecution: 'none', saveDataErrorExecution: 'none', saveManualExecutions: false } };
}
function gate(name, field, position) {
  return node(name, 'n8n-nodes-base.if', 2.2, { conditions: {
    options: { caseSensitive: true, leftValue: '', typeValidation: 'strict', version: 2 },
    conditions: [{ id: stableId(name + '/condition'), leftValue: `={{ $json.${field} }}`, rightValue: '',
      operator: { type: 'boolean', operation: 'true', singleValue: true } }], combinator: 'and' }, options: {} }, position);
}
function build() {
  const chatLibrary = library('src/chat.cjs');
  const nodes = [
    node('Chat Entry', '@n8n/n8n-nodes-langchain.chatTrigger', 1.5, {
      public: true, mode: 'webhook', authentication: 'none',
      options: { allowedOrigins: 'https://gpante.com,https://www.gpante.com', loadPreviousSession: 'notSupported',
        allowFileUploads: false, responseMode: 'lastNode', autoSaveHighlightedData: false }
    }, [0, 0], { webhookId: 'b4f78fd3-66b8-4a6b-9f98-3461dad39e6c' }),
    node('Validate Chat Input', 'n8n-nodes-base.code', 2, { jsCode: chatLibrary + '\nreturn [{json: validate($input.first().json)}];' }, [240, 0]),
    gate('Input Is Valid', 'valid', [480, 0]),
    node('Reserve Request Budget', 'n8n-nodes-base.postgres', 2.6, { operation: 'executeQuery',
      query: 'SELECT * FROM gpante_chat.reserve_request($1::text)',
      options: { queryReplacement: '={{ [$json.sessionId] }}', connectionTimeout: 10 } }, [720, -100], { onError: 'continueRegularOutput' }),
    node('Apply Budget Result', 'n8n-nodes-base.code', 2, {
      jsCode: chatLibrary + "\nreturn [{json: budget($('Validate Chat Input').item.json, $input.first().json)}];"
    }, [960, -100]),
    gate('Budget Is Available', 'allowed', [1200, -100]),
    node('Support Agent', '@n8n/n8n-nodes-langchain.agent', 3.1, {
      promptType: 'define', text: '={{ $json.chatInput }}',
      options: { systemMessage: read('src/system-message.txt'), maxIterations: 4, returnIntermediateSteps: false,
        passthroughBinaryImages: false, passthroughBinaryPdfs: false, enableStreaming: false, autoSaveHighlightedData: false }
    }, [1460, -100], { onError: 'continueRegularOutput' }),
    node('DeepSeek Model', '@n8n/n8n-nodes-langchain.lmChatDeepSeek', 1, {
      model: 'deepseek-flash', options: { maxTokens: 8192, temperature: 0.2, timeout: 45000, maxRetries: 0 }
    }, [1280, 260]),
    node('Conversation Memory', '@n8n/n8n-nodes-langchain.memoryPostgresChat', 1.4, {
      sessionIdType: 'customKey', sessionKey: "={{ 'gpante_chat:' + $json.sessionId }}",
      tableName: 'gpante_chat.histories', contextWindowLength: 6
    }, [1500, 260]),
    node('Product Search', '@n8n/n8n-nodes-langchain.toolWorkflow', 2.2, {
      source: 'parameter', workflowJson: JSON.stringify(embeddedTool('search')),
      description: 'Search all public searchable gpante.com products server-side. Input a JSON string {"search":"موضوع","format":"dxf","thickness":"2.7","material":"mdf","page":1}; optional constraints may be omitted. Conditions are combined with AND. Return size5 is one page, not the whole catalog; use pagination for more. Do not silently alter constraints.'
    }, [1730, 260]),
    node('Product Details', '@n8n/n8n-nodes-langchain.toolWorkflow', 2.2, {
      source: 'parameter', workflowJson: JSON.stringify(embeddedTool('details')),
      description: 'Read current public product facts/price by ID. Input JSON string {"productId":49304}. Use before giving current price or technical facts. Hidden/unsearchable products are not returned. No downloads or customer data.'
    }, [1940, 260]),
    node('Knowledge Search', '@n8n/n8n-nodes-langchain.toolWorkflow', 2.2, {
      source: 'parameter', workflowJson: JSON.stringify(embeddedTool('knowledge')),
      description: 'Read public site knowledge. Input JSON string {"source":"articles","search":"موضوع","page":1} for public posts, or {"source":"guides"} for the actual public rules/contact pages. Cite returned page links; do not invent policies beyond the source or treat truncated text as complete.'
    }, [2150, 260]),
    node('Return Chat Reply', 'n8n-nodes-base.code', 2, {
      jsCode: chatLibrary + '\nreturn [{json: {output: finish($input.first().json)}}];'
    }, [2420, -100]),
    node('Release Notes', 'n8n-nodes-base.stickyNote', 1, {
      content: '## gpante.com — AI Support\nنسخهٔ ۱٫۰٫۰ — پیش‌نویس غیرفعال\n\nکاتالوگ عمومی کامل، سه ابزار خواندنی، DeepSeek و حافظهٔ PostgreSQL جدا.\n\nپیش از انتشار: تأیید سقف مصرف/نگهداری، پراکسی و کنترل IP، آزمون چت و ویجت.\n\nابزارهای سفارش، دانلود خصوصی و انتشار محصول در این نسخه وجود ندارند.',
      height: 270, width: 520
    }, [0, 280])
  ];
  const connections = {
    'Chat Entry': { main: [[edge('Validate Chat Input')]] },
    'Validate Chat Input': { main: [[edge('Input Is Valid')]] },
    'Input Is Valid': { main: [[edge('Reserve Request Budget')], [edge('Return Chat Reply')]] },
    'Reserve Request Budget': { main: [[edge('Apply Budget Result')]] },
    'Apply Budget Result': { main: [[edge('Budget Is Available')]] },
    'Budget Is Available': { main: [[edge('Support Agent')], [edge('Return Chat Reply')]] },
    'Support Agent': { main: [[edge('Return Chat Reply')]] },
    'DeepSeek Model': { ai_languageModel: [[edge('Support Agent', 'ai_languageModel')]] },
    'Conversation Memory': { ai_memory: [[edge('Support Agent', 'ai_memory')]] }
  };
  for (const name of ['Product Search', 'Product Details', 'Knowledge Search']) connections[name] = { ai_tool: [[edge('Support Agent', 'ai_tool')]] };
  return { name: 'gpante.com — AI Support — Main', active: false,
    description: 'نسخهٔ ۱٫۰٫۰: پشتیبانی عمومی، جست‌وجوی کل کاتالوگ، مشخصات/قیمت جاری و محتوای عمومی. پیش‌نویس غیرفعال؛ انتشار عمومی انجام نشده است.',
    nodes, connections, settings: { executionOrder: 'v1', timezone: 'Asia/Tehran', executionTimeout: 150,
      saveDataSuccessExecution: 'none', saveDataErrorExecution: 'none', saveManualExecutions: false, callerPolicy: 'workflowsFromSameOwner' } };
}
if (require.main === module) {
  const output = path.join(root, 'workflows/gpante-support-main.json');
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, JSON.stringify(build(), null, 2) + '\n');
  console.log('Built public workflow template: ' + output);
}
module.exports = { build };
