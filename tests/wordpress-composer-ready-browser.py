"""Immediate composing and serialized early send through actual local WP/Chrome."""
import json
import os
import subprocess
from pathlib import Path

root=Path('/home/codexops/n8n/.gpante-wp-test')
base='https://127.0.0.1:9443'
browser='/home/codexops/n8n/.tools/agent-browser'
session='gpante-composer-ready-042'
env=dict(os.environ,AGENT_BROWSER_DEFAULT_TIMEOUT='45000')
evidence=root/'evidence'
def run(*args):
    p=subprocess.run([browser,'--session',session,'--ignore-https-errors','--json',*args],capture_output=True,text=True,env=env,timeout=60)
    assert p.returncode==0,p.stderr[:800] or p.stdout[:800]
    d=json.loads(p.stdout);assert d['success'],d.get('error');return d['data']
def js(code):return run('eval',code)['result']
def wp(code):return subprocess.check_output(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'eval',code],text=True)
def ready():run('wait','--fn',"document.querySelector('[data-messages]').getAttribute('aria-busy')==='false' && !document.querySelector('[data-input]').disabled")
def page(case):
    run('open',base+'/?composer-ready='+case);run('snapshot','-i','-s','#gpante-support')
    js("""(()=>{const realFetch=window.fetch;const state=window.gpanteReadyTest={trace:[],active:0,maxActive:0,hold:true};window.fetch=async function(url,options){if(!String(url).includes('/gpante-support/v1/chat'))return realFetch(url,options);const action=JSON.parse(options.body).action;state.trace.push(action+':start');state.active++;state.maxActive=Math.max(state.maxActive,state.active);try{const response=await realFetch(url,options);if(action==='loadPreviousSession'&&state.hold){state.hold=false;await new Promise(resolve=>{state.release=resolve;state.waiting=true})}return response}finally{state.active--;state.trace.push(action+':end')}}})()""")
    run('click','#gpante-support [data-launcher]')
def release():
    run('wait','--fn','window.gpanteReadyTest.waiting===true')
    js('window.gpanteReadyTest.release()')

original=json.loads(wp("echo wp_json_encode(['mode'=>get_option('gptest_backend_mode','success'),'delay'=>get_option('gptest_history_delay_ms',false)]);"))
try:
    wp("update_option('gptest_backend_mode','success',false);update_option('gptest_history_delay_ms',1500,false);")
    page('early-send')
    assert js("!document.querySelector('[data-input]').disabled && document.activeElement===document.querySelector('[data-input]')")
    assert js("document.querySelector('[data-messages]').getAttribute('aria-busy')")=='true'
    run('fill','#gpante-support [data-input]','پیام زودهنگام آزمایشی')
    assert js("!document.querySelector('[data-send]').disabled")
    run('screenshot',str(evidence/'composer-immediate-typing.png'))
    run('press','Enter')
    js("document.querySelector('[data-form]').dispatchEvent(new Event('submit',{cancelable:true}))")
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start']
    assert js("document.querySelector('[data-input]').value")=='پیام زودهنگام آزمایشی'
    assert js("document.querySelector('[data-status]').textContent.includes('آمادهٔ ارسال')")
    run('click','#gpante-support [data-close]');run('click','#gpante-support [data-launcher]')
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start']
    release();ready()
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start','loadPreviousSession:end','sendMessage:start','sendMessage:end']
    assert js('window.gpanteReadyTest.maxActive')==1
    assert js("document.querySelectorAll('.gpante-chat-message--user').length")==1
    assert js("document.querySelector('[data-messages]').textContent.includes('پاسخ آزمایشی: پیام زودهنگام آزمایشی')")
    assert js("document.activeElement===document.querySelector('[data-input]')")
    run('reload');run('snapshot','-i','-s','#gpante-support');ready()
    assert js("document.querySelectorAll('.gpante-chat-message--user').length")==1
    assert js("document.querySelector('[data-messages]').textContent.includes('پیام زودهنگام آزمایشی')")
    print('PASS immediate focused typing, exactly one serialized early send, close/reopen and persisted signed session',flush=True)

    page('draft-only');run('fill','#gpante-support [data-input]','پیش‌نویس هنگام خواندن تاریخچه')
    release();ready()
    assert js("document.querySelector('[data-input]').value")=='پیش‌نویس هنگام خواندن تاریخچه'
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start','loadPreviousSession:end']
    print('PASS history rendering preserves an unsent draft and never dispatches it automatically',flush=True)

    wp("update_option('gptest_backend_mode','network',false);")
    page('connection-error');run('fill','#gpante-support [data-input]','پرسش محفوظ پس از قطع اتصال');run('press','Enter')
    release();ready()
    assert js("document.querySelector('[data-input]').value")=='پرسش محفوظ پس از قطع اتصال'
    assert js("!document.querySelector('[data-retry]').hidden && !document.querySelector('[data-retry]').disabled")
    assert js("document.querySelector('#gpante-support').dataset.error")=='true'
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start','loadPreviousSession:end']
    wp("update_option('gptest_backend_mode','success',false);")
    run('click','#gpante-support [data-retry]');ready()
    assert js('window.gpanteReadyTest.trace')==['loadPreviousSession:start','loadPreviousSession:end','loadPreviousSession:start','loadPreviousSession:end','sendMessage:start','sendMessage:end']
    assert js('window.gpanteReadyTest.maxActive')==1
    assert js("document.querySelector('[data-messages]').textContent.includes('پاسخ آزمایشی: پرسش محفوظ پس از قطع اتصال')")
    print('PASS failed initialization preserves the message; one explicit retry reconnects and sends once',flush=True)
    for width,height in [(1440,1000),(390,844),(844,390)]:
        run('set','viewport',str(width),str(height));run('scrollintoview','#gpante-support [data-input]')
        r=js("document.querySelector('#gpante-support-panel').getBoundingClientRect().toJSON()")
        assert r['left']>=0 and r['right']<=width and r['top']>=0 and r['bottom']<=height
    audit=run('a11y','--selector','#gpante-support');assert not audit.get('violations',[]),audit.get('violations')
    assert not run('errors').get('errors',[])
    print('PASS three viewport sizes, zero scoped accessibility violations and runtime errors; no model calls',flush=True)
finally:
    wp("update_option('gptest_backend_mode',"+json.dumps(original['mode'])+",false);"+("delete_option('gptest_history_delay_ms');" if original['delay'] is False else "update_option('gptest_history_delay_ms',"+str(int(original['delay']))+",false);"))
    run('close')
