"""Native Settings API checks on the isolated HTTPS WordPress fixture only."""
import http.cookiejar
import json
import re
import ssl
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

root = Path('/home/codexops/n8n/.gpante-wp-test')
base = 'https://127.0.0.1:9443'
page = '/wp-admin/admin.php?page=gpante-support-appearance'
context = ssl._create_unverified_context()  # Self-signed loopback only.

def client(user, password):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), urllib.request.HTTPSHandler(context=context))
    op.open(base + '/wp-login.php').read()
    body = urllib.parse.urlencode({'log': user, 'pwd': password, 'testcookie': '1', 'redirect_to': base + '/'}).encode()
    op.open(base + '/wp-login.php', body).read()
    assert any('wordpress_logged_in_' in c.name for c in jar)
    return op

def runtime_hash():
    return subprocess.check_output(['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress'), 'eval', 'echo hash("sha256",serialize(get_option("gpante_support_runtime")));'], text=True)

def public():
    with urllib.request.urlopen(base + '/index.php?rest_route=/gpante-support/v1/appearance', context=context) as response:
        assert 'no-store' in response.headers['Cache-Control']
        data = json.load(response)
    assert set(data) == {'version', 'texts', 'colors'}
    assert len(data['texts']) == len(data['colors']) == 8
    assert 'fixture-only-' not in json.dumps(data)
    return data

def submit(op, nonce, config=None, reset=False):
    fields = {'option_page': 'gpante_support_appearance', 'action': 'update', '_wpnonce': nonce, '_wp_http_referer': page}
    if reset:
        fields['gpante_support_appearance[restore_defaults]'] = '1'
    else:
        for group in ('texts', 'colors'):
            fields.update({f'gpante_support_appearance[{group}][{key}]': value for key, value in config[group].items()})
    try:
        response = op.open(base + '/wp-admin/options.php', urllib.parse.urlencode(fields).encode())
        return response.status, response.read().decode(), response.url
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(), error.url

before = runtime_hash()
admin = client('gpante_admin', (root / 'admin-password').read_text())
html = admin.open(base + page).read().decode()
assert 'ظاهر چت پانته' in html and 'type="color"' in html
assert 'fixture-only-' not in html
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', html).group(1)
assert submit(admin, nonce, reset=True)[0] == 200
defaults = public()
custom = json.loads(json.dumps(defaults))
custom['texts']['title'] = 'راهنمای "پانته" & شما'
custom['texts']['welcome'] = 'سلام؛ این خوشامدگویی سفارشی است.'
custom['colors']['primary'] = '#123456'
assert submit(admin, 'invalid-nonce', custom)[0] == 403
assert public() == defaults
assert submit(admin, nonce, custom)[0] == 200
assert public() == custom
html = admin.open(base + page).read().decode()
assert 'راهنمای &quot;پانته&quot; &amp; شما' in html
print('PASS real Settings API: visible menu, valid save, escaped values, no-store public config and nonce rejection')
invalid = json.loads(json.dumps(custom))
invalid['colors']['primary'] = '#ffffff'
status, error_html, _ = submit(admin, nonce, invalid)
assert status == 200 and 'ظاهر ذخیره نشد' in error_html
assert public() == custom
invalid['colors']['primary'] = '#123456;display:none'
submit(admin, nonce, invalid)
assert public() == custom
invalid = json.loads(json.dumps(custom))
invalid['texts']['title'] = 'م' * 81
submit(admin, nonce, invalid)
assert public() == custom
print('PASS real WordPress: unreadable palette, CSS injection and oversized text preserve prior settings')
subscriber = client('gpante_subscriber', 'local-fixture-subscriber-only')
status, body, url = submit(subscriber, nonce, reset=True)
assert status == 403 or ('/wp-admin/' not in url and 'gpante_support_appearance[' not in body)
assert public() == custom
try:
    response = subscriber.open(base + page)
    assert '/wp-admin/' not in response.url and 'gpante_support_appearance[' not in response.read().decode()
except urllib.error.HTTPError as error:
    assert error.code == 403
print('PASS real subscriber cannot view or modify appearance')
assert submit(admin, nonce, reset=True)[0] == 200
assert public() == defaults
assert runtime_hash() == before
print('PASS native reset restores defaults; connection keys, enabled state and runtime option remain byte-for-byte unchanged')
