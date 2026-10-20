'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { buildWidget } = require('./build-widget.cjs');

// This harness is only for an offline browser preview, never for the live embed.
const mock = `
const previewMessages = [];
window.fetch = async function (url, options) {
  if (url === '/wp-json/gpante-support/v1/appearance') return new Response(document.getElementById('gpante-support').dataset.appearance, {headers:{'Content-Type':'application/json'}});
  if (url !== '/wp-json/gpante-support/v1/chat') throw new TypeError('Offline preview blocks network requests.');
  const mode = document.getElementById('preview-mode').value;
  if (mode === 'network') throw new TypeError('Simulated offline state');
  if (mode === 'error') return new Response(JSON.stringify({error:'unavailable',output:'خطای آزمایشی؛ حالت موفق را انتخاب و تلاش دوباره را بزنید.'}), {status:503});
  if (mode === 'malformed') return new Response('<invalid-json>');
  const request = JSON.parse(options.body);
  let result;
  if (request.action === 'loadPreviousSession') result = {messages:previewMessages};
  else if (request.action === 'resetSession') { previewMessages.length = 0; result = {ok:true}; }
  else if (request.action === 'sendMessage') {
    const output = 'این پاسخ کاملاً آزمایشی است و به سایت یا هوش مصنوعی متصل نیست.\\nپرسش شما: ' + request.chatInput;
    previewMessages.push({role:'user',content:request.chatInput},{role:'assistant',content:output});
    result = {output};
  } else return new Response('{}', {status:400});
  result.policy={version:2,userDays:30,archiveDays:30,allowClear:true,revision:1};
  result.expiresAt=new Date(Date.now()+86400000).toISOString();
  previewMessages.forEach(m=>m.expiresAt=result.expiresAt);
  return new Response(JSON.stringify(result), {headers:{'Content-Type':'application/json'}});
};
`;

function buildPreview() {
  return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'; base-uri \'none\'; form-action \'none\'">'
    + '<title>پیش‌نمایش محلی چت پانته</title></head><body style="font-family:Tahoma,sans-serif;line-height:2;background:#f3f5f1;color:#263023;padding:24px">'
    + '<h1>پیش‌نمایش محلی چت پانته</h1><p>داده‌ها مصنوعی و فقط در حافظهٔ همین صفحه‌اند؛ با بارگذاری مجدد پاک می‌شوند. اتصال شبکه و تماس مدل مسدود است.</p>'
    + '<label for="preview-mode">حالت آزمایش: </label><select id="preview-mode"><option value="success">پاسخ موفق</option><option value="error">خطای سرویس</option><option value="network">قطع ارتباط</option><option value="malformed">پاسخ نامعتبر</option></select>'
    + '<p>دکمهٔ چت پایین صفحه را باز کنید. پس از خطا، حالت موفق را انتخاب و «تلاش دوباره» را بزنید. برای بررسی موبایل، اندازهٔ نمایش را در مرورگر تغییر دهید.</p>'
    + '<script>' + mock + '</script>\n' + buildWidget() + '</body></html>\n';
}
if (require.main === module) {
  const output = path.resolve(process.argv[2] || '/tmp/gpante-chat-local-preview.html');
  fs.writeFileSync(output, buildPreview());
  console.log('Offline widget preview: ' + output);
}
module.exports = { buildPreview };
