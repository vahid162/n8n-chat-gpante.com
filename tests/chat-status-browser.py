"""Opt-in status UX checks on the actual widget; all chat responses are intercepted."""
import argparse
import json
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', required=True)
parser.add_argument('--evidence-dir', type=Path, required=True)
parser.add_argument('--candidate', action='store_true', help='Inject canonical markup/assets only in this browser')
parser.add_argument('--engines', nargs='+', choices=['chromium', 'firefox'], default=['chromium', 'firefox'])
args = parser.parse_args()
args.evidence_dir.mkdir(parents=True, exist_ok=True)
repo = Path(__file__).resolve().parents[1]
policy = dict(version=2, userDays=7, archiveDays=30, allowClear=True, revision=4)
results = []

with sync_playwright() as playwright:
    for engine in args.engines:
        browser = getattr(playwright, engine).launch(headless=True)
        context = browser.new_context(viewport=dict(width=1440, height=1000))
        held, actions, errors = [], [], []

        def intercept(route):
            action = route.request.post_data_json['action']
            actions.append(action)
            held.append((action, route))

        context.route('**/wp-json/gpante-support/v1/chat', intercept)
        try:
            page = context.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(args.url, wait_until='domcontentloaded', timeout=45000)
            root = page.locator('#gpante-support')
            root.wait_for()
            baseline = {}
            for width, height in [(1440,1000),(390,844),(320,568),(844,390)]:
                page.set_viewport_size(dict(width=width,height=height))
                baseline[width] = root.evaluate('''r=>{const p=r.querySelector('#gpante-support-panel'),n=r.querySelector('[data-new]'),hidden=n.hidden;p.hidden=false;n.hidden=false;
                  const h=r.querySelector('.gpante-chat-header').getBoundingClientRect().height,
                    m=r.querySelector('[data-scroll]').getBoundingClientRect().height,s=r.querySelector('[data-status]'),
                    footer=r.querySelector('.gpante-chat-header').contains(s)?0:s.getBoundingClientRect().height;
                  p.hidden=true;n.hidden=hidden;return {header:h,messages:m,footer};}''')
            page.set_viewport_size(dict(width=1440,height=1000))
            if args.candidate:
                markup = subprocess.check_output(['php', str(repo/'scripts/render-widget.php')], text=True)
                root.evaluate('''(old, html) => {
                  const container=document.createElement('div');container.innerHTML=html;
                  const fresh=container.firstElementChild;
                  for(const key of ['endpoint','appearance','appearanceEndpoint'])fresh.dataset[key]=old.dataset[key];
                  fresh.style.cssText=old.style.cssText;old.replaceWith(fresh);
                }''', markup)
                page.locator('link[href*="/gpante-support-chat/assets/chat.css"]').evaluate_all('(links)=>links.forEach(link=>link.remove())')
                page.add_style_tag(content=(repo/'wordpress-plugin/gpante-support-chat/assets/chat.css').read_text())
                page.evaluate((repo/'wordpress-plugin/gpante-support-chat/assets/chat.js').read_text())
            assert root.get_attribute('data-endpoint') == '/wp-json/gpante-support/v1/chat', 'Unmocked endpoint'

            def state(value):
                page.wait_for_function('(value)=>document.querySelector("#gpante-support [data-status]").dataset.state===value', arg=value)

            def respond(action, data=None, status=200):
                for _ in range(100):
                    if held:
                        break
                    page.wait_for_timeout(20)
                assert held and held[0][0] == action, (action, actions)
                _, route = held.pop(0)
                route.fulfill(status=status, json=dict(policy=policy, expiresAt='2099-01-01T00:00:00Z', **(data or {})))

            root.locator('[data-launcher]').click()
            state('connecting')
            assert root.locator('[data-input]').is_enabled()
            root.locator('[data-input]').fill('پیش‌نویس هنگام اتصال')
            assert root.locator('.gpante-chat-status-icon').evaluate("e=>getComputedStyle(e).animationName") != 'none'
            assert root.locator('.gpante-chat-status-icon').get_attribute('aria-hidden') == 'true'
            page.screenshot(path=str(args.evidence_dir/f'{engine}-connecting.png'))
            respond('loadPreviousSession', dict(messages=[]))
            state('ready')
            assert root.locator('[data-input]').input_value() == 'پیش‌نویس هنگام اتصال'
            assert root.locator('[data-status-label]').inner_text() == 'Online'
            assert root.locator('.gpante-chat-header [data-status]').count() == 1
            assert root.locator('[data-notice]').is_hidden()
            assert root.locator('.gpante-chat-status-icon').evaluate("e=>getComputedStyle(e).animationName") == 'none'
            for width, height, expected in [(1440,1000,[390,650]), (390,844,[374,650]), (320,568,[304,480]), (844,390,[390,366])]:
                page.set_viewport_size(dict(width=width, height=height))
                root.locator('[data-status]').scroll_into_view_if_needed()
                geometry = root.evaluate('''r=>{
                  const panel=r.querySelector('#gpante-support-panel'),status=r.querySelector('[data-status]'),b=panel.getBoundingClientRect();
                  return {panel:[b.width,b.height],overflow:panel.scrollWidth>panel.clientWidth+1,
                    statusOverflow:status.scrollWidth>status.clientWidth+1,font:getComputedStyle(r.querySelector('[data-status-label]')).fontSize,
                    header:r.querySelector('.gpante-chat-header').getBoundingClientRect().height,messages:r.querySelector('[data-scroll]').getBoundingClientRect().height,
                    closeWidth:r.querySelector('[data-close]').getBoundingClientRect().width,
                    titleTruncation:getComputedStyle(r.querySelector('[data-title]')).textOverflow};
                }''')
                assert all(abs(a-b)<1 for a,b in zip(geometry['panel'], expected)), geometry
                assert not geometry['overflow'] and not geometry['statusOverflow'] and float(geometry['font'][:-2])>=12, geometry
                assert geometry['closeWidth'] >= 44 and geometry['titleTruncation'] == 'ellipsis', geometry
                assert geometry['header'] <= baseline[width]['header'] + 1, (geometry,baseline[width])
                if args.candidate and height > 520:
                    assert geometry['messages'] >= baseline[width]['messages'] + baseline[width]['footer'] - 1, (geometry,baseline[width])
                page.screenshot(path=str(args.evidence_dir/f'{engine}-ready-{width}.png'))
                results.append(dict(engine=engine, version=browser.version, viewport=[width,height], **geometry))

            page.set_viewport_size(dict(width=390,height=844))
            root.locator('[data-input]').fill('پرسش آزمایشی')
            root.locator('[data-input]').press('Enter')
            state('ready')
            root.locator('[data-activity]').wait_for(state='visible')
            assert root.locator('.gpante-chat-dots i').first.evaluate("e=>getComputedStyle(e).animationName") != 'none'
            page.emulate_media(reduced_motion='reduce')
            assert root.locator('.gpante-chat-dots i').first.evaluate("e=>getComputedStyle(e).animationName") == 'none'
            page.emulate_media(reduced_motion='no-preference')
            respond('sendMessage', dict(error=dict(code='backend_unavailable'), output='دریافت پاسخ تأیید نشد.'), 502)
            state('offline')
            assert root.locator('[data-input]').input_value() == 'پرسش آزمایشی'
            assert root.locator('[data-retry]').is_hidden()
            root.locator('[data-reconnect]').click()
            state('connecting')
            assert root.locator('[data-reconnect]').is_disabled()
            respond('loadPreviousSession', dict(error=dict(code='backend_unavailable'),output='خطای آزمایشی اتصال'),502)
            state('offline')
            assert root.locator('[data-messages] .gpante-chat-message').count() == 1, 'Failed refresh erased welcome'
            root.locator('[data-reconnect]').click()
            respond('loadPreviousSession', dict(messages=[dict(role='user',content='پرسش آزمایشی',expiresAt='2099-01-01T00:00:00Z'),
                dict(role='assistant',content='پاسخ ثبت‌شدهٔ مصنوعی',expiresAt='2099-01-01T00:00:00Z')]))
            state('ready')
            assert actions.count('sendMessage') == 1, 'Reconnect resent an uncertain turn'
            assert 'درخواست تازه' in root.locator('[data-status-text]').inner_text()
            assert root.locator('[data-messages]').inner_text().endswith('پاسخ ثبت‌شدهٔ مصنوعی')
            root.locator('[data-retry]').click()
            respond('sendMessage',dict(output='پاسخ کاملاً مصنوعی'))
            root.locator('[data-activity]').wait_for(state='hidden')
            assert root.locator('[data-notice]').is_hidden()
            root.locator('[data-input]').fill('پرسش هنگام محدودیت')
            root.locator('[data-input]').press('Enter')
            respond('sendMessage',dict(error=dict(code='rate_limit'),output='۱ دقیقه صبر کنید، سپس روی «تلاش دوباره» بزنید.'),429)
            root.locator('[data-retry]').wait_for(state='visible')
            state('ready')
            assert '۱ دقیقه' in root.locator('[data-status-text]').inner_text()
            assert root.locator('[data-reconnect]').is_hidden()
            root.locator('[data-retry]').click()
            respond('sendMessage',dict(output='پاسخ پس از تلاش صریح'))
            root.locator('[data-activity]').wait_for(state='hidden')
            root.locator('[data-input]').fill('متن محفوظ هنگام قطع اینترنت')
            context.set_offline(True)
            state('offline')
            assert root.locator('[data-input]').is_enabled()
            assert root.locator('[data-reconnect]').is_visible()
            context.set_offline(False)
            state('connecting')
            respond('loadPreviousSession', dict(messages=[]))
            state('ready')
            assert root.locator('[data-input]').input_value() == 'متن محفوظ هنگام قطع اینترنت'
            assert actions.count('sendMessage') == 4 and actions.count('loadPreviousSession') == 4, actions
            # A long administrator title must not displace the badge or close control.
            page.set_viewport_size(dict(width=320,height=568))
            root.locator('[data-title]').evaluate("e=>e.textContent='عنوان طولانی دستیار هوشمند برای بررسی فضای سربرگ در موبایل'")
            assert root.locator('[data-title]').evaluate('e=>e.scrollWidth>e.clientWidth')
            assert root.locator('[data-status]').is_visible() and root.locator('[data-close]').is_visible()
            assert root.locator('.gpante-chat-header').evaluate('e=>e.scrollWidth<=e.clientWidth+1')
            page.screenshot(path=str(args.evidence_dir/f'{engine}-compact-header.png'))
            assert not held and not errors, errors
        finally:
            context.close()
            browser.close()

(args.evidence_dir/'result.json').write_text(json.dumps(dict(mode='candidate' if args.candidate else 'installed', results=results,
    actual_server_messages=0, model_calls=0, runtime_errors=0, reduced_motion=True, offline_reconnect=True), ensure_ascii=False, indent=2))
print(json.dumps(dict(engines=args.engines, viewports_per_engine=4, runtime_errors=0, actual_server_messages=0, model_calls=0)))
