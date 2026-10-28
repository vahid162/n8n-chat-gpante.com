"""Exercise privileged admin UX on an owned, offline HTTPS WordPress fixture.

Requires Playwright Chromium. Evidence stays in the private fixture directory.
The mock supplies 65 synthetic records and applies filters before pagination.
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


def options():
    return php('echo hash("sha256",serialize(array(get_option("gpante_support_appearance"),get_option("gpante_support_runtime"))));')


assert php('echo defined("GPANTE_SUPPORT_LOCAL_TEST") && GPANTE_SUPPORT_LOCAL_TEST && home_url()===GPANTE_SUPPORT_TEST_URL ? "owned" : "unsafe";') == 'owned'
mock = root / 'wordpress/wp-content/mu-plugins/gpante-menu-browser.php'
assert not mock.exists(), 'Refuse to overwrite an existing fixture file'
shutil.copyfile(repo / 'tests/fixtures/wp-menu-backend.php', mock)
saved = json.loads(php('echo wp_json_encode(array("appearance"=>get_option("gpante_support_appearance"),"runtime"=>get_option("gpante_support_runtime")));'))
results = dict(modelCalls=0, viewports=[], checks=[])
errors, requests, outside = [], [], []
try:
    php('delete_option("gptest_menu_calls");delete_option("gptest_menu_synthetic");delete_option("gptest_menu_diagnostics_mode");')
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(ignore_https_errors=True, viewport=dict(width=1440, height=1000),
                                      permissions=['clipboard-read', 'clipboard-write'])
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base + '/') else (outside.append(route.request.url), route.abort())[-1])
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('request', lambda request: requests.append(dict(url=request.url, method=request.method)))

        def visit(slug):
            path = slug if slug.startswith('/') else '/wp-admin/admin.php?page=' + slug
            response = page.goto(base + path, wait_until='networkidle')
            assert response and response.status == 200, (path, response.status if response else None)

        def click(selector):
            page.locator(selector).click()
            page.wait_for_load_state('networkidle')

        def query():
            return {key: value[0] for key, value in parse_qs(urlparse(page.url).query).items()}

        def check(name):
            results['checks'].append(name)
            print('PASS ' + name, flush=True)

        def geometry(label, width):
            data = page.evaluate('''() => ({viewport:innerWidth, document:document.documentElement.scrollWidth,
              body:document.body.scrollWidth, tables:[...document.querySelectorAll('.gpante-admin-table-wrap')].filter(e=>e.getClientRects().length).map(e=>({width:e.clientWidth,scroll:e.scrollWidth,overflow:getComputedStyle(e).overflowX,left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right}))})''')
            assert data['document'] <= width + 1 and data['body'] <= width + 1, (label, data)
            assert all(table['left'] >= -1 and table['right'] <= width + 1 and table['overflow'] in ('auto', 'scroll') for table in data['tables']), (label, data)
            results['viewports'].append(dict(page=label, width=width, geometry=data))
            page.screenshot(path=str(root / 'evidence' / f'admin-{label}-{width}.png'))

        visit('/wp-login.php')
        page.locator('#user_login').fill('gpante_admin')
        page.locator('#user_pass').fill((root / 'admin-password').read_text())
        click('#wp-submit')
        page.wait_for_url('**/wp-admin/**')
        visit('gpante-support-diagnostics')
        cards = page.locator('article.postbox')
        assert cards.count() == 20
        assert page.locator('.gpante-admin-overview').inner_text().find('$0.001234567') >= 0
        assert '$2.00' in page.locator('.gpante-admin-overview').inner_text()
        description = page.locator('.gpante-admin-description').inner_text()
        assert all(text in description for text in ['۷ روز', 'ماه تقویمی', 'UTC', '۳۰ روز'])
        assert cards.first.locator('time').inner_text() == '2026/10/28 00:00:00 UTC'
        error_links = page.locator('.gpante-admin-overview a').all()
        assert len(error_links) == 2
        assert {parse_qs(urlparse(link.get_attribute('href')).query)['status'][0] for link in error_links} == {'error', 'interrupted'}
        assert all(parse_qs(urlparse(link.get_attribute('href')).query)['page'] == ['gpante-support-diagnostics'] and urlparse(link.get_attribute('href')).fragment == 'gpante-diagnostic-records' for link in error_links)
        assert 'FORBIDDEN-' not in page.content() and 'forbidden.invalid' not in page.content()
        assert page.evaluate('window.gpanteDiagnosticXss || null') is None
        first = cards.first
        first.locator('summary:has-text("مسیر منابع و تصمیم‌ها")').click()
        assert first.locator('.gpante-admin-table-wrap table tbody tr').count() == 2
        assert all(text in first.locator('.gpante-admin-table-wrap table').inner_text() for text in ['Product_Search', 'DXF', 'wood', 'product-1', 'unsupported_reference'])
        first.locator('summary:has-text("دادهٔ فنی مجاز")').click()
        raw = first.locator('pre').all_text_contents()
        assert json.loads(raw[0])['results'] == [dict(id='product-1', rank=1, kind='product')]
        assert not any('FORBIDDEN-' in text for text in raw)
        check('readable USD, explicit summary periods, UTC timestamps and allowlisted structured/raw source rows')

        reference = first.locator('[data-gpante-copy]').first
        source = reference.get_attribute('data-gpante-copy')
        expected = page.locator('#' + source).text_content()
        page.locator('[data-gpante-copy-status]').first.evaluate('(e)=>{e.textContent=""}')
        reference.click()
        page.wait_for_function("document.querySelector('[data-gpante-copy-status]').textContent==='کپی شد.'")
        assert page.evaluate('navigator.clipboard.readText()') == expected
        page.evaluate("() => { navigator.clipboard.writeText = async () => {throw new Error('fixture clipboard refusal')}; }")
        page.locator('[data-gpante-copy-status]').first.evaluate('(e)=>{e.textContent=""}')
        reference.click()
        page.wait_for_function("document.querySelector('[data-gpante-copy-status]').textContent==='کپی شد.'")
        assert page.evaluate('navigator.clipboard.readText()') == expected
        page.evaluate('() => { document.execCommand = () => false; }')
        page.locator('[data-gpante-copy-status]').first.evaluate('(e)=>{e.textContent=""}')
        reference.click()
        page.wait_for_function("document.querySelector('[data-gpante-copy-status]').textContent.includes('کپی خودکار انجام نشد')")
        assert reference.evaluate('(e)=>e===document.activeElement')
        first.locator('.gpante-admin-reference details summary').click()
        full_reference = page.locator('#' + source)
        assert full_reference.is_visible()
        full_reference.focus()
        full_reference.evaluate('''e=>{const selection=getSelection(), range=document.createRange();range.selectNodeContents(e);selection.removeAllRanges();selection.addRange(range)}''')
        assert page.evaluate('getSelection().toString()') == expected
        page.keyboard.press('Control+C')
        assert page.evaluate('navigator.clipboard.readText()') == expected
        check('copy reference matches actual clipboard; fallback/failure status, focus and selectable full-ID manual copy work')
        geometry('diagnostics', 1440)

        page.locator('form[method="get"] input[name="search"]').fill('0')
        click('form[method="get"] button')
        assert page.locator('article.postbox').count() == 20
        assert 'product-1</code>' not in page.content(), 'zero search must filter the first page too'
        calls = json.loads(php('echo wp_json_encode(get_option("gptest_menu_calls"));'))
        zero_filters = dict(search='0', **{'from': '', 'to': ''}, status='', traffic='', source='')
        assert dict(operation='getDiagnostics', page=1, **zero_filters) in calls
        click('.gpante-admin-pager a:has-text("صفحهٔ بعد")')
        calls = json.loads(php('echo wp_json_encode(get_option("gptest_menu_calls"));'))
        assert query()['search'] == '0' and dict(operation='getDiagnostics', page=2, **zero_filters) in calls
        check('zero-valued metadata search is applied and forwarded on both diagnostic pages')
        visit('gpante-support-diagnostics')

        filters = dict(search='Beyond page twenty', **{'from': '2026-10-27', 'to': '2026-10-28'},
                       status='success', traffic='unclassified', source='products')
        for key, value in filters.items():
            field = page.locator('form[method="get"] [name="' + key + '"]')
            field.select_option(value) if field.evaluate('(e)=>e.tagName') == 'SELECT' else field.fill(value)
        click('form[method="get"] button')
        assert page.locator('article.postbox').count() == 20
        assert 'product-21' in page.content() and 'product-1"' not in page.content()
        click('.gpante-admin-pager a:has-text("صفحهٔ بعد")')
        assert query()['diagnostic_page'] == '2'
        assert page.locator('article.postbox').count() == 20 and 'product-41' in page.content()
        calls = json.loads(php('echo wp_json_encode(get_option("gptest_menu_calls"));'))
        assert dict(operation='getDiagnostics', page=2, **filters) in calls, calls
        click('article.postbox:first-of-type a:has-text("مشاهدهٔ گفتگو")')
        assert query()['return_view'] == 'diagnostics' and query()['return_page'] == '2'
        click('.gpante-admin-pager a:has-text("صفحهٔ بعد")')
        assert query()['archive_page'] == '2'
        click('.wrap a:has-text("بازگشت به فهرست")')
        assert query() == dict(page='gpante-support-diagnostics', diagnostic_page='2', **filters), query()
        check('backend filters find rows beyond first twenty and page-two detail/back preserves all diagnostics state')

        visit('gpante-support-conversations')
        archive_filters = dict(search='Synthetic menu', **{'from': '2026-10-01', 'to': '2026-10-28'}, status='success', visibility='visible')
        for key, value in archive_filters.items():
            field = page.locator('form[method="get"] [name="' + key + '"]')
            field.select_option(value) if field.evaluate('(e)=>e.tagName') == 'SELECT' else field.fill(value)
        click('form[method="get"] button')
        click('.gpante-admin-pager a:has-text("صفحهٔ بعد")')
        assert query()['archive_page'] == '2'
        geometry('archive-list', 1440)
        click('.gpante-admin table a[href*="session="]')
        assert query()['archive_page'] == '1' and query()['return_page'] == '2'
        summaries = page.locator('.gpante-admin-turn .gpante-admin-summary').all_text_contents()
        assert '$0.000000001' in summaries[0] and '1.25' in summaries[0]
        assert summaries[1].count('نامشخص') == 2
        assert '$0.0005' in summaries[2] and '$0.00' in summaries[3]
        assert page.locator('.gpante-admin-warning').filter(has_text='رزرو محافظه‌کارانه').count() == 1
        assert page.locator('.gpante-admin-message.is-user').count() == 4
        assert page.locator('.gpante-admin-message.is-assistant').count() == 4
        assert page.evaluate('window.gpanteMenuXss || null') is None
        assert not page.locator('.gpante-admin-turn details[open]').count()
        geometry('archive-detail', 1440)
        click('.gpante-admin-pager a:has-text("صفحهٔ بعد")')
        click('.wrap a:has-text("بازگشت به فهرست")')
        assert query() == dict(page='gpante-support-conversations', archive_page='2', **archive_filters), query()
        check('archive page-two filters survive detail pagination/back; tiny, zero and missing values stay distinct')

        visit('gpante-support-connection')
        assert page.locator('.gpante-admin-key-status .is-configured').count() == 2
        assert page.locator('details.gpante-admin-key-fields').evaluate('(e)=>!e.open')
        assert page.locator('#gpante-chat-key').is_hidden() and page.locator('#gpante-admin-key').is_hidden()
        assert page.locator('#gpante-chat-key').input_value() == '' and page.locator('#gpante-admin-key').input_value() == ''
        page.locator('.gpante-admin-key-fields summary').focus()
        page.keyboard.press('Enter')
        assert page.locator('#gpante-chat-key').is_visible()
        page.locator('#gpante-chat-key').focus()
        assert page.locator('#gpante-chat-key').evaluate('(e)=>e===document.activeElement')
        geometry('connection', 1440)
        php('$c=get_option("gpante_support_runtime");$c["shared_key"]="";$c["admin_key"]="";$c["enabled"]=false;update_option("gpante_support_runtime",$c,false);')
        visit('gpante-support-connection')
        assert page.locator('.gpante-admin-key-status .is-unconfigured').count() == 2
        encoded = json.dumps(saved['runtime'], ensure_ascii=False).encode().hex()
        php('update_option("gpante_support_runtime",json_decode(hex2bin("' + encoded + '"),true),false);')
        check('configured/unconfigured key states, hidden replacement inputs and keyboard focus work without exposing saved keys')

        visit('gpante-support-appearance')
        before = options()
        start_requests = len(requests)
        texts = dict(title='Unsaved preview title', subtitle='Unsaved subtitle', launcher='Unsaved launcher', welcome='Unsaved welcome',
                     placeholder='Unsaved placeholder', clear='Unsaved clear', input_label='Unsaved accessible input')
        for key, value in texts.items():
            page.locator('#gpante-appearance-' + key).fill(value)
            if key == 'input_label':
                assert page.locator('[data-gpante-preview-input]').get_attribute('aria-label') == value
            else:
                assert page.locator('[data-gpante-preview-text="' + key + '"]').inner_text() == value
        page.locator('#gpante-color-primary').evaluate("e=>{e.value='#123456';e.dispatchEvent(new Event('input',{bubbles:true}))}")
        assert page.locator('.gpante-admin-preview').evaluate("e=>getComputedStyle(e).getPropertyValue('--gpante-preview-primary').trim()") == '#123456'
        header_colors = page.locator('.gpante-admin-preview__header').evaluate('''e=>({background:getComputedStyle(e).backgroundColor,
            text:getComputedStyle(e).color,children:[...e.children].map(child=>getComputedStyle(child).color)})''')
        assert header_colors['background'] == 'rgb(18, 52, 86)'
        assert header_colors['text'] == 'rgb(255, 255, 255)' and all(color == header_colors['text'] for color in header_colors['children']), header_colors
        def luminance(rgb):
            channels = [component / 255 for component in rgb]
            channels = [value / 12.92 if value <= 0.04045 else ((value + 0.055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(channels, [0.2126, 0.7152, 0.0722]))
        assert (luminance([255, 255, 255]) + 0.05) / (luminance([18, 52, 86]) + 0.05) >= 4.5
        results['previewHeaderColors'] = header_colors
        for index in range(4):
            page.locator('#gpante-link-label-' + str(index)).fill('Preview shortcut ' + str(index + 1))
            page.locator('#gpante-link-url-' + str(index)).fill('/?preview=' + str(index + 1))
        assert page.locator('[data-gpante-preview-link]:visible').count() == 4
        assert page.locator('.gpante-admin-preview a, .gpante-admin-preview button, .gpante-admin-preview input').count() == 0
        preview = page.locator('.gpante-admin-preview')
        preview.scroll_into_view_if_needed()
        point = preview.bounding_box()
        page.mouse.click(point['x'] + 15, point['y'] + 30)
        page.wait_for_timeout(100)
        assert options() == before
        assert not [r for r in requests[start_requests:] if '/gpante-support/v1/' in r['url'] or r['method'] != 'GET'], requests[start_requests:]
        assert page.locator('#gpante-support').count() == 0
        page.locator('#gpante-appearance-title').focus()
        assert page.locator('#gpante-appearance-title').evaluate('(e)=>e===document.activeElement')
        geometry('appearance', 1440)
        with page.expect_navigation(wait_until='networkidle'):
            page.locator('#submit').click()
        assert options() != before
        assert page.locator('#gpante-appearance-title').input_value() == texts['title']
        saved_appearance = options()
        dialog_messages = []

        def dismiss(dialog):
            dialog_messages.append(dict(type=dialog.type, message=dialog.message, accepted=False))
            dialog.dismiss()

        page.once('dialog', dismiss)
        page.locator('[data-gpante-confirm]').click()
        assert dialog_messages[-1]['type'] == 'confirm'
        assert options() == saved_appearance and page.locator('#gpante-appearance-title').input_value() == texts['title']
        page.once('dialog', lambda dialog: (dialog_messages.append(dict(type=dialog.type, message=dialog.message, accepted=True)), dialog.accept()))
        with page.expect_navigation(wait_until='networkidle'):
            page.locator('[data-gpante-confirm]').click()
        assert page.locator('#gpante-appearance-title').input_value() == 'دستیار پانته'
        assert page.locator('#gpante-color-mode').input_value() == 'site'
        assert all(page.locator('#gpante-link-label-' + str(index)).input_value() != 'Preview shortcut ' + str(index + 1) for index in range(4))
        results['resetDialogs'] = dialog_messages
        check('unsaved texts/colors/four shortcuts update inert preview with no chat or save request; native reset cancel/confirm is correct')

        php('update_option("gptest_menu_diagnostics_mode","missing",false);')
        visit('gpante-support-diagnostics')
        assert page.locator('.gpante-admin-overview > section').nth(3).locator('.gpante-admin-metric').inner_text() == 'نامشخص'
        assert '$0.00' not in page.locator('.gpante-admin-overview').inner_text()
        php('update_option("gptest_menu_diagnostics_mode","offline",false);')
        visit('gpante-support-diagnostics')
        assert 'پاسخ تأیید نشد' in page.locator('.gpante-admin-overview').inner_text()
        assert page.locator('article.postbox').count() == 0
        assert page.locator('form[method="get"] input[name="search"]').is_visible()
        php('delete_option("gptest_menu_diagnostics_mode");')
        visit('gpante-support-diagnostics')
        page.locator('input[name="from"]').fill('2026-10-29')
        page.locator('input[name="to"]').fill('2026-10-28')
        click('form[method="get"] button')
        assert 'فیلتر یا مقدار واردشده معتبر نیست' in page.locator('.notice-error').inner_text()
        assert page.locator('input[name="from"]').is_visible() and page.locator('input[name="from"]').input_value() == '2026-10-29'
        page.locator('input[name="from"]').fill('2026-10-27')
        click('form[method="get"] button')
        assert page.locator('article.postbox').count() == 20
        check('missing/failed reads stay unknown, and unavailable/invalid filters remain editable and recover after correction')

        page.set_viewport_size(dict(width=390, height=844))
        visit('gpante-support-conversations')
        geometry('archive-list', 390)
        tables = results['viewports'][-1]['geometry']['tables']
        assert any(t['scroll'] > t['width'] for t in tables), tables
        click('.gpante-admin table a[href*="session="]')
        page.locator('.gpante-admin-reference details summary').first.click()
        assert len(page.locator('.gpante-admin-reference code[id]').first.inner_text()) == 64
        geometry('archive-detail', 390)
        visit('gpante-support-diagnostics')
        page.locator('article.postbox').first.locator('summary:has-text("مسیر منابع و تصمیم‌ها")').click()
        page.locator('article.postbox').first.locator('.gpante-admin-reference details summary').click()
        geometry('diagnostics', 390)
        visit('gpante-support-appearance')
        page.locator('#gpante-appearance-title').focus()
        assert page.locator('#gpante-appearance-title').evaluate('(e)=>e===document.activeElement')
        geometry('appearance', 390)
        visit('gpante-support-connection')
        page.locator('.gpante-admin-key-fields summary').focus()
        page.keyboard.press('Enter')
        page.locator('#gpante-chat-key').focus()
        assert page.locator('#gpante-chat-key').evaluate('(e)=>e===document.activeElement')
        geometry('connection', 390)
        assert not errors, errors
        results.update(errors=errors, externalRequestsBlocked=outside, browser=browser.version)
        check('desktop 1440 and mobile 390 contain table scrolling, preserve field focus and have zero JavaScript errors')
        results['backendRequests'] = json.loads(php('echo wp_json_encode(get_option("gptest_menu_calls"));'))
        (root / 'evidence/admin-experience.json').write_text(json.dumps(results, ensure_ascii=False, indent=2) + '\n')
        context.close()
        browser.close()
finally:
    encoded = json.dumps(saved, ensure_ascii=False).encode().hex()
    php('$s=json_decode(hex2bin("' + encoded + '"),true);update_option("gpante_support_appearance",$s["appearance"],false);update_option("gpante_support_runtime",$s["runtime"],false);delete_option("gptest_menu_calls");delete_option("gptest_menu_synthetic");delete_option("gptest_menu_diagnostics_mode");')
    mock.unlink(missing_ok=True)
