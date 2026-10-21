"""Opt-in browser layout checks with synthetic chat responses; never call the model."""
import argparse
import json
from pathlib import Path
from urllib.parse import urljoin

from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', required=True)
parser.add_argument('--evidence-dir', required=True, type=Path)
parser.add_argument('--css', type=Path, help='Inject candidate CSS and formatter only in the test browser')
parser.add_argument('--observe', action='store_true', help='Record the old layout without enforcing the fix')
parser.add_argument('--engines', nargs='+', choices=['chromium', 'firefox', 'webkit'], default=['chromium', 'firefox'])
args = parser.parse_args()
args.evidence_dir.mkdir(parents=True, exist_ok=True)

reply = '''**باکس آجیل — نمونهٔ آزمایشی**

یک گزینه برای ساخت باکس آجیل.
این توضیح در خط بعد هم خوانا می‌ماند.

| ویژگی | مشخصات |
| --- | --- |
| قیمت | رایگان |
| فرمت فایل | DXF / CDR |
| متریال | MDF |
| ضخامت | ۲٫۷ میلی‌متر |

[محصول](https://gpante.com/product/synthetic/) [مقاله](https://gpante.com/synthetic-article/)
https://gpante.com/synthetic-guide/

اطلاعات بیشتر: [راهنمای انتخاب طرح مناسب برای ساخت با دستگاه برش لیزر](https://gpante.com/synthetic-help/)

<img src=x onerror=alert(1)> [خصوصی](https://gpante.com/wp-content/paid.zip)'''
wide = '| ویژگی | گزینه اول | گزینه دوم | گزینه سوم |\n| --- | --- | --- | --- |\n| فرمت | DXF | [راهنما](https://gpante.com/wide-help/) | SVG |'
policy = dict(version=2, userDays=7, archiveDays=30, allowClear=False, revision=4)
results = []

def ready(page):
    page.wait_for_function("document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")

