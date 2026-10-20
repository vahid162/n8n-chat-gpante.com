"""Release packaging checks using isolated copies of the real runtime source."""
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('package_plugin', Path(__file__).resolve().parents[1] / 'scripts/build-plugin.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PluginPackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.original = package.SOURCE
        self.version = package.plugin_version()
        self.addCleanup(setattr, package, 'SOURCE', self.original)
        package.SOURCE = self.root / 'plugin'
        shutil.copytree(self.original, package.SOURCE)

    def test_archive_is_reproducible_and_contains_only_installable_runtime(self):
        self.assertEqual(package.plugin_version(), self.version)
        first, second = self.root / 'one.zip', self.root / 'two.zip'
        package.build_plugin(first)
        package.build_plugin(second)
        self.assertEqual(first.read_bytes(), second.read_bytes())
        with zipfile.ZipFile(first) as archive:
            self.assertEqual(set(archive.namelist()), {'gpante-support-chat/' + f for f in package.FILES})
            for name in package.FILES:
                self.assertEqual(archive.read('gpante-support-chat/' + name), (package.SOURCE / name).read_bytes())

    def test_inconsistent_versions_and_leading_zeroes_are_rejected(self):
        major, minor, patch = map(int, self.version.split('.'))
        next_version = f'{major}.{minor}.{patch + 1}'
        for filename, old, new in [
            ('gpante-support-chat.php', f"'GPANTE_SUPPORT_VERSION', '{self.version}'", f"'GPANTE_SUPPORT_VERSION', '{next_version}'"),
            ('gpante-support-chat.php', 'Version: ' + self.version, 'Version: 0' + self.version),
            ('readme.txt', 'Stable tag: ' + self.version, 'Stable tag: ' + next_version),
        ]:
            file = package.SOURCE / filename
            original = file.read_text()
            with self.subTest(filename=filename, new=new):
                file.write_text(original.replace(old, new))
                with self.assertRaises(ValueError):
                    package.plugin_version()
            file.write_text(original)

    def test_unexpected_files_and_symlinks_never_enter_the_release(self):
        extra = package.SOURCE / '.env'
        extra.write_text('fixture only')
        with self.assertRaises(ValueError):
            package.build_plugin(self.root / 'bad.zip')
        extra.unlink()
        asset = package.SOURCE / 'assets/chat.js'
        outside = self.root / 'outside.js'
        outside.write_bytes(asset.read_bytes())
        asset.unlink()
        asset.symlink_to(outside)
        with self.assertRaises(ValueError):
            package.build_plugin(self.root / 'bad.zip')


if __name__ == '__main__':
    unittest.main()
