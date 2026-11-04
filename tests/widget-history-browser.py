"""Canonical widget smoke in Chromium with synthetic responses and no external network."""
import json
from pathlib import Path
import subprocess

from playwright.sync_api import sync_playwright

repo = Path(__file__).resolve().parents[1]
plugin = repo / 'wordpress-plugin/gpante-support-chat'
markup = subprocess.check_output(['php', str(repo / 'scripts/render-widget.php')], text=True)
html = ('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; '
        'script-src \'unsafe-inline\' \'unsafe-eval\'; style-src \'unsafe-inline\'; connect-src \'none\'">'
        '<style>' + (plugin / 'assets/chat.css').read_text() + '</style><body>' + markup
        + '<script>' + (plugin / 'assets/chat.js').read_text() + '</script></body></html>')
mock = r"""
window.fixture = {
  calls:[], failSend:false, historyResetAt:null,
  policy:{version:2,userDays:7,archiveDays:30,allowClear:true,revision:1},
  history:Array.from({length:8}, (_, i) => ({role:'assistant',
    content:'راهنمای آزمایشی '+i+'\n\n'+('توضیح فایل طراحی. '.repeat(8))+
      '\n\n[محصول](https://gpante.com/product/fixture-'+i+'/)',
    requestId:(i+1).toString(16).padStart(32,'0'),
    expiresAt:new Date(Date.now()+86400000).toISOString()}))
};
window.fetch = async (url, options) => {
  const f=window.fixture, root=document.querySelector('#gpante-support');
  if (url===root.dataset.appearanceEndpoint && options.method==='GET') {
    const appearance=JSON.parse(root.dataset.appearance);
    appearance.links=[{label:'راهنمای آزمایشی',url:'/?guide=1'}];
    return new Response(JSON.stringify(appearance));
  }
  if (url!==root.dataset.endpoint || options.method!=='POST') throw Error('Unexpected network request');
  const request=JSON.parse(options.body);f.calls.push(request);
  let data;
  if (request.action==='loadPreviousSession') data={messages:f.history,
    hasMore:false,nextCursor:null,historyResetAt:f.historyResetAt};
  else if (request.action==='sendMessage') {
    if (f.failSend) return new Response(JSON.stringify({error:{code:'rate_limit'},output:'کمی بعد دوباره تلاش کنید.'}),{status:429});
    const requestId='f'.repeat(32),expiresAt=new Date(Date.now()+86400000).toISOString();
    const output='پاسخ آزمایشی\n\n[محصول](https://gpante.com/product/fixture/)\n\n'
      +'[ناامن](https://example.org/private/)\n\n[طراح](tel:+989179509010)';
    f.history.push({role:'user',content:request.chatInput,requestId,expiresAt},
      {role:'assistant',content:output,requestId,expiresAt});
    data={output,requestId,expiresAt};
  } else throw Error('Unexpected action');
  return new Response(JSON.stringify({...data,policy:f.policy}));
};
"""

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    for width, height in [(1440, 1000), (390, 844)]:
        context = browser.new_context(viewport={'width': width, 'height': height})
        context.add_init_script(mock)
        network, errors = [], []

        def route(request):
            network.append(request.request.url)
            if request.request.url == 'https://gpante.test/':
                request.fulfill(status=200, content_type='text/html', body=html)
            else:
                request.abort()

        context.route('**/*', route)
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto('https://gpante.test/')
        widget = page.locator('#gpante-support')

        def ready():
            page.wait_for_function("document.querySelector('[data-messages]').getAttribute('aria-busy')==='false'")

        def refresh():
            before = page.evaluate('fixture.calls.length')
            page.evaluate("dispatchEvent(new Event('focus'))")
            page.wait_for_function('(before) => fixture.calls.length>before', arg=before)
            ready()

        widget.locator('[data-launcher]').click()
        ready()
        assert widget.locator('[data-shortcuts] a').get_attribute('href') == 'https://gpante.test/?guide=1'
        rect = page.locator('#gpante-support-panel').bounding_box()
        assert rect and rect['x'] >= 0 and rect['x'] + rect['width'] <= width
        assert rect['y'] >= 0 and rect['y'] + rect['height'] <= height

        # Retaining real DOM nodes is necessary: removing a focused link blurs it in Chrome.
        page.evaluate("""() => {
          window.retained=document.querySelector('[data-messages] a');retained.focus({preventScroll:true});
          const scroll=document.querySelector('[data-scroll]');scroll.scrollTop=90;window.readingTop=scroll.scrollTop;
        }""")
        refresh()
        assert page.evaluate('retained.isConnected && document.activeElement===retained')
        assert page.evaluate("document.querySelector('[data-scroll]').scrollTop===readingTop")
        page.evaluate("fixture.history[1].content='توضیح تازه';fixture.history.push({...fixture.history[2],requestId:'e'.repeat(32),content:'پیام تازه'})")
        refresh()
        assert page.evaluate('retained.isConnected && document.activeElement===retained')
        assert page.evaluate("document.querySelector('[data-scroll]').scrollTop===readingTop")
        assert widget.locator('[data-messages]').inner_text().count('توضیح تازه') == 1
        assert widget.locator('[data-messages]').inner_text().count('پیام تازه') == 1
        page.evaluate('fixture.history.splice(1,1)')
        refresh()
        assert 'توضیح تازه' not in widget.locator('[data-messages]').inner_text()
        assert page.evaluate('retained.isConnected && document.activeElement===retained')

        widget.locator('[data-input]').fill('پیش‌نویس آزمایشی')
        page.evaluate('fixture.failSend=true')
        widget.locator('[data-send]').click()
        ready()
        assert widget.locator('[data-input]').input_value() == 'پیش‌نویس آزمایشی'
        assert widget.locator('[data-retry]').is_visible()
        page.evaluate('fixture.failSend=false')
        widget.locator('[data-retry]').click()
        ready()
        sends = page.evaluate("fixture.calls.filter(call=>call.action==='sendMessage')")
        assert len(sends) == 2 and sends[0]['sendId'] == sends[1]['sendId']
        reply = widget.locator('.gpante-chat-message--assistant').last
        assert reply.locator('a[href="https://gpante.com/product/fixture/"]').count() == 1
        assert reply.locator('a[href="tel:+989179509010"]').count() == 1
        assert reply.locator('a[href^="https://example.org"]').count() == 0
        assert reply.locator('a[target="_blank"]').get_attribute('rel') == 'noopener noreferrer'
        refresh()
        assert widget.locator('[data-messages]').inner_text().count('پاسخ آزمایشی') == 1

        page.evaluate('fixture.history=[];fixture.historyResetAt=new Date().toISOString()')
        refresh()
        assert widget.locator('.gpante-chat-message').count() == 1
        assert widget.locator('.gpante-chat-message--welcome').count() == 1
        assert not page.evaluate('retained.isConnected')
        widget.locator('[data-close]').click()
        assert page.locator('#gpante-support-panel').is_hidden()
        assert not errors, errors
        assert network == ['https://gpante.test/'], network
        print(json.dumps({'viewport': [width, height], 'focusAndScroll': True,
                          'updatesAndRemoval': True, 'retryAndSafeLinks': True,
                          'externalRequests': 0, 'modelCalls': 0}), flush=True)
        context.close()
    browser.close()
