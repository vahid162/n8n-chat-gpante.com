"""Portable locations for the explicitly isolated, HTTPS-loopback WP fixture."""
import os
from pathlib import Path
import re
import shutil
import tempfile

root = Path(os.environ.get('GPANTE_WP_TEST_DIR', Path(tempfile.gettempdir()) / 'gpante-native-wordpress')).expanduser().resolve()
base = os.environ.get('GPANTE_WP_TEST_URL', 'https://127.0.0.1:9443')
if not re.fullmatch(r'https://127\.0\.0\.1:[1-9][0-9]{0,4}', base) or not 1 <= int(base.rsplit(':', 1)[1]) < 65535:
    raise ValueError('WordPress browser/HTTP fixtures require an HTTPS loopback URL')
browser = os.environ.get('AGENT_BROWSER_BIN', shutil.which('agent-browser') or 'agent-browser')
