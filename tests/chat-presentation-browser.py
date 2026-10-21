"""Opt-in reply bubbles and thumbnail checks; chat requests are intercepted before the server."""
import argparse
import json
from pathlib import Path
from urllib.parse import urljoin

from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', required=True)
parser.add_argument('--evidence-dir', required=True, type=Path)
parser.add_argument('--css', type=Path, help='Inject candidate CSS and formatter in this browser only')
parser.add_argument('--previews', required=True, type=Path, help='Public url/title/thumbnail records verified against WordPress')
parser.add_argument('--engines', nargs='+', choices=['chromium', 'firefox'], default=['chromium', 'firefox'])
args = parser.parse_args()
args.evidence_dir.mkdir(parents=True, exist_ok=True)
source = json.loads(args.previews.read_text())
products = (source['previews'] if isinstance(source, dict) else source)[:2]
assert len(products) == 2
reply = f'''دو پیشنهاد برای بررسی چیدمان؛ همهٔ پیام‌های این آزمون مصنوعی‌اند.

**{products[0]['title']}**
توضیح کوتاهِ پیشنهاد اول در کنار تصویر کوچک آن.

| ویژگی | مشخصات |
| --- | --- |
| قیمت | رایگان |
| فرمت فایل | DXF / CDR |
| متریال | MDF |

[مشاهدهٔ محصول اول]({products[0]['url']})

**{products[1]['title']}**
توضیح کوتاهِ پیشنهاد دوم و تصویر مربوط به همین محصول.

- قیمت: ۱۵۰٬۰۰۰ تومان
- ضخامت: ۲٫۷ میلی‌متر
- متریال: MDF

[مشاهدهٔ محصول دوم]({products[1]['url']})

برای مطالعه: [مقاله](https://gpante.com/synthetic-article/)
<img src=x onerror=alert(1)> [خصوصی](https://gpante.com/wp-content/paid.zip)'''
policy = dict(version=2, userDays=7, archiveDays=30, allowClear=False, revision=4)
results = []


def ready(page):
    page.wait_for_function("document.querySelector('#gpante-support [data-messages]').getAttribute('aria-busy')==='false'")


