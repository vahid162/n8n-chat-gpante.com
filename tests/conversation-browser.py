"""Offline Chromium/Firefox conversation regressions. Every request is intercepted; no model or production traffic."""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--evidence-dir', required=True, type=Path)
parser.add_argument('--engines', nargs='+', choices=['chromium', 'firefox'], default=['chromium', 'firefox'])
args = parser.parse_args()
args.evidence_dir.mkdir(parents=True, exist_ok=True)
repo = Path(__file__).resolve().parents[1]
markup = subprocess.check_output(['php', str(repo/'scripts/render-widget.php')], text=True)
html = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + (repo/'wordpress-plugin/gpante-support-chat/assets/chat.css').read_text() + '</style><body>' + markup + '<script>' + (repo/'wordpress-plugin/gpante-support-chat/assets/chat.js').read_text() + '</script></body></html>'
policy = dict(version=2, userDays=30, archiveDays=30, allowClear=True, revision=1)
expiry = '2099-01-01T00:00:00Z'
results = []

with sync_playwright() as playwright:
    for engine in args.engines:
        browser = getattr(playwright, engine).launch(headless=True)
        context = browser.new_context(viewport=dict(width=390, height=844))
        turns = [dict(requestId=hashlib.md5(str(i).encode()).hexdigest(), question=f'پرسش {i}', answer=f'پاسخ {i}', feedback=None) for i in range(1, 8)]
        sends, calls, errors = {}, [], []
        state = dict(reset=None, lost=False, unavailable=False)

        def intercept(route):
            request = route.request
            if request.is_navigation_request():
                route.fulfill(status=200, content_type='text/html', body=html)
                return
            if request.url.endswith('/appearance'):
                route.fulfill(status=200, json={})
                return
            assert request.url.endswith('/chat'), request.url
            body = request.post_data_json
            action = body['action']
            calls.append(action)
            data = dict(policy=policy)
            status = 200
            if action == 'loadPreviousSession':
                page_turns = turns[:1] if body.get('historyCursor') else turns[-6:]
                data.update(messages=[dict(role=role, content=turn[field], requestId=turn['requestId'], feedback=turn['feedback'], expiresAt=expiry)
                    for turn in page_turns for role, field in [('user', 'question'), ('assistant', 'answer')]],
                    hasMore=bool(len(turns)>6 and not body.get('historyCursor')),
                    nextCursor='3' if len(turns)>6 and not body.get('historyCursor') else None, historyResetAt=state['reset'])
            elif action == 'sendMessage':
                assert len(body['sendId']) == 32
                if state['unavailable']:
                    status = 503
                    data = dict(error=dict(code='monthly_budget'), output='سهمیهٔ ماه تمام شده است.')
                else:
                    turn = sends.get(body['sendId'])
                    if turn is None:
                        turn = dict(requestId=hashlib.md5(body['sendId'].encode()).hexdigest(), question=body['chatInput'], answer='پاسخ ارسال پایدار', feedback=None)
                        sends[body['sendId']] = turn
                        turns.append(turn)
                    if not state['lost']:
                        state['lost'] = True
                        status = 502
                        data = dict(error=dict(code='backend_unavailable'), output='پاسخ شبکه دریافت نشد.')
                    else:
                        data.update(output=turn['answer'], requestId=turn['requestId'], expiresAt=expiry)
            elif action == 'feedback':
                turn = next(turn for turn in turns if turn['requestId'] == body['feedbackRequestId'])
                turn['feedback'] = body['feedbackValue']
                data['ok'] = True
            elif action == 'resetSession':
                turns.clear()
                state['reset'] = '2026-10-24T12:00:00Z'
                data['ok'] = True
            else:
                raise AssertionError(action)
            route.fulfill(status=status, json=data)

        context.route('**/*', intercept)
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        try:
            page.goto('http://127.0.0.1:8877/', wait_until='domcontentloaded')
            root = page.locator('#gpante-support')

            def ready():
                page.wait_for_function("document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")

            root.locator('[data-launcher]').click()
            ready()
            assert root.locator('.gpante-chat-message').count() == 12
            root.locator('[data-older]').click()
            ready()
            assert root.locator('.gpante-chat-message').count() == 14
            page.evaluate("window.dispatchEvent(new Event('focus'))")
            ready()
            assert root.locator('.gpante-chat-message').count() == 14, 'Refresh discarded loaded old history'
            root.locator('[data-input]').fill('ارسال مصنوعی پایدار')
            root.locator('[data-send]').click()
            ready()
            root.locator('[data-reconnect]').click()
            ready()
            root.locator('[data-retry]').click()
            ready()
            assert len(sends) == 1 and calls.count('sendMessage') == 2, 'Explicit retry created a second logical turn'
            assert root.locator('.gpante-chat-message--assistant').filter(has_text='پاسخ ارسال پایدار').count() == 1, 'Replay duplicated answer'
            helpful = root.locator('.gpante-chat-message--assistant').last.get_by_role('button', name='مفید بود', exact=True)
            helpful.click()
            ready()
            assert helpful.get_attribute('aria-pressed') == 'true'
            assert turns[-1]['feedback'] == 'helpful'
            for width, height, expected in [(1440, 1000, [390, 650]), (390, 844, [374, 650]), (320, 568, [304, 480]), (844, 390, [390, 366])]:
                page.set_viewport_size(dict(width=width, height=height))
                geometry = root.locator('#gpante-support-panel').bounding_box()
                assert abs(geometry['width']-expected[0])<1 and abs(geometry['height']-expected[1])<1, geometry
                assert helpful.evaluate('e=>e.getBoundingClientRect().height') >= 44
            page.set_viewport_size(dict(width=390, height=844))
            state['unavailable'] = True
            root.locator('[data-input]').fill('جست‌وجوی طرح اسب')
            root.locator('[data-send]').click()
            ready()
            fallback = root.locator('[data-fallback]')
            assert fallback.is_visible() and 's=' in fallback.get_attribute('href')
            assert fallback.evaluate('e=>new URL(e.href).origin') == 'https://gpante.com'
            root.locator('[data-new]').click()
            root.locator('[data-clear]').click()
            ready()
            assert root.locator('[data-older]').is_hidden() and root.locator('.gpante-chat-message').count() == 1
            assert not errors, errors
            page.screenshot(path=str(args.evidence_dir/f'{engine}-conversation.png'))
            results.append(dict(engine=engine, version=browser.version, historyPages=True, olderSurvivesRefresh=True,
                sendIdReplay=True, feedback=True, fallback=True, clear=True, geometry=True, modelCalls=0, errors=errors))
        finally:
            context.close()
            browser.close()
(args.evidence_dir/'conversation-results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2)+'\n')
print(json.dumps(results, ensure_ascii=False))
