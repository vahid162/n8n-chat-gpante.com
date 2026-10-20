# بررسی محلی و بسته‌بندی

1. منابع PHP با lint/WPCS و تست‌های `tests/plugin-settings.php`، `tests/widget-bridge.php` و `tests/catalog-query.php` بررسی شوند.
2. `node --test tests/*.test.cjs` و ساخت widget/preview اجرا شوند؛ preview به شبکه متصل نیست.
3. در WordPress/WooCommerce مجزا: نصب تازه خاموش، ذخیرهٔ مدیر، منع مشترک و nonce نامعتبر، حفظ کلید، مهاجرت و uninstall بررسی شوند.
4. با agent-browser و Chrome، رابط واقعی در دسکتاپ، موبایل عمودی و افقی، ارسال/خطا/تلاش مجدد/reset و console بررسی و screenshot ذخیره شود. API مدل در این آزمون mock است.
5. `python3 scripts/build-plugin.py` ZIP allowlist را بسازد؛ محتوا، header/version و نبود کلید/ابزار بررسی شود.
6. روی gpante.com چیزی نصب نشود؛ Main فعال نشود. نتیجه و محدودیت‌ها در وضعیت پروژه ثبت شوند.
