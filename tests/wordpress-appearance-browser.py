"""Chrome: native appearance save, cached HTML refresh and mobile controls; local fixture only."""
import json
import os
import ssl
import subprocess
import urllib.request
from pathlib import Path

root = Path('/home/codexops/n8n/.gpante-wp-test')
base = 'https://127.0.0.1:9443'
browser = '/home/codexops/n8n/.tools/agent-browser'
session = 'gpante-appearance-ui'

def run(*args):
    result = subprocess.run([browser, '--session', session, '--ignore-https-errors', '--json', *args], text=True, capture_output=True, check=True)
    value = json.loads(result.stdout)
    assert value['success'], value
    return value['data']

def js(code):
    return run('eval', code)['result']

def runtime_hash():
    return subprocess.check_output(['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress'), 'eval', 'echo hash("sha256",serialize(get_option("gpante_support_runtime")));'], text=True)

before = runtime_hash()
cached = root / 'wordpress' / 'appearance-cached.html'
with urllib.request.urlopen(base, context=ssl._create_unverified_context()) as response:
    cached.write_bytes(response.read())
try:
    run('open', base + '/wp-login.php')
    run('snapshot', '-i')
    run('fill', '#user_login', 'gpante_admin')
    run('fill', '#user_pass', (root / 'admin-password').read_text())
    run('click', '#wp-submit')
    run('wait', '--load', 'networkidle')
    run('open', base + '/wp-admin/admin.php?page=gpante-support-appearance')
    run('snapshot', '-i')
    assert js("document.querySelector('#adminmenu').textContent.includes('ظاهر')")
    run('fill', '#gpante-appearance-title', 'راهنمای فروشگاه')
    run('fill', '#gpante-appearance-launcher', 'پرسش دارید؟')
    run('fill', '#gpante-appearance-welcome', 'سلام؛ چطور کمک کنم؟')
    run('fill', '#gpante-appearance-placeholder', 'موضوع را بنویسید')
    js("document.querySelector('#gpante-color-primary').value='#123456'")
    run('click', '#submit')
    run('wait', '--load', 'networkidle')
    run('snapshot', '-i')
    assert js("document.querySelector('#gpante-appearance-title').value") == 'راهنمای فروشگاه'
    assert js("document.querySelector('#gpante-color-primary').value") == '#123456'
    assert runtime_hash() == before
    print('PASS real Chrome native admin form saves text/color and preserves private connection')
    run('open', base + '/appearance-cached.html')
    run('wait', '--fn', "document.querySelector('#gpante-support-title').textContent==='راهنمای فروشگاه'")
    run('snapshot', '-i')
    assert js("document.querySelector('[data-launcher-label]').textContent") == 'پرسش دارید؟'
    assert js("getComputedStyle(document.querySelector('[data-launcher]')).backgroundColor") == 'rgb(18, 52, 86)'
    run('click', '#gpante-support [data-launcher]')
    run('wait', '--fn', "!document.querySelector('#gpante-support [data-input]').disabled")
    assert js("document.querySelector('#gpante-support [data-messages]').textContent") == 'راهنمای فروشگاهسلام؛ چطور کمک کنم؟'
    assert js("document.querySelector('#gpante-support [data-input]').placeholder") == 'موضوع را بنویسید'
    assert '۳۰' in js("document.querySelector('#gpante-support [data-policy]').textContent")
    print('PASS cached HTML gets fresh public appearance, matching launcher/header/welcome/placeholder, with authoritative policy')
    for width, height in [(1440,1000),(390,844),(844,390)]:
        run('set', 'viewport', str(width), str(height))
        rect = js("document.querySelector('#gpante-support-panel').getBoundingClientRect().toJSON()")
        assert rect['top'] >= 0 and rect['left'] >= 0 and rect['bottom'] <= height and rect['right'] <= width
        for selector in ['[data-input]', '[data-send]', '[data-new]', '[data-close]']:
            run('scrollintoview', '#gpante-support ' + selector)
            assert js("(()=>{const e=document.querySelector('#gpante-support "+selector+"'),r=e.getBoundingClientRect(),p=document.querySelector('#gpante-support-panel').getBoundingClientRect();return r.top>=p.top&&r.bottom<=p.bottom&&r.top>=0&&r.bottom<=innerHeight})()")
        run('screenshot', str(root/'evidence'/f'appearance-{width}x{height}.png'))
    assert not run('errors').get('errors', [])
    print('PASS custom appearance fits desktop, portrait and landscape; controls reachable; no runtime errors')
    run('set', 'viewport', '1440', '1000')
    run('scrollintoview', '#gpante-support [data-policy]')
    audit = run('a11y', '--selector', '#gpante-support')
    assert not audit.get('violations', []), audit.get('violations')
    (root/'evidence'/'appearance-a11y.json').write_text(json.dumps(audit, ensure_ascii=False, indent=2))
    print('Saved scoped accessibility audit for inspection')
    run('open', base + '/wp-admin/admin.php?page=gpante-support-appearance')
    run('snapshot', '-i')
    run('click', 'button[name="gpante_support_appearance[restore_defaults]"]')
    run('wait', '--load', 'networkidle')
    run('snapshot', '-i')
    assert js("document.querySelector('#gpante-appearance-title').value") == 'دستیار پانته'
    assert js("document.querySelector('#gpante-color-primary').value") == '#365e28'
    assert runtime_hash() == before
    print('PASS native restore defaults in Chrome preserves runtime settings')
finally:
    cached.unlink(missing_ok=True)
    run('close')
