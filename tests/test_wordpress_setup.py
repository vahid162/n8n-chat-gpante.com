"""The native fixture bootstrap must reject unsafe targets before running tools."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('wordpress_setup', REPO / 'scripts/test-wordpress.py')
setup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(setup)


class WordPressSetupTests(unittest.TestCase):
    def test_remote_database_and_non_fixture_database_are_rejected(self):
        for key, value in [('GPANTE_TEST_DB_HOST', 'db.production.example:3306'),
                           ('GPANTE_TEST_DB_HOST', '127.0.0.1:99999'),
                           ('GPANTE_TEST_DB_NAME', 'wordpress'),
                           ('GPANTE_WP_TEST_URL', 'https://127.0.0.1:99999'),
                           ('GPANTE_WP_TEST_URL', 'https://gpante.com')]:
            with self.subTest(key=key), patch.dict(os.environ, {key: value}):
                with self.assertRaises(ValueError):
                    setup.settings()

    def test_checkout_and_home_cannot_be_used_as_fixture_directory(self):
        for root in [str(REPO), str(REPO / 'fixture'), str(Path.home()), '/']:
            with self.subTest(root=root), patch.dict(os.environ, GPANTE_WP_TEST_DIR=root):
                with self.assertRaises(ValueError):
                    setup.settings()

    def test_existing_directory_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as root, patch.dict(os.environ, GPANTE_WP_TEST_DIR=root), \
                patch('sys.argv', ['test-wordpress.py']), patch.object(setup.subprocess, 'check_output') as database:
            sentinel = Path(root) / 'wp-config.php'
            sentinel.write_text('existing installation')
            with self.assertRaisesRegex(ValueError, 'already exists'):
                setup.main()
            self.assertEqual(sentinel.read_text(), 'existing installation')
            database.assert_not_called()

    def test_run_only_requires_ownership_marker(self):
        with tempfile.TemporaryDirectory() as root, patch.dict(os.environ, GPANTE_WP_TEST_DIR=root), \
                patch('sys.argv', ['test-wordpress.py', '--run-only']), patch.object(setup, 'command') as command:
            with self.assertRaisesRegex(ValueError, 'owned test fixture'):
                setup.main()
            command.assert_not_called()

    def test_nonempty_database_is_rejected_before_writing_fixture(self):
        with tempfile.TemporaryDirectory() as parent, patch('sys.argv', ['test-wordpress.py']), \
                patch.object(setup.subprocess, 'check_output', return_value='12\n'):
            root = Path(parent) / 'new-fixture'
            with patch.dict(os.environ, GPANTE_WP_TEST_DIR=str(root)):
                with self.assertRaisesRegex(ValueError, 'not empty'):
                    setup.main()
            self.assertFalse(root.exists())


if __name__ == '__main__':
    unittest.main()
