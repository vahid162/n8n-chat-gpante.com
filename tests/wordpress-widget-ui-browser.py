"""Real Chrome checks for composer, optional links and navigation; loopback fixture only."""
import json
import os
import ssl
import subprocess
import urllib.request
from pathlib import Path

root=Path('/home/codexops/n8n/.gpante-wp-test')
base='https://127.0.0.1:9443'
browser='/home/codexops/n8n/.tools/agent-browser'
session='gpante-widget-ui-040'

def run(*args):
    result=subprocess.run([browser,'--session',session,'--ignore-https-errors','--json',*args],capture_output=True,text=True,timeout=60)
    assert result.returncode==0,result.stderr[:800] or result.stdout[:800]
    data=json.loads(result.stdout);assert data['success'],data.get('error');return data['data']
def js(code):return run('eval',code)['result']
def ready():run('wait','--fn',"!document.querySelector('#gpante-support [data-input]').disabled && document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")
def open_chat():
    run('snapshot','-i','-s','#gpante-support')
    run('click','#gpante-support [data-launcher]');ready()
def admin_page():
    run('open',base+'/wp-admin/admin.php?page=gpante-support-appearance');run('snapshot','-i')
def save():
    run('click','#submit');run('wait','--load','networkidle');run('snapshot','-i')
def previous_then_current():
    run('open',base+'/?stage=previous');run('open',base+'/widget-ui-cached.html');open_chat()

cached=root/'wordpress/widget-ui-cached.html'
with urllib.request.urlopen(base,context=ssl._create_unverified_context()) as response:cached.write_bytes(response.read())
try:
    run('open',base+'/wp-login.php');run('snapshot','-i')
    run('fill','#user_login','gpante_admin');run('fill','#user_pass',(root/'admin-password').read_text())
    run('click','#wp-submit');run('wait','--load','networkidle');admin_page()
    for i,label in enumerate(['پیدا کردن طرح 🔎','فایل‌های رایگان 🎁','طراحی اختصاصی ✏️','راهنمای خرید 🛒']):
        run('fill',f'#gpante-link-label-{i}',label)
        run('fill',f'#gpante-link-url-{i}',f'/?guide={i+1}')
    save();previous_then_current()
    run('wait','--fn',"document.querySelectorAll('[data-shortcuts] a').length===4")
    assert js("document.querySelector('[data-policy]')===null")
    assert js("getComputedStyle(document.querySelector('[data-input-label]')).clipPath")=='inset(50%)'
    assert js("document.querySelector('[data-send] svg')!==null")
    assert js("document.querySelector('[data-send]').getAttribute('aria-label')")=='ارسال پیام'
    run('click','#gpante-support [data-input]')
    assert js("getComputedStyle(document.querySelector('[data-input]')).outlineStyle")=='none'
    assert js("getComputedStyle(document.querySelector('.gpante-chat-composer')).boxShadow")!='none'
    assert js("(()=>{const c=document.querySelector('.gpante-chat-composer').getBoundingClientRect(),s=document.querySelector('[data-send]').getBoundingClientRect();return s.top>=c.top&&s.bottom<=c.bottom&&s.left>=c.left&&s.right<=c.right})()")
    print('PASS four native configured links on cached HTML, removed notice/visible label, soft focus and send inside composer',flush=True)
    for width,height,expected_w,expected_h in [(1440,1000,390,650),(390,844,374,650),(844,390,390,366)]:
        run('set','viewport',str(width),str(height))
        rect=js("document.querySelector('#gpante-support-panel').getBoundingClientRect().toJSON()")
        assert abs(rect['width']-expected_w)<1 and abs(rect['height']-expected_h)<1,(width,height,rect)
        assert rect['left']>=0 and rect['right']<=width and rect['top']>=0 and rect['bottom']<=height
        for selector in ('[data-input]','[data-send]','[data-close]','[data-shortcuts] a:last-child'):
            run('scrollintoview','#gpante-support '+selector)
            assert js("(()=>{const e=document.querySelector('#gpante-support "+selector+"'),r=e.getBoundingClientRect(),p=document.querySelector('#gpante-support-panel').getBoundingClientRect();return r.top>=p.top&&r.bottom<=p.bottom&&r.top>=0&&r.bottom<=innerHeight})()"),(width,height,selector)
        run('screenshot',str(root/'evidence'/f'widget-ui-{width}x{height}.png'))
    print('PASS exact existing window dimensions preserved at desktop, portrait and landscape; all controls reachable',flush=True)
    run('back');run('wait','--fn',"document.querySelector('#gpante-support-panel').hidden")
    assert run('get','url')['url'].endswith('/widget-ui-cached.html')
    run('back');assert run('get','url')['url'].endswith('/?stage=previous')
    run('forward');run('forward');ready()
    assert not js("document.querySelector('#gpante-support-panel').hidden")
    run('reload');ready()
    assert not js("document.querySelector('#gpante-support-panel').hidden")
    run('back');run('wait','--fn',"document.querySelector('#gpante-support-panel').hidden")
    run('back');assert run('get','url')['url'].endswith('/?stage=previous')
    print('PASS real browser Back closes, second Back navigates; Forward/reload restores without dead history steps',flush=True)
    previous_then_current();run('scrollintoview','#gpante-support [data-close]');run('click','#gpante-support [data-close]')
    run('wait','--fn',"!history.state?.gpanteSupportChat")
    run('back');assert run('get','url')['url'].endswith('/?stage=previous')
    run('set','viewport','1440','1000');previous_then_current()
    js("const b=document.createElement('button');b.id='outside-close-test';b.textContent='بیرون چت';b.style='position:fixed;top:20px;left:20px;z-index:999999';document.body.append(b)")
    run('fill','#gpante-support [data-input]','پیش‌نویس محفوظ')
    run('click','#outside-close-test');run('wait','--fn',"!history.state?.gpanteSupportChat")
    assert js("document.querySelector('#gpante-support-panel').hidden")
    assert js("document.activeElement.id")=='outside-close-test'
    open_chat();assert js("document.querySelector('[data-input]').value")=='پیش‌نویس محفوظ'
    # Outside links must still navigate normally when dismissing the panel.
    js("const a=document.createElement('a');a.id='outside-link-test';a.href='/?stage=destination';a.textContent='صفحه بعد';a.style='position:fixed;top:80px;left:20px;z-index:999999';document.body.append(a)")
    run('click','#outside-link-test');run('wait','--load','networkidle')
    assert run('get','url')['url'].endswith('/?stage=destination')
    print('PASS X and outside click close, preserve draft/focus and do not intercept outside link navigation',flush=True)
    admin_page();run('fill','#gpante-link-label-1','');save()
    run('open',base+'/widget-ui-cached.html');open_chat()
    run('wait','--fn',"document.querySelectorAll('[data-shortcuts] a').length===3")
    assert not js("document.querySelector('[data-shortcuts]').textContent.includes('فایل‌های رایگان')")
    run('set','viewport','1440','1000')
    audit=run('a11y','--selector','#gpante-support')
    (root/'evidence'/'widget-ui-a11y.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2))
    assert not audit.get('violations',[]),audit.get('violations')
    assert not run('errors').get('errors',[])
    admin_page();run('click','button[name="gpante_support_appearance[restore_defaults]"]');run('wait','--load','networkidle')
    run('open',base+'/widget-ui-cached.html');open_chat()
    run('wait','--fn',"document.querySelector('[data-shortcuts]').hidden")
    print('PASS blank labels hide individually; reset hides all four; scoped accessibility has zero violations',flush=True)
finally:
    cached.unlink(missing_ok=True)
    run('close')
