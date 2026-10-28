"""Click native plugin menus/controls on an owned, offline HTTPS WordPress fixture.

Requires the existing test-wordpress.py fixture and Playwright. No production
requests or model calls: shared wp-local-backend plus a temporary menu mock.
"""
import json
import shutil
import subprocess
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright
from wordpress_test_config import root, base

repo = Path(__file__).resolve().parents[1]
assert (root / '.gpante-test-owner').read_text() == 'gpante-isolated-wordpress-v1\n'
wp = ['php', str(root / 'wp-cli.phar'), '--path=' + str(root / 'wordpress')]


def php(code):
    return subprocess.check_output(wp + ['eval', code], text=True).strip()


assert php('echo defined("GPANTE_SUPPORT_LOCAL_TEST") && GPANTE_SUPPORT_LOCAL_TEST && home_url()===GPANTE_SUPPORT_TEST_URL ? "owned" : "unsafe";') == 'owned'
mock = root / 'wordpress/wp-content/mu-plugins/gpante-menu-browser.php'
assert not mock.exists(), 'Refuse to overwrite an existing fixture file'
shutil.copyfile(repo / 'tests/fixtures/wp-menu-backend.php', mock)
saved = json.loads(php('echo wp_json_encode(array("appearance"=>get_option("gpante_support_appearance"),"runtime"=>get_option("gpante_support_runtime"),"policy"=>get_option("gptest_policy",null)));'))
results = {}
try:
    php('delete_option("gptest_menu_calls");delete_option("gptest_menu_synthetic");delete_option("gptest_now");update_option("gptest_backend_mode","success",false);update_option("gptest_policy",array("version"=>2,"userDays"=>30,"archiveDays"=>30,"allowClear"=>true,"revision"=>1),false);$a=gpante_support_get_appearance();for($i=0;$i<4;$i++)$a["links"][$i]=array("label"=>"Local guide ".($i+1),"url"=>"/?menu_guide=".($i+1));update_option("gpante_support_appearance",$a,false);')
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(ignore_https_errors=True, viewport=dict(width=1440, height=1000))
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base + '/') else route.abort())
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def visit(path):
            response = page.goto(base + path, wait_until='domcontentloaded')
            assert response and response.status == 200, (path, response.status if response else None)

        def click(selector):
            page.locator(selector).click()
            page.wait_for_load_state('domcontentloaded')

        def ready():
            page.wait_for_function("document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")

        def open_chat():
            if page.locator('#gpante-support-panel').is_hidden():
                page.locator('#gpante-support [data-launcher]').click()
            ready()

        visit('/wp-login.php')
        page.locator('#user_login').fill('gpante_admin')
        page.locator('#user_pass').fill((root / 'admin-password').read_text())
        click('#wp-submit')
        page.wait_for_url('**/wp-admin/**')
        visit('/wp-admin/admin.php?page=gpante-support-conversations')
        click('#toplevel_page_gpante-support-conversations > a')
        assert page.locator('#gpante-user-days').count() == 1
        destinations = [('gpante-support-connection', '#gpante-chat-key'),
                        ('gpante-support-diagnostics', 'input[name="request_id"]'),
                        ('gpante-support-appearance', '#gpante-appearance-title'),
                        ('gpante-support-conversations', '#gpante-user-days')]
        for slug, marker in destinations:
            click(f'#toplevel_page_gpante-support-conversations .wp-submenu a[href="admin.php?page={slug}"]')
            assert parse_qs(urlparse(page.url).query)['page'] == [slug]
            assert page.locator(marker).count() >= 1, slug
        visit('/wp-admin/options-general.php')
        click('#menu-settings .wp-submenu a[href="options-general.php?page=gpante-support-chat"]')
        assert page.locator('#gpante-chat-key').count() == 1
        results['adminMenus'] = 6
        print('PASS all plugin admin menu destinations and legacy Settings link', flush=True)

        visit('/wp-admin/admin.php?page=gpante-support-conversations')
        page.locator('input[name="search"]').fill('Synthetic menu')
        page.locator('input[name="from"]').fill('2026-10-01')
        page.locator('input[name="to"]').fill('2026-10-28')
        page.locator('select[name="status"]').select_option('success')
        page.locator('select[name="visibility"]').select_option('visible')
        click('form[method="get"] button')
        click('.wrap a.button:has-text("صفحهٔ بعد")')
        query = parse_qs(urlparse(page.url).query)
        assert query['archive_page'] == ['2'] and query['search'] == ['Synthetic menu']
        click('.wrap a.button:has-text("صفحهٔ قبل")')
        assert parse_qs(urlparse(page.url).query)['archive_page'] == ['1']
        click('.wrap table a[href*="session="]')
        assert page.locator('.wrap').inner_text().count('Synthetic menu question') == 4
        assert page.evaluate('window.gpanteMenuXss || null') is None
        click('.wrap a.button:has-text("صفحهٔ بعد")')
        assert parse_qs(urlparse(page.url).query)['session'] == ['b' * 64]
        click('.wrap a.button:has-text("صفحهٔ قبل")')
        click('.wrap a:has-text("بازگشت به فهرست")')
        assert 'session' not in parse_qs(urlparse(page.url).query)
        calls = json.loads(php('echo wp_json_encode(get_option("gptest_menu_calls"));'))
        assert any(call == dict(operation='listSessions', page=2, search='Synthetic menu',
                               **{'from': '2026-10-01', 'to': '2026-10-28', 'status': 'success', 'visibility': 'visible'}) for call in calls)
        results['archiveNavigation'] = True
        print('PASS archive filters, pagination, session detail, escaping and back link', flush=True)

        visit('/wp-admin/admin.php?page=gpante-support-diagnostics')
        form = page.locator('form:has(input[name="action"][value="gpante_support_synthetic"])').first
        nonce = form.locator('input[name="_wpnonce"]').input_value()
        mutation = dict(action='gpante_support_synthetic', request_id='a' * 32, _wpnonce='invalid')
        assert context.request.post(base + '/wp-admin/admin-post.php', form=mutation).status == 403
        assert php('echo get_option("gptest_menu_synthetic") ? "changed" : "unchanged";') == 'unchanged'
        form.locator('input[type="submit"]').click()
        page.wait_for_load_state('domcontentloaded')
        assert page.locator('input[name="request_id"][value="' + 'a' * 32 + '"]').count() == 0
        assert php('echo get_option("gptest_menu_synthetic") ? "changed" : "unchanged";') == 'changed'
        subscriber = browser.new_context(ignore_https_errors=True)
        subscriber.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base + '/') else route.abort())
        sub = subscriber.new_page()
        sub.goto(base + '/wp-login.php')
        sub.locator('#user_login').fill('gpante_subscriber')
        sub.locator('#user_pass').fill('local-fixture-subscriber-only')
        sub.locator('#wp-submit').click()
        sub.wait_for_load_state('domcontentloaded')
        assert any(cookie['name'].startswith('wordpress_logged_in_') for cookie in subscriber.cookies())
        assert subscriber.request.post(base + '/wp-admin/admin-post.php', form={**mutation, '_wpnonce': nonce}).status == 403
        response = subscriber.request.get(base + '/wp-admin/admin.php?page=gpante-support-diagnostics')
        assert response.status == 403 or 'کیفیت پاسخ و سلامت منابع' not in response.text()
        subscriber.close()
        results['diagnosticMutationBoundaries'] = True
        print('PASS native diagnostics marking and administrator/nonce boundaries', flush=True)

        for index in range(1, 5):
            visit('/')
            open_chat()
            click(f'#gpante-support [data-shortcuts] a:nth-child({index})')
            assert parse_qs(urlparse(page.url).query)['menu_guide'] == [str(index)]
        results['shortcutDestinations'] = 4
        print('PASS all four configured shortcut destinations', flush=True)

        visit('/')
        open_chat()
        root_widget = page.locator('#gpante-support')
        page.locator('[data-input]').fill('Draft remains')
        page.locator('[data-close]').click()
        page.wait_for_function("document.querySelector('#gpante-support-panel').hidden")
        open_chat()
        assert page.locator('[data-input]').input_value() == 'Draft remains'
        for index in range(7):
            root_widget.locator('[data-input]').fill(f'Synthetic question {index}')
            root_widget.locator('[data-send]').click()
            ready()
        page.reload(wait_until='domcontentloaded')
        open_chat()
        assert root_widget.locator('.gpante-chat-message').count() == 12
        root_widget.locator('[data-older]').click()
        ready()
        assert root_widget.locator('.gpante-chat-message').count() == 14
        helpful = root_widget.locator('.gpante-chat-message--assistant').last.get_by_role('button', name='مفید بود', exact=True)
        helpful.click()
        ready()
        assert helpful.get_attribute('aria-pressed') == 'true'
        unhelpful = root_widget.locator('.gpante-chat-message--assistant').last.get_by_role('button', name='مفید نبود', exact=True)
        unhelpful.click()
        ready()
        assert unhelpful.get_attribute('aria-pressed') == 'true'
        root_widget.locator('[data-new]').click()
        root_widget.locator('[data-cancel]').click()
        assert root_widget.locator('[data-confirm]').is_hidden()
        assert root_widget.locator('.gpante-chat-message').count() == 14
        php('update_option("gptest_backend_mode","network",false);')
        root_widget.locator('[data-input]').fill('Synthetic fallback')
        root_widget.locator('[data-send]').click()
        ready()
        assert root_widget.locator('[data-fallback]').is_visible()
        php('update_option("gptest_backend_mode","success",false);')
        root_widget.locator('[data-reconnect]').click()
        ready()
        root_widget.locator('[data-retry]').click()
        ready()
        assert root_widget.locator('.gpante-chat-message--assistant').last.inner_text().count('Synthetic fallback') == 1
        php('update_option("gptest_backend_mode","network",false);')
        root_widget.locator('[data-input]').fill('Synthetic search')
        root_widget.locator('[data-send]').click()
        ready()
        click('#gpante-support [data-fallback]')
        assert parse_qs(urlparse(page.url).query)['s'] == ['Synthetic search']
        php('update_option("gptest_backend_mode","success",false);')
        visit('/')
        open_chat()
        root_widget.locator('[data-new]').click()
        root_widget.locator('[data-clear]').click()
        ready()
        assert root_widget.locator('.gpante-chat-message').count() == 1
        assert root_widget.locator('[data-older]').is_hidden()
        root_widget.locator('[data-close]').click()
        assert not errors, errors
        results.update(frontendControls=True, nativeSearchFallback=True, modelCalls=0, errors=errors,
                       browser=browser.version)
        page.screenshot(path=str(root / 'evidence/menu-smoke.png'))
        context.close()
        browser.close()
    (root / 'evidence/menu-smoke.json').write_text(json.dumps(results, indent=2) + '\n')
    print(json.dumps(results))
finally:
    data = json.dumps(saved, ensure_ascii=False).encode().hex()
    php('$s=json_decode(hex2bin("' + data + '"),true);update_option("gpante_support_appearance",$s["appearance"],false);update_option("gpante_support_runtime",$s["runtime"],false);if($s["policy"]===null)delete_option("gptest_policy");else update_option("gptest_policy",$s["policy"],false);delete_option("gptest_menu_calls");delete_option("gptest_menu_synthetic");update_option("gptest_backend_mode","success",false);')
    mock.unlink(missing_ok=True)
