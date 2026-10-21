#!/usr/bin/env python3
"""Build a deterministic, runtime-only WordPress ZIP; never include the repo."""
import argparse
import hashlib
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'wordpress-plugin/gpante-support-chat'
FILES = ('gpante-support-chat.php', 'includes/settings.php', 'includes/appearance.php', 'includes/bridge.php',
         'includes/catalog.php', 'includes/faq.php', 'includes/admin.php', 'templates/widget.php', 'assets/chat.js',
         'assets/chat.css', 'readme.txt', 'uninstall.php', 'LICENSE.txt')


def plugin_version():
    """Require the header, runtime constant and readme to declare one version."""
    header = (SOURCE / FILES[0]).read_text()
    version = re.search(r'^ \* Version: ((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$', header, re.M)
    if not version:
        raise ValueError('Plugin header must declare an X.Y.Z version without leading zeroes')
    version = version.group(1)
    constant = re.search(r"define\(\s*'GPANTE_SUPPORT_VERSION',\s*'([^']+)'\s*\)", header)
    stable = re.search(r'^Stable tag: (\S+)$', (SOURCE / 'readme.txt').read_text(), re.M)
    if not constant or not stable or constant.group(1) != version or stable.group(1) != version:
        raise ValueError('Plugin header, runtime constant and Stable tag must match')
    return version


def build_plugin(output):
    """Package exactly the approved runtime files and check the resulting ZIP."""
    actual = {str(f.relative_to(SOURCE)) for f in SOURCE.rglob('*') if f.is_file()}
    if actual != set(FILES):
        raise ValueError('Unexpected or missing runtime files')
    if output.resolve() == SOURCE or SOURCE in output.resolve().parents:
        raise ValueError('Output must be outside the plugin source')
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(FILES):
            file = SOURCE / name
            if not file.is_file() or file.is_symlink():
                raise ValueError('Runtime sources must be regular files')
            info = zipfile.ZipInfo('gpante-support-chat/' + name, date_time=(2026, 10, 20, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, file.read_bytes())
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == {'gpante-support-chat/' + f for f in FILES}
        for name in FILES:
            assert archive.read('gpante-support-chat/' + name) == (SOURCE / name).read_bytes()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', nargs='?', type=Path)
    parser.add_argument('--version', action='store_true', help='print the validated plugin version')
    args = parser.parse_args()
    version = plugin_version()
    if args.version:
        print(version)
        return
    output = args.output or ROOT / 'dist' / ('gpante-support-chat-wordpress-' + version + '.zip')
    build_plugin(output)
    print(str(output.resolve()))
    print('SHA256 ' + hashlib.sha256(output.read_bytes()).hexdigest()
          + '; ' + str(len(FILES)) + ' runtime files; version ' + version)


if __name__ == '__main__':
    main()
