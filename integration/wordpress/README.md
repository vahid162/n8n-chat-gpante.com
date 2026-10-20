# اتصال وردپرس و وودمارت

منبع اصلی اتصال اکنون [افزونهٔ GPante Support Chat](../../wordpress-plugin/gpante-support-chat/readme.txt)، نسخهٔ **۰٫۲٫۱** است. PHP، کاتالوگ، template و assetها فقط در پوشهٔ افزونه نگهداری می‌شوند. نصب و اتصال عمومی روی gpante.com بررسی شدند؛ [گزارش تولید](../../docs/production-readiness.md).

## نصب و تنظیم

1. ZIP رسمی را از [Releases](https://github.com/vahid162/n8n-chat-gpante.com/releases) دریافت کنید یا با `python3 scripts/build-plugin.py` بستهٔ مستقل `dist/gpante-support-chat-wordpress-0.2.1.zip` را بسازید. ZIP شامل ۱۱ فایل runtime است؛ کل مخزن، مهارت، تست یا تنظیم خصوصی را در وردپرس نصب نکنید.
2. در وردپرسِ مجاز برای نصب، WooCommerce فعال، PHP حداقل ۸٫۱ و WordPress حداقل ۶٫۶ لازم است. ZIP را از بخش افزودن افزونه بارگذاری و فعال کنید. **نصب تازه چت را خاموش نگه می‌دارد.**
3. از «تنظیمات ← چت جی‌پانته»، کلید اختصاصی را خصوصی وارد کنید. ورودی خالی کلید قبلی را حفظ می‌کند؛ مقدار ذخیره‌شده در HTML یا REST تنظیمات بازنمایش داده نمی‌شود.
4. پس از آماده‌بودن سرویس n8n، HTTPS، پراکسی و cache، نمایش عمومی را روشن کنید. رابط برای همهٔ بازدیدکنندگان از hookهای معمول وردپرس نمایش داده می‌شود؛ به ویرایش قالب نیاز ندارد.

گزینهٔ `gpante_support_runtime` بارگذاری عمومی خودکار ندارد و شامل `schema_version=2`، `enabled`، `backend_url` ثابت و `shared_key` است. ارتقای افزونه migration کوچک و idempotent را اجرا می‌کند؛ کلید، انتخاب قبلی و فیلدهای توسعه حفظ می‌شوند. نسخهٔ schema جدیدتر حفظ و اتصال ناسازگار متوقف می‌شود. غیرفعال‌کردن، تنظیمات را پاک نمی‌کند؛ حذف کامل فقط این option را پاک می‌کند و به دیتابیس یا تاریخچهٔ n8n دست نمی‌زند.

## ارتقا

نسخهٔ WordPress plugin از نسخهٔ workflow و مهارت مستقل است. برای ارتقا، از فایل‌های افزونه و تنظیم خصوصی پشتیبان امن بگیرید، ZIP جدید را در وردپرس بارگذاری و گزینهٔ جایگزینی نسخهٔ موجود را انتخاب کنید؛ سپس تنظیم اتصال، ارسال، تاریخچه و پاک‌کردن را بررسی کنید. پیش از ارتقای معمول، افزونه را **حذف کامل نکنید**؛ uninstall تنظیمات را حذف می‌کند. برای برگشت نسخه، سازگاری `schema_version` و قرارداد API را بررسی کنید. `Update URI` مانع اشتباه با افزونهٔ هم‌نام می‌شود؛ دانلود خودکار از GitHub در این نسخه ساخته نشده است.

## مرز اتصال

قرارداد مرورگر: `POST /wp-json/gpante-support/v1/chat` با action یکی از `sendMessage`، `loadPreviousSession` و `resetSession`؛ ارسال به `chatInput` هم نیاز دارد. template مسیر واقعی REST وردپرس را می‌سازد و permalink ساده هم آزموده شد. مرورگر هویت معتبر نمی‌فرستد؛ سرور cookie امضاشدهٔ Secure/HttpOnly/SameSite=Strict و HMAC از `REMOTE_ADDR` می‌سازد.

Origin دقیق HTTPS از `home_url` مورد اعتماد وردپرس خوانده می‌شود؛ `Host` یا `X-Forwarded-For` دلخواه مبنا نیستند. مقصد n8n ثابت، redirect ممنوع و بررسی TLS روشن است. کلید فقط در درخواست سرور به n8n قرار می‌گیرد. APIهای عمومی `/products` و `/knowledge` مستقل از روشن/خاموش‌بودن چت ثبت می‌شوند و اطلاعات خصوصی/محصول مخفی را نمی‌دهند.

مدل، بودجهٔ ۲ دلار/ماه UTC و کنترل نرخ در سرویس موجود می‌مانند. آرشیو مدیر ۳۰روزه و مدت نمایش کاربر، سراسری و قابل تنظیم ۳/۷/۱۵/۳۰ روز است. روشن‌کردن افزونه Main را منتشر نمی‌کند؛ وضعیت backend باید جدا بررسی شود. اتصال و کاتالوگ تولید اکنون بررسی شده‌اند؛ وضعیت زنده در هر نگهداری دوباره خوانده شود.

## روش اختیاری درج HTML در وودمارت

`woodmart-chat.html` خروجی تولیدی HTML/CSS/JavaScript است. `node scripts/build-widget.cjs` آن را از template و assetهای **همان افزونه** می‌سازد؛ خروجی را مستقل ویرایش نکنید. به PHP CLI برای render template نیاز دارد.

اگر این روش انتخاب شد، ثابت سروری `GPANTE_SUPPORT_INLINE_WIDGET=true` را پیش از بارگذاری افزونه تعریف و HTML را فقط یک‌بار در فوتر مشترک وودمارت درج کنید. ثابت، نمایش خودکار و enqueue را خاموش می‌کند؛ واسط و کاتالوگ برقرار می‌مانند. چسباندن HTML به‌تنهایی سرویس سروری را ایجاد نمی‌کند. برای روش معمول افزونه، embed قبلی را بردارید و این ثابت را تعریف نکنید. `bridge.php` و `catalog.php` این پوشه فقط loader سازگاری برای مسیر قدیمی‌اند؛ هم‌زمان با نصب معمول، جدا بارگذاری نشوند.

## آزمون

۴۴ آزمون Node، آزمون تنظیمات/مهاجرت PHP، واسط امن و ۱۰ حالت query/Schema موفق‌اند. نصب محلی با WordPress **۷٫۱٫۳**، WooCommerce **۱۱٫۲٫۱** و PHP **۸٫۴٫۲۵** بررسی شد؛ [گزارش مرحلهٔ محلی](../../specs/001-wordpress-plugin-basic/verification.md). بررسی بعدی روی PHP **۸٫۳٫۳۲** و قالب واقعی WoodMart **۸٫۶٫۴**، ارسال واقعی، تاریخچه، حذف، نشست جدا، خطا/تلاش مجدد و سه اندازهٔ صفحه را پوشش داد؛ [گزارش تولید](../../docs/production-readiness.md). این بررسی، تأیید همهٔ نسخه‌های حداقل، Safari/iOS یا multisite نیست.

پیش‌نمایش مستقل: `node scripts/preview-widget.cjs /tmp/gpante-chat-local-preview.html`. شبکهٔ مصنوعی و CSP مسدودکننده دارد؛ برای درج در سایت نیست. تست WordPress نیز با fixture خارج بسته به backend مصنوعی وصل می‌شود، نه n8n یا مدل واقعی.

پیش از نصب تولیدِ مجاز، هویت IP پشت پراکسی، HTTPS/cookie، استثنای cache/CDN برای chat، timeout حداقل ۱۵۵ ثانیه و نمایش با قالب واقعی بررسی شوند. کد افزونه timeout پراکسی یا وب‌سرور را تغییر نمی‌دهد.

منابع توسعه: [هدر افزونه](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/)، [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)، [enqueue](https://developer.wordpress.org/plugins/themes/including-css-javascript/)، [وودمارت و HTML Block](https://woodmarttheme.com/docs/html-blocks-usage/).

## ارتقای آرشیو 0.2.0

نسخهٔ جدید ۱۱ فایل runtime، schema تنظیمات ۲ و قرارداد عمومی سیاست ۲ دارد. پیش از ZIP، migration 003 و Main/Admin سازگار آماده شوند. کلید مدیریت مستقل را تنظیم کنید؛ کلید و enabled قبلی حفظ می‌شوند. مسیر «چت پانته ← گفتگوها» سیاست سراسری و آرشیو را نشان می‌دهد. [رفتار پاک‌کردن، مدت‌ها، امنیت و rollback](../../docs/conversation-archive.md).