with sync_playwright() as playwright:
    for engine in args.engines:
        browser = getattr(playwright, engine).launch(headless=True)
        context = browser.new_context(viewport=dict(width=1440, height=1000))
        errors, calls, history = [], [], []

        def mock(route):
            body = route.request.post_data_json
            calls.append(body['action'])
            expiry = '2099-01-01T00:00:00Z'
            data = dict(policy=policy)
            if body['action'] == 'sendMessage':
                history.extend([dict(role='user', content=body['chatInput'], expiresAt=expiry),
                                dict(role='assistant', content=reply, products=products, expiresAt=expiry)])
                data.update(output=reply, products=products, expiresAt=expiry)
            elif body['action'] == 'loadPreviousSession':
                data['messages'] = history
            else:
                raise AssertionError('Unexpected chat action')
            route.fulfill(status=200, json=data)

        context.route('**/wp-json/gpante-support/v1/chat', mock)
        context.route('**/wp-content/uploads/gpante-missing-fixture.jpg', lambda route: route.abort())
        try:
            page = context.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))

            def candidate():
                if not args.css:
                    return
                page.locator('link[href*="/gpante-support-chat/assets/chat.css"]').evaluate_all('(links)=>links.forEach(link=>link.remove())')
                page.add_style_tag(content=args.css.read_text())
                root.evaluate("r=>{const copy=r.cloneNode(true);delete copy.dataset.gpanteInitialized;copy.querySelector('#gpante-support-panel').hidden=true;r.replaceWith(copy)}")
                page.evaluate((Path(__file__).resolve().parents[1]/'wordpress-plugin/gpante-support-chat/assets/chat.js').read_text())

            page.goto(args.url, wait_until='domcontentloaded', timeout=45000)
            root = page.locator('#gpante-support')
            root.wait_for()
            assert urljoin(page.url, root.get_attribute('data-endpoint')).endswith('/wp-json/gpante-support/v1/chat'), 'Unmocked endpoint'
            candidate()
            page.evaluate('document.fonts.ready')
            root.locator('[data-launcher]').click()
            ready(page)
            root.locator('[data-input]').fill('درخواست مصنوعی بررسی چیدمان <b>متن کاربر</b>')
            root.locator('[data-input]').press('Enter')
            ready(page)
            reply_node = root.locator('.gpante-chat-message--assistant').last
            assert reply_node.locator('.gpante-chat-segment').count() == 4
            assert reply_node.locator('table,script,iframe').count() == 0
            assert root.locator('.gpante-chat-message--user img,.gpante-chat-message--user b').count() == 0
            assert reply_node.locator('img').count() == 2
            for img in reply_node.locator('img').all():
                img.scroll_into_view_if_needed()
                img.evaluate("e=>e.decode()")
                assert img.evaluate('e=>e.naturalWidth>0&&e.naturalHeight>0&&!e.hidden')
                assert img.get_attribute('loading') == 'lazy' and img.get_attribute('alt') == ''

            for width, height, expected in [(1440,1000,[390,650]),(390,844,[374,650]),(844,390,[390,366]),(320,568,[304,480])]:
                page.set_viewport_size(dict(width=width,height=height))
                data = reply_node.evaluate('''bubble=>{
                  const root=bubble.closest('#gpante-support'),panel=root.querySelector('#gpante-support-panel');
                  const rect=e=>{const b=e.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,right:b.right,bottom:b.bottom}};
                  const sections=[...bubble.querySelectorAll('.gpante-chat-segment')];
                  return {panel:rect(panel),overflow:panel.scrollWidth>panel.clientWidth+1,sections:sections.map(rect),
                    cards:[...bubble.querySelectorAll('.gpante-chat-segment--card')].map(e=>({rect:rect(e),image:rect(e.querySelector('img')),copy:rect(e.querySelector('.gpante-chat-card-copy')),facts:e.querySelectorAll('li').length,border:getComputedStyle(e).borderTopWidth,rowsFont:getComputedStyle(e.querySelector('li')).fontSize})),
                    links:[...bubble.querySelectorAll('a')].map(e=>({rect:rect(e),parent:rect(e.parentElement),weight:getComputedStyle(e).fontWeight,decoration:getComputedStyle(e).textDecorationLine,href:e.href}))};
                }''')
                assert all(abs(data['panel'][key]-value)<1 for key,value in zip(['width','height'],expected)),data
                assert not data['overflow'] and len(data['cards']) == 2 and len(data['links']) == 3,data
                for index,section in enumerate(data['sections']):
                    if index:
                        assert section['y']-data['sections'][index-1]['bottom'] >= 9, data
                for card in data['cards']:
                    assert card['image']['x'] >= card['copy']['right']+8, card
                    assert card['image']['width'] == card['image']['height'] == 48,card
                    assert card['facts'] == 3 and card['border'] == '1px' and card['rowsFont'] == '13px',card
                for link in data['links']:
                    assert 'paid.zip' not in link['href'] and link['weight']=='400' and link['decoration']=='none',link
                    assert link['rect']['height']>=44 and abs(link['rect']['width']-link['parent']['width'])<1,link
                reply_node.locator('.gpante-chat-segment--card').first.scroll_into_view_if_needed()
                page.screenshot(path=str(args.evidence_dir/f'{engine}-{width}.png'))
                results.append(dict(engine=engine,version=browser.version,viewport=[width,height],**data))
            reply_node.locator('a').first.focus()
            page.keyboard.press('Tab')
            assert page.evaluate('document.activeElement.textContent') == 'مشاهدهٔ محصول دوم'
            image = reply_node.locator('img').first
            image.evaluate("e=>e.src='https://gpante.com/wp-content/uploads/gpante-missing-fixture.jpg'")
            page.wait_for_function("document.querySelector('#gpante-support .gpante-chat-thumbnail').hidden")
            assert products[0]['title'] in reply_node.inner_text()
            page.reload(wait_until='domcontentloaded')
            root.wait_for();candidate();ready(page)
            reply_node = root.locator('.gpante-chat-message--assistant').last
            assert reply_node.locator('.gpante-chat-segment').count() == 4 and reply_node.locator('table').count() == 0
            for img in reply_node.locator('img').all():
                img.scroll_into_view_if_needed();img.evaluate('e=>e.decode()')
                assert img.evaluate('e=>e.naturalWidth>0&&!e.hidden')
            assert calls.count('sendMessage') == 1 and not errors,errors
        finally:
            context.close()
            browser.close()

(args.evidence_dir/'result.json').write_text(json.dumps(dict(mode='candidate' if args.css else 'installed',results=results,
    actual_server_messages=0,model_calls=0,runtime_errors=0,public_thumbnails_loaded=True,separate_bubbles=True,legacy_tables_as_lists=True,keyboard=True,reload=True,image_failure=True),ensure_ascii=False,indent=2))
print(json.dumps(dict(engines=args.engines,viewports_per_engine=4,actual_server_messages=0,model_calls=0,runtime_errors=0,public_thumbnails_loaded=True)))
