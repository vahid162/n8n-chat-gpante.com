"""Chrome: native appearance save, cached HTML refresh and mobile controls; local fixture only."""
import json
import os
import ssl
import subprocess
import urllib.request
from pathlib import Path
from wordpress_test_config import root, base, browser

session = os.environ.get('GPANTE_BROWSER_SESSION', 'gpante-appearance-ui')

def run(*args):
    result = subprocess.run([browser, '--session', session, '--ignore-https-errors', '--json', *args], text=True, capture_output=True, check=True)
    value = json.loads(result.stdout)
    assert value['success'], value
    return value['data']

def js(code):
    return run('eval', code)['result']

def runtime_hash():
    return subprocess.check_output(['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress'), 'eval', 'echo hash("sha256",serialize(get_option("gpante_support_runtime")));'], text=True)

def wp(code):
    return subprocess.check_output(['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress'), 'eval', code], text=True)

def color_values():
    return js("Object.fromEntries([...document.querySelectorAll('#gpante-appearance-colors input[type=color]')].map(input=>[input.id.replace('gpante-color-',''),input.value]))")

before = runtime_hash()
assert (root / '.gpante-test-owner').read_text() == 'gpante-isolated-wordpress-v1\n'
provider = root / 'wordpress/wp-content/mu-plugins/gptest-browser-site-palette.php'
provider_created = False
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
    assert js("document.querySelector('#gpante-color-mode').value") == 'site'
    js("const color=document.querySelector('#gpante-color-primary');color.value='#123456';color.dispatchEvent(new Event('input',{bubbles:true}))")
    assert js("document.querySelector('#gpante-color-mode').value") == 'custom'
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
    run('wait', '--fn', "!document.querySelector('#gpante-support [data-input]').disabled && document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")
    assert js("document.querySelector('#gpante-support [data-messages]').textContent") == 'راهنمای فروشگاهسلام؛ چطور کمک کنم؟'
    assert js("document.querySelector('#gpante-support [data-input]').placeholder") == 'موضوع را بنویسید'
    assert js("document.querySelector('#gpante-support [data-policy]')===null")
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
    run('scrollintoview', '#gpante-support [data-messages]')
    audit = run('a11y', '--selector', '#gpante-support')
    assert not audit.get('violations', []), audit.get('violations')
    (root/'evidence'/'appearance-a11y.json').write_text(json.dumps(audit, ensure_ascii=False, indent=2))
    print('Saved scoped accessibility audit for inspection')
    run('open', base + '/wp-admin/admin.php?page=gpante-support-appearance')
    run('snapshot', '-i')
    assert not provider.exists(), 'Never overwrite an existing palette provider'
    provider.write_text("<?php\nfunction woodmart_get_opt($key){return get_option('gptest_browser_site_palette',[])[$key]??null;}\n")
    provider_created = True
    wp('update_option("gptest_browser_site_palette",array("primary-color"=>array("idle"=>"#285846"),"secondary-color"=>array("idle"=>"#e4b23c"),"text-font"=>array(array("color"=>"#1d2e29")),"pages-background"=>array("color"=>"#f5f7f2")),false);')
    manual = dict(primary='#123456', on_primary='#ffffff', surface='#f8f9fa', text='#101820',
                  messages='#eef2f5', user='#dce6ed', assistant='#ffffff', accent='#b34d00')
    js("for(const [key,value] of Object.entries(" + json.dumps(manual) + ")){const input=document.getElementById('gpante-color-'+key);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}")
    run('click', '#submit'); run('wait', '--load', 'networkidle')
    assert color_values() == manual
    run('select', '#gpante-color-mode', 'site')
    assert color_values() == js("JSON.parse(document.getElementById('gpante-appearance-colors').dataset.siteColors)")
    run('click', '#submit'); run('wait', '--load', 'networkidle')
    assert json.loads(wp('echo wp_json_encode(get_option("gpante_support_appearance")["colors"]);')) == manual
    wp('$p=get_option("gptest_browser_site_palette");$p["primary-color"]["idle"]="#381b56";$p["text-font"][0]["color"]="#1b1420";$p["pages-background"]["color"]="#f1eef4";update_option("gptest_browser_site_palette",$p,false);$a=get_option("gpante_support_appearance");$a["colors"]["unowned"]="fixture-only-unowned-color";update_option("gpante_support_appearance",$a,false);')
    run('reload'); run('wait', '--load', 'networkidle')
    assert color_values()['primary'] == '#381b56'
    run('select', '#gpante-color-mode', 'custom')
    assert color_values() == manual, 'Selecting manual after saving site mode must restore all eight saved manual colors, even after the site palette changes'
    assert js("JSON.parse(document.getElementById('gpante-appearance-colors').dataset.manualColors)") == manual
    run('click', '#submit'); run('wait', '--load', 'networkidle')
    assert color_values() == manual
    draft = {**manual, 'primary': '#223344', 'user': '#cce0ef'}
    js("for(const [key,value] of Object.entries(" + json.dumps(draft) + ")){const input=document.getElementById('gpante-color-'+key);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}")
    for _ in range(2):
        run('select', '#gpante-color-mode', 'site')
        run('select', '#gpante-color-mode', 'custom')
        assert color_values() == draft, 'Same-page source toggles must retain the whole manual draft'
    run('click', '#submit'); run('wait', '--load', 'networkidle')
    assert json.loads(wp('echo wp_json_encode(get_option("gpante_support_appearance")["colors"]);')) == draft
    assert runtime_hash() == before
    print('PASS all eight manual colors survive saved site mode, source changes and repeated draft toggles')
    run('click', 'button[name="gpante_support_appearance[restore_defaults]"]')
    assert run('dialog', 'status')['type'] == 'confirm'
    run('dialog', 'accept')
    run('wait', '--load', 'networkidle')
    run('snapshot', '-i')
    assert js("document.querySelector('#gpante-appearance-title').value") == 'دستیار پانته'
    assert color_values() == js("JSON.parse(document.getElementById('gpante-appearance-colors').dataset.siteColors)")
    assert js("document.querySelector('#gpante-color-mode').value") == 'site'
    assert runtime_hash() == before
    print('PASS native restore defaults in Chrome preserves runtime settings')
finally:
    if provider_created:
        provider.unlink()
        wp('delete_option("gptest_browser_site_palette");')
    cached.unlink(missing_ok=True)
    run('close')
