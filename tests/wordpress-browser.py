"""Real Chrome checks through agent-browser, against the isolated mock WP site."""
import json, os, subprocess
from pathlib import Path
root=Path(os.environ.get('GPANTE_WP_TEST_DIR','/home/codexops/n8n/.gpante-wp-test'))
browser=os.environ.get('AGENT_BROWSER_BIN','/home/codexops/n8n/.tools/agent-browser')
session=os.environ.get('AGENT_BROWSER_TEST_SESSION','gpante-plugin-archive-local'); evidence=root/'evidence';evidence.mkdir(exist_ok=True)
def run(*args):
 res=subprocess.run([browser,'--session',session,'--ignore-https-errors','--json',*args],text=True,capture_output=True,check=True)
 data=json.loads(res.stdout);assert data['success'],data
 return data['data']
def js(code):return run('eval',code)['result']
def ready():run('wait','--fn',"!document.querySelector('#gpante-support [data-input]').disabled")
def mode(value):subprocess.run(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'option','update','gptest_backend_mode',value],check=True,capture_output=True)
def message(text):run('fill','#gpante-support [data-input]',text);run('press','Enter');ready()
mode('success'); run('open','https://127.0.0.1:9443');run('snapshot','-i');run('click','#gpante-support [data-launcher]');ready()
message('آزمون مرورگر واقعی')
assert 'پاسخ آزمایشی: آزمون مرورگر واقعی' in js("document.querySelector('#gpante-support [data-messages]').textContent")
assert js("document.querySelector('#gpante-support [data-messages] a').href")=='https://gpante.com/product/local-test/'
print('PASS real WordPress bridge send, safe link, Persian reply')
# Same signed browser cookie restores history across a real navigation.
run('reload');run('snapshot','-i')
if js("document.querySelector('#gpante-support-panel').hidden"):run('click','#gpante-support [data-launcher]')
ready()
assert 'آزمون مرورگر واقعی' in js("document.querySelector('#gpante-support [data-messages]').textContent")
print('PASS real persisted session reload')
mode('error');message('پیش‌نویس پس از خطا')
assert js("document.querySelector('#gpante-support [data-input]').value")=='پیش‌نویس پس از خطا'
assert not js("document.querySelector('#gpante-support [data-retry]').hidden")
mode('success');run('snapshot','-i');run('click','#gpante-support [data-retry]');ready()
assert 'پاسخ آزمایشی: پیش‌نویس پس از خطا' in js("document.querySelector('#gpante-support [data-messages]').textContent")
print('PASS real failed send preserves draft and explicit retry succeeds')
geometry=[]
for width,height,label in [(1440,1000,'desktop'),(390,844,'mobile-portrait'),(844,390,'mobile-landscape')]:
 run('set','viewport',str(width),str(height))
 rect=js("document.querySelector('#gpante-support-panel').getBoundingClientRect().toJSON()")
 assert rect['top']>=0 and rect['bottom']<=height and rect['left']>=0 and rect['right']<=width,(label,rect)
 for selector in ['[data-input]','[data-send]','[data-new]','[data-close]']:
  run('scrollintoview','#gpante-support '+selector)
  assert js("(()=>{const e=document.querySelector('#gpante-support "+selector+"'),r=e.getBoundingClientRect(),p=document.querySelector('#gpante-support-panel').getBoundingClientRect();return r.top>=p.top && r.bottom<=p.bottom && r.top>=0 && r.bottom<=innerHeight})()"),(label,selector)
 run('scrollintoview','#gpante-support [data-input]')
 run('screenshot',str(evidence/(label+'.png')))
 geometry.append({'viewport':[width,height],'panel':rect,'controls_reachable':True})
 print('PASS '+label+' panel fits viewport and all controls are reachable')
# Short-height reset confirmation is also reachable by panel scrolling.
run('scrollintoview','#gpante-support [data-new]');run('click','#gpante-support [data-new]');run('snapshot','-i')
assert not js("document.querySelector('#gpante-support [data-confirm]').hidden")
run('press','Escape');assert js("document.querySelector('#gpante-support [data-confirm]').hidden")
run('click','#gpante-support [data-new]');run('snapshot','-i');run('scrollintoview','#gpante-support [data-clear]');run('click','#gpante-support [data-clear]');ready()
assert 'آزمون مرورگر واقعی' not in js("document.querySelector('#gpante-support [data-messages]').textContent")
run('press','Escape');run('wait','--load','networkidle');run('wait','--fn',"document.querySelector('#gpante-support-panel')?.hidden===true");assert js("document.querySelector('#gpante-support-panel').hidden")
print('PASS reset confirmation, Escape cancellation, successful delete and keyboard close')
assert js("document.querySelectorAll('#gpante-support').length")==1
assert 'fixture-only-' not in js('document.documentElement.innerHTML')
errors=run('errors');print('Browser runtime errors:',json.dumps(errors,ensure_ascii=False))
(evidence/'browser-result.json').write_text(json.dumps({'geometry':geometry,'runtime_errors':errors,'backend':'synthetic local only'},ensure_ascii=False,indent=2))
