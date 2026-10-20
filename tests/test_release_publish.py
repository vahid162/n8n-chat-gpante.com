"""Exercise the actual publishing shell with an isolated, offline GitHub CLI."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / '.github/workflows/wordpress-release.yml'
MARKER = '      - name: Publish the versioned ZIP and checksum\n        run: |\n'


class ReleasePublishTest(unittest.TestCase):
    def exercise(self, mode):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'scripts').mkdir()
            shutil.copyfile(ROOT / 'scripts/build-plugin.py', root / 'scripts/build-plugin.py')
            shutil.copytree(ROOT / 'wordpress-plugin', root / 'wordpress-plugin')
            shutil.copytree(ROOT / 'releases', root / 'releases')
            version = subprocess.check_output(['python3', 'scripts/build-plugin.py', '--version'], cwd=root, text=True).strip()
            subprocess.run(['python3', 'scripts/build-plugin.py'], cwd=root, check=True, capture_output=True)
            package = root / 'dist' / f'gpante-support-chat-wordpress-{version}.zip'
            binary = root / 'bin'
            binary.mkdir()
            gh = binary / 'gh'
            gh.write_text('''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
with open(os.environ['FAKE_GH_LOG'],'a') as log:log.write(json.dumps(args)+'\\n')
if args[0]=='api':
 if os.environ['FAKE_GH_MODE']=='denied':
  print('gh: Forbidden (HTTP 403)',file=sys.stderr);sys.exit(1)
 if '/commits/' in args[1]:
  print('gh: No commit found for SHA (HTTP 422)',file=sys.stderr);sys.exit(1)
 print('gh: Not Found (HTTP 404)',file=sys.stderr);sys.exit(1)
if args[:2] not in [['release','create'],['release','upload'],['release','edit']]:
 raise RuntimeError('Unexpected CLI call; network fallback forbidden')
''')
            gh.chmod(0o755)
            log = root / 'gh-calls.jsonl'
            env = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ['PATH'],
                       RUNNER_TEMP=str(root), GH_TOKEN='local-fixture-only', VERSION=version,
                       GITHUB_REPOSITORY='fixture/plugin', GITHUB_SHA='a' * 40,
                       EXPECTED_DIGEST=hashlib.sha256(package.read_bytes()).hexdigest(),
                       FAKE_GH_MODE=mode, FAKE_GH_LOG=str(log))
            # Execute the exact run block, rather than a second implementation of it.
            script = '\n'.join(line[10:] for line in WORKFLOW.read_text().split(MARKER, 1)[1].splitlines())
            result = subprocess.run(['bash', '-c', script], cwd=root, env=env, capture_output=True, text=True)
            calls = [json.loads(line) for line in log.read_text().splitlines()]
            return result, calls

    def test_first_release_with_no_tag_publishes_after_404(self):
        result, calls = self.exercise('missing')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(any('/git/ref/tags/' in c[1] for c in calls if c[0] == 'api'))
        self.assertEqual([c[1] for c in calls if c[0] == 'release'], ['create', 'upload', 'edit'])

    def test_permission_failure_never_creates_or_publishes_a_release(self):
        result, calls = self.exercise('denied')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any(c[0] == 'release' for c in calls))


if __name__ == '__main__':
    unittest.main()
