"""Real WordPress/Chrome layout checks; all messages/settings are loopback fixtures."""
import json
import os
import subprocess
from pathlib import Path

root=Path('/home/codexops/n8n/.gpante-wp-test')
base='https://127.0.0.1:9443'
browser='/home/codexops/n8n/.tools/agent-browser'
session='gpante-layout-041'
env=dict(os.environ,AGENT_BROWSER_DEFAULT_TIMEOUT='45000')
evidence=root/'evidence'

def run(*args):
    result=subprocess.run([browser,'--session',session,'--ignore-https-errors','--json',*args],capture_output=True,text=True,env=env,timeout=60)
    assert result.returncode==0,result.stderr[:800] or result.stdout[:800]
    data=json.loads(result.stdout);assert data['success'],data.get('error');return data['data']
def js(code):return run('eval',code)['result']
def wp(code):
    return subprocess.check_output(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'eval',code],text=True)
def ready():run('wait','--fn',"!document.querySelector('#gpante-support [data-input]').disabled")
def configure(count):
    wp("$a=gpante_support_appearance_defaults();foreach($a['links'] as $i=>&$link){$link=['label'=>$i<"+str(count)+"?'راهنمای '.($i+1):'','url'=>'/?guide='.($i+1)];}unset($link);update_option('gpante_support_appearance',$a,false);update_option('gptest_backend_mode','success',false);")
    run('open',base+'/?layout-count='+str(count));run('snapshot','-i','-s','#gpante-support')
    run('click','#gpante-support [data-launcher]');ready()
    run('wait','--fn',f"document.querySelectorAll('[data-shortcuts] a').length==={count}")
def layout():
    return js("""(()=>{const rect=s=>document.querySelector(s).getBoundingClientRect().toJSON(),nav=document.querySelector('[data-shortcuts]'),p=document.querySelector('.gpante-chat-composer'),r=rect('#gpante-support-panel'),input=rect('[data-input]'),send=rect('[data-send]'),icon=rect('[data-send] svg');return {panel:r,nav:nav.getBoundingClientRect().toJSON(),links:[...nav.children].map(e=>e.getBoundingClientRect().toJSON()),gap:parseFloat(getComputedStyle(nav).columnGap),messages:rect('[data-messages]'),welcome:document.querySelector('.gpante-chat-message--welcome')?rect('.gpante-chat-message--welcome'):null,sendCenter:send.top+send.height/2,inputCenter:input.top+input.height/2,iconCenter:icon.top+icon.height/2,padding:parseFloat(getComputedStyle(p).paddingLeft),noHorizontalOverflow:document.querySelector('[data-scroll]').scrollWidth<=document.querySelector('[data-scroll]').clientWidth}})()""")
def assert_links(data,count):
    for i,r in enumerate(data['links']):
        expected=data['nav']['width'] if count%2 and i==count-1 else (data['nav']['width']-data['gap'])/2
        assert abs(r['width']-expected)<1,(count,i,r['width'],expected)

def window_checks(data,width,height):
    expected=(374,650) if width==390 else ((390,366) if height==390 else (390,650))
    assert abs(data['panel']['width']-expected[0])<1 and abs(data['panel']['height']-expected[1])<1
    assert data['panel']['left']>=0 and data['panel']['right']<=width and data['panel']['top']>=0 and data['panel']['bottom']<=height
    assert abs(data['sendCenter']-data['inputCenter'])<1,data
    assert abs(data['sendCenter']-data['iconCenter'])<1,data
    assert data['padding']>=6 and data['noHorizontalOverflow']

try:
    run('open',base+'/wp-login.php');run('snapshot','-i')
    run('fill','#user_login','gpante_admin');run('fill','#user_pass',(root/'admin-password').read_text());run('click','#wp-submit');run('wait','--load','networkidle')
    run('open',base+'/wp-admin/admin.php?page=gpante-support-appearance');run('snapshot','-i')
    assert js("document.querySelector('#gpante-appearance-send')===null")
    assert js("document.querySelector('#gpante-appearance-input_label')!==null")
    before=wp("echo hash('sha256',serialize(get_option('gpante_support_appearance')));")
    run('click','#submit');run('wait','--load','networkidle')
    assert wp("echo hash('sha256',serialize(get_option('gpante_support_appearance')));")==before
    print('PASS native settings hide only send-name field; unchanged save preserves appearance and accessible name',flush=True)
    configure(1)
    for width,height in [(1440,1000),(390,844),(844,390)]:
        run('set','viewport',str(width),str(height));data=layout();window_checks(data,width,height);assert_links(data,1)
        assert abs(data['welcome']['width']-data['messages']['width'])<1
        run('click','#gpante-support [data-input]')
        assert js("getComputedStyle(document.querySelector('[data-input]')).outlineStyle")=='none'
        assert js("getComputedStyle(document.querySelector('.gpante-chat-composer')).boxShadow")!='none'
        js("document.querySelector('[data-input]').style.height='100px'")
        grown=layout();assert abs(grown['sendCenter']-grown['inputCenter'])<1
        js("document.querySelector('[data-input]').style.height=''")
        for selector in ('[data-input]','[data-send]','[data-close]','[data-shortcuts] a'):
            run('scrollintoview','#gpante-support '+selector)
            assert js("(()=>{const r=document.querySelector('#gpante-support "+selector+"').getBoundingClientRect(),p=document.querySelector('#gpante-support-panel').getBoundingClientRect();return r.top>=p.top&&r.bottom<=p.bottom})()")
        run('screenshot',str(evidence/f'layout-welcome-{width}x{height}.png'))
    print('PASS one link and welcome are 100%; send stays centered at normal/resized input heights; exact existing window dimensions retained',flush=True)
    run('set','viewport','1440','1000')
    for count in (2,3,4):
        configure(count);assert_links(layout(),count)
    print('PASS two/four links use balanced columns; final link in three-button layout fills its row',flush=True)
    run('fill','#gpante-support [data-input]','سلام');run('press','Enter');ready()
    for width,height in [(1440,1000),(390,844),(844,390)]:
        run('set','viewport',str(width),str(height));data=layout();window_checks(data,width,height)
        sides=js("(()=>{const r=s=>document.querySelector(s).getBoundingClientRect().toJSON();return {area:r('[data-messages]'),user:r('.gpante-chat-message--user'),assistant:r('.gpante-chat-message--assistant:not(.gpante-chat-message--welcome)')}})()")
        assert abs(sides['user']['right']-sides['area']['right'])<1,sides
        assert abs(sides['assistant']['left']-sides['area']['left'])<1,sides
        run('scrollintoview','#gpante-support [data-input]');run('screenshot',str(evidence/f'layout-messages-{width}x{height}.png'))
    print('PASS actual synthetic message rendering: user right, assistant left at all three viewports',flush=True)
    run('fill','#gpante-support [data-input]','متن بلند فارسی و English123 ' * 35);run('press','Enter');ready()
    assert layout()['noHorizontalOverflow']
    run('set','viewport','1440','1000')
    audit=run('a11y','--selector','#gpante-support');(evidence/'layout-a11y.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2));assert not audit.get('violations',[]),audit.get('violations')
    assert not run('errors').get('errors',[])
    print('PASS long mixed-direction text wraps; scoped accessibility has zero violations and no runtime errors',flush=True)
finally:
    wp("update_option('gpante_support_appearance',gpante_support_appearance_defaults(),false);")
    run('close')
