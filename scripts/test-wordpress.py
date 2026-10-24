#!/usr/bin/env python3
"""Create an owned, isolated WordPress fixture and run its native integration tests.

Requires an empty local MariaDB/MySQL database named gpante_test_*. No production
credentials, model calls, database creation/deletion, or existing install reuse.
"""
import argparse
import hashlib
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import re
import secrets
import shutil
import ssl
import subprocess
import sys
import tempfile
import urllib.request

REPO = Path(__file__).resolve().parents[1]
WP_VERSION = '7.1.3'
WOO_VERSION = '11.2.1'
CLI_VERSION = '2.12.0'
CLI_SHA256 = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c'
MARKER = 'gpante-isolated-wordpress-v1\n'
NATIVE_TESTS = ['wordpress-plugin-integration.php', 'catalog-discovery-wordpress.php',
                'faq-source-wordpress.php', 'site-knowledge-wordpress.php', 'search-index-wordpress.php',
                'wordpress-backend-wordpress.php']


def settings():
    root = Path(os.environ.get('GPANTE_WP_TEST_DIR', Path(tempfile.gettempdir()) / 'gpante-native-wordpress')).expanduser().resolve()
    if root == Path(root.anchor) or root == Path.home() or root == REPO or REPO in root.parents:
        raise ValueError('Test directory must be outside the repository and cannot be a home/root directory')
    host = os.environ.get('GPANTE_TEST_DB_HOST', '127.0.0.1:3306')
    if not re.fullmatch(r'(?:127\.0\.0\.1|localhost)(?::[1-9][0-9]{0,4})?', host) or int(host.partition(':')[2] or '3306') > 65535:
        raise ValueError('Only a loopback test database is permitted')
    name = os.environ.get('GPANTE_TEST_DB_NAME', 'gpante_test_native')
    if not re.fullmatch(r'gpante_test_[a-z0-9_]+', name):
        raise ValueError('Database name must start with gpante_test_')
    url = os.environ.get('GPANTE_WP_TEST_URL', 'https://127.0.0.1:9443')
    if not re.fullmatch(r'https://127\.0\.0\.1:[1-9][0-9]{0,4}', url) or not 1 <= int(url.rsplit(':', 1)[1]) < 65535:
        raise ValueError('Only an HTTPS IPv4 loopback test URL is permitted')
    return root, host, name, url


def command(args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)


