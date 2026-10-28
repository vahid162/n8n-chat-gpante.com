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
 assert.match(main.nodes.find(n=>n.name==='Admit Request').parameters.query,/admit_v4/);
});
test('diagnostics accept bounded server filters and preserve the legacy no-argument request',()=>{
 assert.deepEqual(adminInput({operation:'getDiagnostics',sql:'ignored'}),{operation:'getDiagnostics'});
 const filters={page:2,search:'<script>needle</script>',from:'2024-02-29',to:'2024-03-01',status:'error',traffic:'synthetic',source:'site'};
 assert.deepEqual(adminInput({operation:'getDiagnostics',...filters,sessionId:'private',sql:'ignored'}),{operation:'getDiagnostics',...filters});
 assert.deepEqual(adminInput({operation:'getDiagnostics',search:'needle'}),{operation:'getDiagnostics',page:1,search:'needle',from:'',to:'',status:'',traffic:'',source:''});
 for(const source of ['','products','articles','site','faq','guides','categories'])assert.equal(adminInput({operation:'getDiagnostics',source}).source,source);
 for(const status of ['','pending','success','error','interrupted','legacy'])assert.equal(adminInput({operation:'getDiagnostics',status}).status,status);
 for(const traffic of ['','synthetic','unclassified'])assert.equal(adminInput({operation:'getDiagnostics',traffic}).traffic,traffic);
});
test('diagnostics reject malformed filters at the workflow trust boundary',()=>{
 const invalid=[{page:0},{page:1001},{page:1.5},{page:'2'},{page:null},{search:'x'.repeat(101)},{search:[]},
  {from:'2026-02-29'},{from:'2024-02-30'},{from:'0000-01-01'},{from:'2026-13-01'},{to:'2026-10-01T00:00:00Z'},
  {from:'2026-10-02',to:'2026-10-01'},{from:null},{status:'human'},{status:[]},{traffic:'human'},{source:'unknown'},{source:{}},
  {search:null},{to:42}];
 for(const filter of invalid)assert.deepEqual(adminInput({operation:'getDiagnostics',...filter}),{},JSON.stringify(filter));
 assert.equal(adminInput({operation:'getDiagnostics',page:1000,search:'x'.repeat(100),from:'2024-02-29',to:'2024-02-29'}).page,1000);
});
