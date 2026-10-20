"""Run against the isolated HTTPS test install. No production credentials or URLs."""
import http.cookiejar, ssl, urllib.request, urllib.parse, urllib.error, re, subprocess, json
from pathlib import Path
root=Path('/home/codexops/n8n/.gpante-wp-test')
base='https://127.0.0.1:9443'
ctx=ssl._create_unverified_context() # Self-signed loopback fixture only.
def client(user,password):
 jar=http.cookiejar.CookieJar(); op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),urllib.request.HTTPSHandler(context=ctx))
 op.open(base+'/wp-login.php').read()
 op.open(base+'/wp-login.php',urllib.parse.urlencode({'log':user,'pwd':password,'wp-submit':'Log In','testcookie':'1','redirect_to':base+'/wp-admin/'}).encode()).read()
 assert any('wordpress_logged_in_' in c.name for c in jar), 'test login failed: '+user
 return op
def submit(op,nonce):
 body=urllib.parse.urlencode({'option_page':'gpante_support','action':'update','_wpnonce':nonce,'_wp_http_referer':'/wp-admin/options-general.php?page=gpante-support-chat','gpante_support_runtime[enabled]':'1','gpante_support_runtime[shared_key]':''}).encode()
 try:
  res=op.open(urllib.request.Request(base+'/wp-admin/options.php',data=body));return res.status,res.read().decode(),res.url
 except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.url
admin=client('gpante_admin',(root/'admin-password').read_text())
html=admin.open(base+'/wp-admin/options-general.php?page=gpante-support-chat').read().decode()
assert 'fixture-only-' not in html, 'stored secret exposed'
nonce=re.search(r'name="_wpnonce" value="([^"]+)"',html).group(1)
assert submit(admin,'invalid-nonce')[0]==403
print('PASS actual admin HTTP rejects invalid nonce')
assert submit(admin,nonce)[0]==200
html=admin.open(base+'/wp-admin/options-general.php?page=gpante-support-chat').read().decode()
assert re.search(r'name="gpante_support_runtime\[enabled\]"[^>]*checked',html), 'repeated sanitization disabled chat'
assert 'کلید ذخیره شده است' in html and 'fixture-only-' not in html
print('PASS actual admin HTTP save keeps blank key and hides stored secret')
sub=client('gpante_subscriber','local-fixture-subscriber-only')
def stored():
 return subprocess.check_output(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'eval','echo hash("sha256",serialize(get_option("gpante_support_runtime")));'],text=True)
before=stored()
status,body,url=submit(sub,nonce)
assert status==403 or ('/wp-admin/' not in url and 'name="gpante_support_runtime[' not in body), (status,url)
assert stored()==before, 'subscriber changed config'
print('PASS actual subscriber HTTP cannot save settings even with an admin nonce')
try:
 res=sub.open(base+'/wp-admin/options-general.php?page=gpante-support-chat')
 assert '/wp-admin/' not in res.url and 'name="gpante_support_runtime[' not in res.read().decode(), 'subscriber accessed settings'
except urllib.error.HTTPError as e:assert e.code==403
print('PASS actual subscriber HTTP cannot access settings page')
