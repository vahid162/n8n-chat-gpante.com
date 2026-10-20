#!/usr/bin/env python3
"""Build a deterministic, runtime-only WordPress ZIP; never include the repo."""
from pathlib import Path
import hashlib, re, sys, zipfile
root=Path(__file__).resolve().parents[1]
source=root/'wordpress-plugin/gpante-support-chat'
files=('gpante-support-chat.php','includes/settings.php','includes/bridge.php','includes/catalog.php','templates/widget.php','assets/chat.js','assets/chat.css','readme.txt','uninstall.php','LICENSE.txt')
header=(source/files[0]).read_text()
version=re.search(r'^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$',header,re.M).group(1)
assert 'Stable tag: '+version in (source/'readme.txt').read_text(), 'readme/header version mismatch'
assert {str(f.relative_to(source)) for f in source.rglob('*') if f.is_file()}==set(files), 'unexpected or missing runtime files'
output=Path(sys.argv[1]) if len(sys.argv)>1 else root/'dist'/('gpante-support-chat-wordpress-'+version+'.zip')
assert source not in output.resolve().parents, 'output must be outside plugin source'
output.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_DEFLATED) as archive:
 for name in sorted(files):
  file=source/name
  assert file.is_file() and not file.is_symlink(), 'runtime sources must be regular files'
  info=zipfile.ZipInfo('gpante-support-chat/'+name,date_time=(2026,10,20,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
  archive.writestr(info,file.read_bytes())
with zipfile.ZipFile(output) as archive:
 assert archive.testzip() is None
 assert set(archive.namelist())=={'gpante-support-chat/'+f for f in files}
 for name in files: assert archive.read('gpante-support-chat/'+name)==(source/name).read_bytes()
print(str(output.resolve()))
print('SHA256 '+hashlib.sha256(output.read_bytes()).hexdigest()+'; '+str(len(files))+' runtime files; version '+version)
