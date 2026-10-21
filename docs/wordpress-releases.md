# انتشار و نسخه‌بندی افزونهٔ وردپرس

دریافت نسخه‌ها از [GitHub Releases](https://github.com/vahid162/n8n-chat-gpante.com/releases) است. بستهٔ قابل نصب، asset با نام `gpante-support-chat-wordpress-X.Y.Z.zip` است؛ Source code ZIP کل مخزن را شامل می‌شود و فایل نصب وردپرس نیست.

## قرارداد نسخه

- نسخهٔ افزونه `X.Y.Z` بدون صفر اضافه است. تگ همین جزء، `wordpress-vX.Y.Z` است؛ نسخهٔ workflow و مهارت مستقل می‌مانند.
- Version هدر PHP، ثابت `GPANTE_SUPPORT_VERSION` و Stable tag در readme باید دقیقاً برابر باشند؛ سازندهٔ بسته اختلاف را رد می‌کند.
- اصلاح سازگار: افزایش PATCH مانند `0.1.0 → 0.1.1`؛ قابلیت سازگار: افزایش MINOR مانند `0.1.0 → 0.2.0`.
- سری `0.x` مرحلهٔ توسعهٔ اولیه است. تغییر ناسازگار در این سری دست‌کم MINOR را افزایش می‌دهد و باید مسیر مهاجرت روشن داشته باشد؛ پس از `1.0.0`، تغییر ناسازگار MAJOR را افزایش می‌دهد.
- REST API، گزینهٔ تنظیمات و schema قراردادهای جدا هستند؛ افزایش نسخهٔ بسته مجوز پاک‌کردن تنظیمات نیست. برای schema ناسازگار، migration و آزمون حفظ داده لازم است.
- تگ و فایل نسخهٔ منتشرشده جابه‌جا یا جایگزین نمی‌شوند؛ هر اصلاح بسته، نسخهٔ تازه می‌گیرد. workflow در اجرای تکراری فقط بستهٔ یکسان را می‌پذیرد.

## انتشار هر نسخه

1. تغییر را در شاخهٔ جدا انجام دهید. سه محل نسخه، changelog داخلی readme و `CHANGELOG.md` را هماهنگ کنید.
2. `releases/wordpress/X.Y.Z.md` را با تغییرات، نیازمندی، راهنمای ارتقا، شواهد و محدودیت‌های همان نسخه اضافه کنید؛ متن فاقد کلید و اطلاعات عملیاتی خصوصی باشد.
3. تست‌های مرتبط، WPCS و آزمون واقعی WordPress/مرورگر را متناسب با تغییر اجرا کنید؛ نتیجهٔ انجام‌نشده را موفق معرفی نکنید.
4. PR را بررسی و ادغام کنید. workflow انتشار، آزمون‌های Node/PHP و بسته‌بندی را اجرا می‌کند. ادغام فایل یادداشت انتشار در `main` انتشار را آغاز می‌کند؛ تغییر کد به‌تنهایی انتشار خودکار ندارد.
5. job انتشار ZIP را دوباره از همان commit می‌سازد و hash آن را با job بررسی تطبیق می‌دهد؛ draft Release را به commit دقیق متصل، ZIP و `SHA256SUMS` را بارگذاری و سپس منتشر می‌کند.
6. نتیجه را در Releases بازخوانی، asset را دانلود و checksum و محتویات ZIP را تأیید کنید. نصب روی وب‌سایت مجوز جداگانه می‌خواهد.

برای اجرای دستی، Actions ← WordPress plugin release ← Run workflow را روی `main` اجرا کنید. انتشار هم‌زمان سریالی است. draft ناقص فقط با همان commit قابل ادامه است؛ نسخهٔ عمومی متفاوت هرگز با `--clobber` جایگزین نمی‌شود.

بررسی محلی:

```sh
python3 scripts/build-plugin.py --version
python3 -m unittest discover -s tests -p test_plugin_package.py
python3 scripts/build-plugin.py
cd dist
sha256sum gpante-support-chat-wordpress-*.zip > SHA256SUMS
sha256sum -c SHA256SUMS
```

ZIP نسخهٔ جاری فقط ۱۳ فایل runtime در پوشهٔ ثابت `gpante-support-chat/` دارد و تنظیم واقعی، ابزار، fixture یا مهارت در آن نیست. در یک محیط ساخت یکسان، خروجی قطعی است. checksum صحت فایل دانلودشده را بررسی می‌کند و امضای دیجیتال ناشر نیست.

Actions رسمی به SHA کامل pin شده‌اند؛ تست‌ها دسترسی خواندن دارند و فقط job انتشار روی `main` دسترسی نوشتن محتوا دارد. توکن کوتاه‌عمر داخلی GitHub Actions استفاده می‌شود و کلید دائمی تازه‌ای لازم نیست. انتشار Release، updater خودکار وردپرس یا انتشار در WordPress.org ایجاد نمی‌کند؛ ارتقا فعلاً با ZIP نسخهٔ تازه است.

مراجع: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html)، [GitHub Releases](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository)، [readme وردپرس و Stable tag](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).
