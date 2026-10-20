# نتیجهٔ بررسی افزونهٔ پایه

۲۰ اکتبر ۲۰۲۶، محدودهٔ محلی. WordPress plugin **0.1.0**؛ schema **1**؛ API **v1**؛ مهارت **0.4.0**. سایت، قالب، افزونه‌های نصب‌شدهٔ تولید و workflow زنده تغییر نکردند. تماس مدل: صفر.

## محیط و نتیجه

| بررسی | شاهد و نتیجه |
| --- | --- |
| PHP lint / WordPress Coding Standards | همهٔ PHPهای runtime؛ WPCS 3.4.1 / PHPCS 3.13.6؛ صفر خطا و هشدار |
| Node 24.22.0 | `node --test --test-reporter=spec tests/*.test.cjs`؛ ۴۰ موفق |
| تنظیمات و migration | `php tests/plugin-settings.php`؛ پیش‌فرض خاموش، دامنهٔ کلید، کلید خالی، نقش، تکرار sanitizer/migration و schema آینده موفق |
| bridge / catalog mock | `php tests/widget-bridge.php` و `php tests/catalog-query.php`؛ امنیت قرارداد و هشت حالت خطای SQL/صفحه‌بندی موفق |
| WordPress واقعی | `tests/wordpress-plugin-integration.php` با WP-CLI؛ ۱۷ بررسی موفق؛ WP 7.1.3 / WooCommerce 11.2.1 / PHP 8.4.25 / MariaDB 11.4 |
| HTTP واقعی تنظیمات | `tests/wordpress-settings-http.py`؛ nonce نامعتبر رد، مدیر ذخیره، کلید خالی حفظ، مشترک بدون تغییر تنظیمات رد شد؛ WooCommerce مشترک را به My account هدایت می‌کند |
| Chrome واقعی | `tests/wordpress-browser.py` با agent-browser 0.39.1 / Chrome 155.0.8059.84؛ ارسال و لینک امن، بازیابی تاریخچه، خطا/retry، reset و Escape موفق؛ خطای runtime صفر |
| اندازه‌های مرورگر | 1440×1000، 390×844، 844×390؛ پنجره داخل viewport و کنترل‌ها قابل دسترس؛ پنجره در ارتفاع کوتاه اسکرول دارد |
| ZIP | ۱۰ فایل allowlist؛ byte-for-byte با منبع؛ header/readme version برابر؛ نصب واقعی ZIP با WP-CLI و حفظ تنظیم فعال/schema=1 موفق؛ مرورگر روی ZIP نصب‌شده نیز تکرار شد |

کاتالوگ WooCommerce واقعی visible/catalog/search را برگرداند و hidden/private/passworded را حذف کرد؛ قیمت صفر و total صفحهٔ خارج محدوده حفظ شدند. این fixture محدود، سقف تولید نیست. مدل/ledger/schema کاربردی n8n در این آزمون‌ها لمس نشدند.

## اصلاح‌های حاصل از آزمون

1. Schema بومی WooCommerce، `prices` را پیش از JSON به‌صورت stdClass می‌دهد؛ فقط همان شکلِ قابل اعتماد به آرایه تبدیل و allowlist حفظ شد. آزمون واقعی ابتدا شکست خورد و پس از اصلاح موفق شد.
2. Settings API می‌تواند sanitizer را تکرار کند؛ ورودی معتبر boolean خروجی خود تابع هم پذیرفته می‌شود تا enabled ناخواسته false نشود. آزمون تکرار و ذخیرهٔ HTTP این رفتار را بررسی می‌کند.
3. کنترل غیرفعال/مخفی در Chrome فوکوس را به body می‌برد؛ پس از تکمیل درخواست، فوکوسِ گم‌شده در همان رابط بازیابی می‌شود و فوکوس عمدی بیرون رابط حفظ می‌شود. تست VM و مرورگر واقعی پوشش می‌دهند.
4. در ارتفاع ۳۹۰ پیکسل، فرم از پنجره بیرون می‌زد؛ در صفحهٔ کوتاه launcher باز مخفی و خود پنجره اسکرول‌پذیر است. header، ارسال و تأیید حذف با scroll و کیبورد قابل دسترس‌اند.

## بازبینی امنیت و سادگی

- capability و nonce هسته، sanitizer idempotent و عدم نمایش کلید بررسی شدند؛ ورودی نامعتبر در پیام خطا چاپ نمی‌شود.
- option خصوصی nonautoload و از REST تنظیمات خارج است؛ نسخهٔ schema آینده overwrite نمی‌شود.
- HTTPS/Origin از home_url معتبر، Fetch Metadata دقیق، کوکی امضاشده، REMOTE_ADDR بدون اعتماد به forwarded header و مقصد ثابت TLS/no-redirect حفظ شدند.
- خروجی DOM متن است و لینک‌ها فقط allowlist عمومی سایت‌اند؛ package فاقد credential، fixture، مهارت، ابزار و فایل محیطی است.
- مدل/هزینه/حافظه در n8n ماندند؛ framework، service، custom table، updater یا کلاس واسط تازه ساخته نشد.

## تطبیق نیازها

FR-001..004 و US1: بستهٔ مستقل و تنظیم امن با PHP، WordPress و HTTP واقعی.
FR-005..007 و US2: رابط و قرارداد عمومی با Chrome و bridge؛ eligibility و Schema واقعی WooCommerce.
FR-008..009 و US3: schema/migration، حفظ داده در deactivation و حذف محدود option.
FR-010: هیچ تغییر workflow/model/SQL؛ سیاست موجود ۲ دلار UTC/۳۰روز حفظ شد.
FR-011..012: ZIP allowlist، راهنمای فارسی و این گزارش.
SC-001..004: هر سه داستان بررسی شدند؛ کلید در DOM/پاسخ عمومی/بسته بازنمایش ندارد و دادهٔ معتبر حفظ شد.

## حدود نتیجه و قدم بعد

نسخه‌های حداقل اعلام‌شده از روی API/syntax انتخاب شدند؛ ماتریس همهٔ نسخه‌ها اجرا نشده است. قالب تولید WoodMart، Safari/iOS واقعی، multisite، proxy/CDN واقعی و اتصال end-to-end به n8n در این مرحله بررسی نشده‌اند. خطاهای update/check وردپرس در محیط محلی ناشی از مسدودبودن HTTP خارجی‌اند؛ backend چت مصنوعی است. تصویرهای سه اندازه و رسید مرورگر بیرون Git در پوشهٔ خصوصی محیط آزمایش نگهداری شدند.

قدم بعد، پس از اجازهٔ نصب تولید: تنظیم خصوصی اتصال، بررسی proxy/HTTPS/cache/timeout، provider و قالب واقعی، سپس انتشار مجاز Main و آزمون محدود end-to-end. ZIP به‌تنهایی Main را فعال نمی‌کند.

## تطبیق نهایی Spec Kit

Converge پس از اجرای implement: ۱۲ نیاز عملیاتی، چهار معیار موفقیت، ۹ سناریوی پذیرش، چهار edge case، هشت تصمیم برنامه و پنج اصل بررسی شدند؛ missing/partial/contradicts/unrequested قابل اقدام: صفر. tasks.md هنگام converge بدون تغییر ماند؛ ۱۴ کار پیاده‌سازی تکمیل‌اند.
