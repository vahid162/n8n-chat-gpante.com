'use strict';
// Run with Node inside the installed n8n container. No database/API writes.
process.argv[1] = '/usr/local/lib/node_modules/n8n/bin/n8n';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const base = '/usr/local/lib/node_modules/n8n';
require(base + '/node_modules/reflect-metadata');
const { Container } = require(base + '/node_modules/@n8n/di');
const { LoadNodesAndCredentials } = require(base + '/dist/load-nodes-and-credentials');
const { NodeTypes } = require(base + '/dist/node-types');
const { WorkflowValidationService } = require(base + '/dist/workflows/workflow-validation.service');
const sdk = require(base + '/node_modules/@n8n/workflow-sdk');
(async () => {
  const input = process.argv[2];
  if (!input) throw new Error('Provide a workflow JSON path');
  await Container.get(LoadNodesAndCredentials).init();
  const types = Container.get(NodeTypes);
  const service = new WorkflowValidationService(null, null, null, null);
  const reports = [];
  async function check(workflow) {
    const activation = await service.validateForActivation(Object.fromEntries(workflow.nodes.map(n => [n.name, n])), workflow.connections, types);
    const validation = sdk.validateWorkflow(workflow, { nodeTypesProvider: types, strictMode: true });
    assert(activation.isValid, JSON.stringify(activation));
    assert(validation.valid, JSON.stringify(validation));
    assert.equal(validation.warnings.length, 0, JSON.stringify(validation.warnings));
    for (const n of workflow.nodes) {
      types.getByNameAndVersion(n.type, n.typeVersion);
      if (n.type === 'n8n-nodes-base.code') new Function(n.parameters.jsCode);
      if (n.type === '@n8n/n8n-nodes-langchain.toolWorkflow' && n.parameters.source === 'parameter') await check(JSON.parse(n.parameters.workflowJson));
    }
    reports.push({ name: workflow.name, nodes: workflow.nodes.length, activation: true, sdkStrict: true, warnings: 0 });
  }
  await check(JSON.parse(fs.readFileSync(input, 'utf8')));
  console.log(JSON.stringify({ passed: true, reports }));
})().catch(e => { console.error(e.message); process.exitCode = 1; });
