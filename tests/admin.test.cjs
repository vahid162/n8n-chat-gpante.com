'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {adminInput}=require('../src/admin.cjs');
const {build,admin}=require('../scripts/build-workflow.cjs');
test('administrative input accepts only bounded fixed operations and strips extra fields',()=>{
 assert.deepEqual(adminInput({operation:'getPolicy',sql:'DROP DATABASE anything'}),{operation:'getPolicy'});
 assert.deepEqual(adminInput({operation:'setPolicy',userDays:7,allowClear:false,revision:1}),{operation:'setPolicy',userDays:7,allowClear:false,revision:1});
 for(const v of [null,[],{operation:'executeSql'},{operation:'setPolicy',userDays:4,allowClear:true,revision:1},{operation:'listSessions',page:1001},{operation:'listSessions',search:'x'.repeat(101)},{operation:'getSession',sessionId:'a'.repeat(63)}])assert.deepEqual(adminInput(v),{});
 assert.equal(adminInput({operation:'getSession',sessionId:'a'.repeat(64),page:2}).page,2);
});
test('administration is a separate authenticated graph with raw execution storage disabled',()=>{
 const w=admin();assert.equal(w.name,'gpante.com — AI Support — Admin');
 assert.equal(w.nodes[0].parameters.authentication,'headerAuth');
 assert.equal(w.settings.saveDataErrorExecution,'none');
 assert(!w.nodes.some(n=>n.type==='n8n-nodes-base.httpRequest'));
 const main=build();assert(!main.nodes.some(n=>n.parameters.query?.includes('admin_api')));
 assert.match(main.nodes.find(n=>n.name==='Admit Request').parameters.query,/admit_v3/);
});
