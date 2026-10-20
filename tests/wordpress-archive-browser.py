"""Chrome administrator/user policy checks, isolated WordPress fixture only."""
import json, os, subprocess
from pathlib import Path
root=Path('/home/codexops/n8n/.gpante-wp-test')
browser='/home/codexops/n8n/.tools/agent-browser'
session='gpante-archive-admin-local'
def run(*args):
 r=subprocess.run([browser,'--session',session,'--ignore-https-errors','--json',*args],capture_output=True,text=True,env=dict(os.environ,AGENT_BROWSER_DEFAULT_TIMEOUT='45000'))
 if r.returncode:raise RuntimeError(r.stdout[:1000]+r.stderr[:300])
 v=json.loads(r.stdout);assert v['success'],v;return v['data']
def js(code):return run('eval',code)['result']
try:
 run('open','https://127.0.0.1:9443/wp-login.php');run('snapshot','-i')
 run('fill','#user_login','gpante_admin');run('fill','#user_pass',(root/'admin-password').read_text());run('click','#wp-submit');run('wait','--url','**/wp-admin/**');run('snapshot','-i')
 run('open','https://127.0.0.1:9443/wp-admin/admin.php?page=gpante-support-conversations');run('snapshot','-i')
 run('click','details > summary');run('select','#gpante-user-days','15');run('uncheck','input[name="allow_clear"]');run('click','form[action$="admin-post.php"] input[type="submit"]');run('wait','--load','networkidle');run('wait','#gpante-user-days');run('snapshot','-i')
 assert js('document.querySelector("#gpante-user-days").value')=='15'
 assert js('document.querySelector("input[name=allow_clear]").checked') is False
 print('PASS real administrator browser edits central duration and clear toggle')
 run('open','https://127.0.0.1:9443/wp-admin/admin.php?page=gpante-support-conversations&session='+'a'*64);run('snapshot','-i')
 assert js('window.gpanteStoredXss || null') is None
 assert '<script>window.gpanteStoredXss=1</script>' in js('document.body.textContent')
 print('PASS real browser displays hostile transcript as text')
 run('open','https://127.0.0.1:9443');run('snapshot','-i');run('click','#gpante-support [data-launcher]')
 run('wait','--fn','!document.querySelector("#gpante-support [data-input]").disabled')
 assert '۱۵ روز' in js('document.querySelector("#gpante-support [data-policy]").textContent')
 assert js('document.querySelector("#gpante-support [data-new]").hidden') is True
 print('PASS public browser receives administrator policy and hides clearing')
 assert not run('errors')['errors']
finally:
 subprocess.run(['php',str(root/'wp-cli.phar'),'--path='+str(root/'wordpress'),'eval','$p=get_option("gptest_policy");$p["userDays"]=30;$p["allowClear"]=true;update_option("gptest_policy",$p,false);'],check=True,capture_output=True)
 run('close')
