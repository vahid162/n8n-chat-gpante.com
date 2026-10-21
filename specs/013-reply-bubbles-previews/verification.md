# شواهد 0.7.0

۲۱ اکتبر ۲۰۲۶؛ نامزد و سپس بستهٔ رسمی نصب‌شده روی سایت بررسی شدند.

- ۸۵ آزمون Node، پنج آزمون Python بسته/انتشار، PHP settings/appearance/bridge، بررسی تصویر، ۲۴ بررسی query و WPCS بدون خطا/هشدار موفق‌اند. ابزار اتصال نیز با baseline سربرگ فعلی سازگار شد و نامزد Chromium/Firefox در چهار اندازه، offline/reconnect/draft/retry را تأیید کرد.
- دو آزمون اصلی حباب مستقل/فهرست/عکس معتبر و رد عکس نامعتبر پیش از اصلاح قرمز بودند و اکنون سبز هستند. تست‌های legacy table، چندستون، متن مخرب، لینک امن، عکس خراب و send/history نیز وجود دارند؛ user متن ساده می‌ماند.
- PHP تصویر، eligibility visible/catalog/search و رد hidden/private/password/variation/post غیرمحصول، عکس گمشده/خارجی/SVG/پیمایشی، فیلدهای مجاز، cache و سقف ۳۰ lookup را بررسی کرد. bridge، metadata خام backend را کنار می‌گذارد و user history عکس نمی‌گیرد. این تست به CI اضافه شد.
- روی PHP واقعی 8.3.32 در WP-CLI، فقط دو تابع نامزد در حافظهٔ همان process ارزیابی شدند؛ فایل/تنظیم سایت تغییر نکرد. پنج عکس محصول عمومی با خروجی بومی thumbnail همان attachment تطبیق داشتند.
- Chromium و Firefox روی قالب/ظاهر واقعی، دو محصول با thumbnail عمومی واقعی، چهار حباب، شش li و بدون table را در چهار اندازه بررسی کردند: ۱۴۴۰×۱۰۰۰→۳۹۰×۶۵۰، ۳۹۰×۸۴۴→۳۷۴×۶۵۰، ۸۴۴×۳۹۰→۳۹۰×۳۶۶ و ۳۲۰×۵۶۸→۳۰۴×۴۸۰. فاصله حداقل ۱۰، عکس ۴۸×۴۸ سمت راست عنوان، متن ویژگی ۱۳، لینک regular/تمام‌عرض/حداقل ۴۴ و overflow صفر بودند.
- عکس‌ها واقعاً load/decode شدند؛ خطای عکس با abort فقط در مرورگر شبیه‌سازی شد و متن باقی ماند. Tab و reload همراه metadata موفق بودند؛ HTML/لینک ممنوع اجرا/فعال نشد. همهٔ chat requestها intercept شدند؛ پیام واقعی، تماس مدل و خطای runtime صفر بود. تصویر Firefox دستی بررسی شد.

مرورگر قابل تکرار `tests/chat-presentation-browser.py` است؛ `--previews` فایل عمومی url/title/thumbnail می‌گیرد و `--css` نامزد را فقط در همان مرورگر اعمال می‌کند. شواهد خام و شناسه‌های تولید خصوصی‌اند. Safari/دستگاه فیزیکی و CDN خارجی آزموده/اضافه نشده‌اند؛ تشخیص مرز حباب بر ساختار متن تکیه دارد.

## انتشار و نصب

- PR 32 پس از موفقیت verify و archive-database ادغام شد؛ Release رسمی `wordpress-v0.7.0` از commit همان ادغام ساخته شد. ZIP رسمی دانلودشده با بستهٔ محلی و ۱۲ فایل source برابر است؛ SHA256: `79fbc45373ce19a3633f68e79b131e2d2ffb94ff64142399fae9ac722ea45ddc`.
- پیش از نصب، نسخهٔ 0.6.2 و همهٔ hashها/تنظیمات با baseline برابر بودند. پشتیبان محدود افزونه و دو option خصوصی، بدون transcript، خارج webroot گرفته شد. WP-CLI با PHP 8.3 بسته را نصب و هر ۱۲ فایل دوباره تطبیق داده شد. کل runtime/ظاهر، کلیدها، enabled، policy/revision، active plugins و قالب حفظ شدند؛ cache فقط همین سایت پاک شد و سرویس مشترک restart نشد.
- پنج thumbnail با تابع نصب‌شده و attachment بومی WordPress برابرند. مسیر واقعی bridge داخل WP-CLI برای send/history، هرکدام دو preview درست برگرداند؛ user metadata خالی و metadata جعلی backend کنار گذاشته شد. دو درخواست backend در همان process با HTTP مصنوعی intercept شدند؛ صفر درخواست به n8n/مدل و صفر پیام ذخیره‌شدهٔ مصنوعی.
- Chromium/Firefox روی فایل نصب‌شده، بدون تزریق CSS/formatter، همان دو عکس واقعی، چهار حباب، فهرست خطی، ابعاد ثابت در چهار اندازه، Tab/reload و عکس خراب را تأیید کردند. همهٔ chat requestها intercept و خطای runtime/تماس مدل صفر بود.
- Chrome تازه و بدون mock فقط history/policy واقعی را خواند؛ state=ready، label=Online، اعلان خالی و ورودی فعال بود. شمار دفتر تماس مدل قبل/بعد برابر ماند. هر ۲۵ fingerprint workflow برابر، Main 1.3.0 فعال و active/draft برابر، runtime بودجه/سیاست ثابت و آخرین Retention طبیعی موفق بود. SQL و پایگاه داخلی n8n تغییر نکردند.
- مهارت canonical نسخهٔ 0.9.0 و ZIP مستقل آن از چهار فایل canonical بازسازی و با منبع تطبیق داده شدند؛ لینک محلی به همان منبع است. شواهد خام/تصاویر و شناسه‌های عملیاتی خصوصی‌اند.
