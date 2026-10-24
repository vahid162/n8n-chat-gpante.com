"""Actual WordPress capability/nonce/policy checks on the isolated loopback fixture only."""
import runpy, re, urllib.parse, urllib.request, urllib.error, subprocess, json
from pathlib import Path
from wordpress_test_config import root, base, browser
m=runpy.run_path(str(Path(__file__).with_name('wordpress-settings-http.py')))
admin, sub, base=m['admin'],m['sub'],m['base']
def wp(code):
 return subprocess.check_output(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'eval',code],text=True)
def post(client,fields):
 try:
  r=client.open(base+'/wp-admin/admin-post.php',urllib.parse.urlencode(fields).encode());return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
url=base+'/wp-admin/admin.php?page=gpante-support-conversations'
html=admin.open(url).read().decode()
assert 'مدت نمایش تاریخچه برای همه' in html and 'fixture-admin-only-' not in html
nonce=re.search(r'name="_wpnonce" value="([^"]+)"',html).group(1)
revision=re.search(r'name="revision" value="([^"]+)"',html).group(1)
fields={'action':'gpante_support_policy','user_days':'7','revision':revision,'_wpnonce':nonce}
before=wp('echo wp_json_encode(get_option("gptest_policy"));')
assert post(admin,{**fields,'_wpnonce':'invalid'})[0]==403
assert post(sub,fields)[0]==403
assert wp('echo wp_json_encode(get_option("gptest_policy"));')==before
print('PASS policy mutation rejects invalid nonce and subscriber, even with administrator nonce')
saved=post(admin,fields)
assert saved[0]==200,(saved[0],re.sub('<[^>]+>',' ',saved[1])[-1800:])
p=json.loads(wp('echo wp_json_encode(get_option("gptest_policy"));'))
assert p['userDays']==7 and p['allowClear'] is False
assert post(admin,fields)[0]==502
assert json.loads(wp('echo wp_json_encode(get_option("gptest_policy"));'))==p
print('PASS central policy saved; stale revision fails visibly without overwriting')
wp('$c=get_option("gpante_support_runtime");$c["enabled"]=false;update_option("gpante_support_runtime",$c,false);')
assert 'مدت نمایش تاریخچه برای همه' in admin.open(url).read().decode()
print('PASS administrator archive remains available with public chat disabled')
html=admin.open(url+'&session='+'a'*64).read().decode()
assert '&lt;script&gt;window.gpanteStoredXss=1&lt;/script&gt;' in html
assert '<script>window.gpanteStoredXss=1</script>' not in html
print('PASS transcript escaped against stored XSS')
try:
 r=sub.open(url);assert '/wp-admin/' not in r.url and 'سیاست تاریخچه' not in r.read().decode()
except urllib.error.HTTPError as e:assert e.code==403
print('PASS non-administrator cannot read archive')
wp('$c=get_option("gpante_support_runtime");$c["enabled"]=true;update_option("gpante_support_runtime",$c,false);$p=get_option("gptest_policy");$p["userDays"]=30;$p["allowClear"]=true;update_option("gptest_policy",$p,false);')