with sync_playwright() as playwright:
    for engine in args.engines:
        browser = getattr(playwright, engine).launch(headless=True)
        context = browser.new_context(viewport=dict(width=1440, height=1000), ignore_https_errors=False)
        errors, calls, history = [], [], []
        state = dict(reply=reply)

        def mock(route):
            body = route.request.post_data_json
            calls.append(body['action'])
            expiry = '2099-01-01T00:00:00Z'
            data = dict(policy=policy)
            if body['action'] == 'sendMessage':
                history.extend([dict(role='user', content=body['chatInput'], expiresAt=expiry),
                                dict(role='assistant', content=state['reply'], expiresAt=expiry)])
                data.update(output=state['reply'], expiresAt=expiry)
            elif body['action'] == 'loadPreviousSession':
                data['messages'] = history
            else:
                raise AssertionError('Unexpected chat action')
            route.fulfill(status=200, json=data)

        context.route('**/wp-json/gpante-support/v1/chat', mock)
        try:
            page = context.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(args.url, wait_until='domcontentloaded')
            root = page.locator('#gpante-support')
            root.wait_for()
            assert urljoin(page.url, root.get_attribute('data-endpoint')).endswith('/wp-json/gpante-support/v1/chat'), 'Unmocked endpoint'
            if args.css:
                page.add_style_tag(content=args.css.read_text())
                root.evaluate("r=>{const copy=r.cloneNode(true);delete copy.dataset.gpanteInitialized;copy.querySelector('#gpante-support-panel').hidden=true;r.replaceWith(copy)}")
                page.evaluate((Path(__file__).resolve().parents[1]/'wordpress-plugin/gpante-support-chat/assets/chat.js').read_text())
            page.evaluate('document.fonts.ready')
            root.locator('[data-launcher]').click()
            ready(page)
            root.locator('[data-input]').fill('درخواست مصنوعی بررسی چیدمان')
            root.locator('[data-input]').press('Enter')
            ready(page)
            for width, height, expected in [(1440,1000,[390,650]), (390,844,[374,650]), (844,390,[390,366]), (320,568,[304,480])]:
                page.set_viewport_size(dict(width=width, height=height))
                data = root.evaluate('''r => {
                  const panel=r.querySelector('#gpante-support-panel'), bubble=r.querySelector('.gpante-chat-message--rich'), table=bubble.querySelector('table');
                  const rect=e=>{const b=e.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,bottom:b.bottom}};
                  return {panel:rect(panel), bubble:rect(bubble), table:rect(table), font:getComputedStyle(bubble).fontSize,
                    cellPadding:getComputedStyle(table.querySelector('td')).paddingTop,
                    links:[...bubble.querySelectorAll('a')].map(a=>({rect:rect(a),parent:rect(a.parentElement),weight:getComputedStyle(a).fontWeight,decoration:getComputedStyle(a).textDecorationLine,href:a.href})),
                    unsafeMarkup:!!bubble.querySelector('img,script,iframe'),overflow:panel.scrollWidth>panel.clientWidth+1};
                }''')
                assert len(data['links']) == 4 and not data['unsafeMarkup']
                assert all('paid.zip' not in link['href'] for link in data['links'])
                assert all(abs(data['panel'][key]-value)<1 for key,value in zip(['width','height'],expected)), data
                assert not data['overflow'] and data['font'] == '14px', data
                if not args.observe:
                    assert float(data['cellPadding'][:-2]) <= 6, data
                    for index, link in enumerate(data['links']):
                        assert link['weight']=='400' and link['decoration']=='none', link
                        assert link['rect']['height']>=44 and abs(link['rect']['width']-link['parent']['width'])<1, link
                        if index:
                            assert link['rect']['y'] >= data['links'][index-1]['rect']['bottom'], data['links']
                            if index < 3:
                                assert link['rect']['y']-data['links'][index-1]['rect']['bottom'] <= 8, 'Blank separator line between buttons'
                bubble = root.locator('.gpante-chat-message--rich')
                bubble.locator('a').first.scroll_into_view_if_needed()
                page.screenshot(path=str(args.evidence_dir/f'{engine}-{width}.png'))
                results.append(dict(engine=engine, version=browser.version, viewport=[width,height], **data))
            root.locator('.gpante-chat-message--rich a').first.focus()
            page.keyboard.press('Tab')
            assert page.evaluate('document.activeElement.textContent') == 'مقاله'
            state['reply'] = wide
            root.locator('[data-input]').fill('جدول عریض مصنوعی')
            root.locator('[data-input]').press('Enter')
            ready(page)
            wrapper = root.locator('.gpante-chat-table-wrap[tabindex]')
            assert wrapper.get_attribute('role') == 'region'
            if not args.observe:
                assert wrapper.locator('a').evaluate("a=>getComputedStyle(a).fontWeight==='400'&&a.getBoundingClientRect().height>=44&&a.getBoundingClientRect().width<=a.parentElement.getBoundingClientRect().width")
            wrapper.focus()
            before = wrapper.evaluate('e=>e.scrollLeft')
            page.keyboard.press('ArrowLeft')
            page.wait_for_function('(before)=>document.querySelector("#gpante-support .gpante-chat-table-wrap[tabindex]").scrollLeft!==before', arg=before)
            page.reload(wait_until='domcontentloaded')
            root.wait_for()
            if args.css:
                page.add_style_tag(content=args.css.read_text())
                root.evaluate("r=>{const copy=r.cloneNode(true);delete copy.dataset.gpanteInitialized;copy.querySelector('#gpante-support-panel').hidden=true;r.replaceWith(copy)}")
                page.evaluate((Path(__file__).resolve().parents[1]/'wordpress-plugin/gpante-support-chat/assets/chat.js').read_text())
            ready(page)
            assert root.locator('.gpante-chat-message--rich').count() == 2
            assert calls.count('sendMessage') == 2 and not errors, errors
        finally:
            context.close()
            browser.close()

(args.evidence_dir/'result.json').write_text(json.dumps(dict(mode='baseline' if args.observe else 'candidate' if args.css else 'installed',
    results=results, actual_server_messages=0, model_calls=0, runtime_errors=0, wide_table_keyboard=True, reload=True), ensure_ascii=False, indent=2))
print(json.dumps(dict(engines=args.engines, viewports_per_engine=4, actual_server_messages=0, model_calls=0, runtime_errors=0)))
