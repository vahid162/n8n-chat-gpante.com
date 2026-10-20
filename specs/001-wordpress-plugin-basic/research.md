# تصمیم‌های تحقیق

- تصمیم: یک فایل header با WP>=6.6، PHP>=8.1، Requires Plugins: woocommerce و Update URI یکتا.
  دلیل: بستهٔ مستقل و جلوگیری از جایگزینی هم‌نام. نسخهٔ Woo و Tested up to فقط پس از آزمون ثبت می‌شوند.
  منبع: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
- تصمیم: Settings API، manage_options، nonce هسته، show_in_rest=false، secret بدون بازنمایش.
  دلیل: بازاستفاده از هسته؛ فرم/route مدیریتی اختصاصی لازم نیست. ورودی خالی یا نامعتبر کلید را پاک نمی‌کند.
  منابع: https://developer.wordpress.org/plugins/settings/settings-api/ و https://developer.wordpress.org/reference/functions/register_setting/
- تصمیم: همان gpante_support_runtime با schema_version؛ activation و بارگذاری عادی ارتقا را بررسی کنند.
  دلیل: activation در update اجرا نمی‌شود. nonautoload با wp_set_option_autoload نیز اصلاح می‌شود.
  منابع: https://developer.wordpress.org/plugins/creating-tables-with-plugins/ و https://developer.wordpress.org/reference/functions/wp_set_option_autoload/
- تصمیم: deactivation داده را حفظ و uninstall فقط option را حذف کند؛ rewrite/cron تازه وجود ندارد.
  منبع: https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
- تصمیم: asset بومی و template داخلی؛ کلید در JS/HTML/REST نباشد و منبع catalog/bridge کپی موازی نشود.
  منابع: https://developer.wordpress.org/plugins/javascript/enqueuing/ و https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
- تصمیم: origin دقیق HTTPS سایت از تنظیم معتبر home_url خوانده شود تا localhost/staging امن قابل آزمون باشد؛ HTTP_HOST و forwarded دلخواه معتبر نشوند. backend n8n همچنان ثابت است.
- ردشده: framework تنظیمات، جدول/queue تازه، auto-updater سفارشی، تعویض مدل در افزونه، و نصب روی تولید.
- حدود: query عمومی catalog هزینهٔ SQL دارد و سقف API آن را محدود نمی‌کند؛ سنجش تولید بعداً لازم است. آزمون mock جای WooCommerce واقعی نیست.