def serve(root, url, wp):
    """Optional HTTPS loopback bridge for the existing HTTP/real-browser suites."""
    port = int(url.rsplit(':', 1)[1])
    if not 1 <= port < 65535:
        raise ValueError('HTTPS fixture port must be between 1 and 65534')
    cert, key = root / 'tls.crt', root / 'tls.key'
    if not cert.exists():
        command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
                 '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1',
                 '-keyout', str(key), '-out', str(cert)], capture_output=True)
        key.chmod(0o600)
    router = root / 'router.php'
    router.write_text("<?php\n$_SERVER['HTTPS']='on';\n$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);\n"
                      "if($path!=='/' && is_file(__DIR__.'/wordpress'.$path)) return false;\n"
                      "require __DIR__.'/wordpress/index.php';\n")
    command(wp + ['eval', "$c=get_option('gpante_support_runtime'); $c['enabled']=true; $c['shared_key']=str_repeat('fixture-only-',4); $c['admin_key']=str_repeat('fixture-admin-only-',3); update_option('gpante_support_runtime',$c,false);"])

    class Proxy(BaseHTTPRequestHandler):
        def request(self):
            body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
            upstream = HTTPConnection('127.0.0.1', port + 1, timeout=180)
            headers = {key: value for key, value in self.headers.items() if key.lower() not in {'host', 'connection'}}
            headers['Host'] = f'127.0.0.1:{port}'
            upstream.request(self.command, self.path, body, headers)
            response = upstream.getresponse()
            data = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in {'connection', 'transfer-encoding', 'content-length'}:
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            upstream.close()

        do_GET = do_POST = do_HEAD = do_OPTIONS = request

        def log_message(self, *args):
            pass

    server = ThreadingHTTPServer(('127.0.0.1', port), Proxy)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    with (root / 'php-server.log').open('a') as log:
        php = subprocess.Popen(['php', '-S', f'127.0.0.1:{port + 1}', '-t', str(root / 'wordpress'), str(router)],
                               stdout=log, stderr=log)
        try:
            print('Isolated HTTPS fixture:', url, '(Ctrl-C stops both servers)', flush=True)
            server.serve_forever()
        except KeyboardInterrupt:
            pass
        finally:
            server.server_close()
            php.terminate()
            php.wait(timeout=10)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--setup-only', action='store_true', help='Prepare the fixture without running native tests')
    parser.add_argument('--run-only', action='store_true', help='Run native tests on this script’s owned fixture')
    parser.add_argument('--serve', action='store_true', help='After checking, serve this owned fixture over loopback HTTPS')
    args = parser.parse_args()
    root, host, name, url = settings()
    marker = root / '.gpante-test-owner'
    if args.run_only:
        if not marker.is_file() or marker.read_text() != MARKER:
            raise ValueError('An owned test fixture is required; run setup first')
    else:
        if root.exists():
            raise ValueError('Test directory already exists; choose a new empty path (existing installs are never overwritten)')
        # Check before writing files: a similarly named nonempty database is still not ours.
        address, _, port = host.partition(':')
        db_env = dict(os.environ, MYSQL_PWD=os.environ.get('GPANTE_TEST_DB_PASSWORD', 'gpante-isolated-ci-only'))
        db_command = [shutil.which('mariadb') or 'mysql', '--protocol=tcp', '--host=' + address,
                      '--port=' + (port or '3306'), '--user=' + os.environ.get('GPANTE_TEST_DB_USER', 'gpante_test'),
                      '--database=' + name, '--batch', '--skip-column-names']
        count = subprocess.check_output(db_command + ['--execute=SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'], env=db_env, text=True)
        if count.strip() != '0':
            raise ValueError('Test database is not empty; no installation was changed')
        root.mkdir(mode=0o700, parents=True)
        marker.write_text(MARKER)
        phar = root / 'wp-cli.phar'
        with urllib.request.urlopen(f'https://github.com/wp-cli/wp-cli/releases/download/v{CLI_VERSION}/wp-cli-{CLI_VERSION}.phar', timeout=60) as response:
            payload = response.read(16 * 1024 * 1024)
        if hashlib.sha256(payload).hexdigest() != CLI_SHA256:
            raise ValueError('WP-CLI checksum mismatch')
        phar.write_bytes(payload)
        wp = ['php', str(phar), '--path=' + str(root / 'wordpress')]
        command(wp + ['core', 'download', '--version=' + WP_VERSION, '--locale=en_US'])
        command(wp + ['config', 'create', '--dbname=' + name,
                      '--dbuser=' + os.environ.get('GPANTE_TEST_DB_USER', 'gpante_test'), '--dbhost=' + host,
                      '--dbprefix=gptest_', '--skip-check', '--prompt=dbpass'], input=db_env['MYSQL_PWD'] + '\n', capture_output=True)
        for key, value in [('GPANTE_SUPPORT_LOCAL_TEST', 'true'), ('DISABLE_WP_CRON', 'true')]:
            command(wp + ['config', 'set', key, value, '--raw'])
        command(wp + ['config', 'set', 'GPANTE_SUPPORT_TEST_URL', url])
        password = secrets.token_urlsafe(30)
        (root / 'admin-password').write_text(password)
        (root / 'admin-password').chmod(0o600)
        command(wp + ['core', 'install', '--url=' + url, '--title=GPante isolated fixture',
                      '--admin_user=gpante_admin', '--admin_email=admin@example.invalid', '--skip-email',
                      '--prompt=admin_password'], input=password + '\n', capture_output=True)
        # WP-CLI can infer a /wordpress home path from the fixture directory name.
        command(wp + ['option', 'update', 'home', url])
        command(wp + ['option', 'update', 'siteurl', url])
        command(wp + ['plugin', 'install', 'woocommerce', '--version=' + WOO_VERSION, '--activate'])
        shutil.copytree(REPO / 'wordpress-plugin/gpante-support-chat', root / 'wordpress/wp-content/plugins/gpante-support-chat')
        mu = root / 'wordpress/wp-content/mu-plugins'
        mu.mkdir(exist_ok=True)
        shutil.copyfile(REPO / 'tests/fixtures/wp-local-backend.php', mu / 'gpante-local-backend.php')
        command(wp + ['plugin', 'activate', 'gpante-support-chat'])
        command(wp + ['user', 'create', 'gpante_subscriber', 'subscriber@example.invalid',
                      '--role=subscriber', '--user_pass=local-fixture-subscriber-only'])
        (root / 'evidence').mkdir()
    wp = ['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress')]
    if not args.setup_only:
        command(wp + ['eval', "if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== GPANTE_SUPPORT_TEST_URL) throw new RuntimeException('Isolated fixture required'); delete_option('gpante_support_runtime');"])
        for fixture in NATIVE_TESTS:
            # These fixtures require the canonical source directly; avoid loading the installed copy twice.
            skip = [] if fixture in {'wordpress-plugin-integration.php', 'wordpress-backend-wordpress.php'} else ['--skip-plugins=gpante-support-chat']
            command(wp + skip + ['eval-file', str(REPO / 'tests' / fixture)])
    print('Owned isolated WordPress fixture:', root)
    print('Pinned WordPress/WooCommerce/WP-CLI:', WP_VERSION, WOO_VERSION, CLI_VERSION)
    if args.serve:
        serve(root, url, wp)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print('Fixture setup/check failed:', str(error), file=sys.stderr)
        sys.exit(1)
