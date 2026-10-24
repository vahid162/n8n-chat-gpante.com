# آزمون قابل بازسازی

بررسی‌های بدون سرویس:

```sh
node --test tests/*.test.cjs
python3 -m unittest discover -s tests -p 'test_*.py'
```

تست `generated-artifacts.test.cjs` خروجی Main، Admin، Retention و ویجت را در حافظه با فایل ثبت‌شده مقایسه می‌کند. بعد از تغییر منبع، خروجی مرتبط را با `node scripts/build-workflow.cjs` یا `node scripts/build-widget.cjs` بازسازی کنید. این کنترل، فایل قدیمیِ قابل استقرار را حتی با تست‌های موفق منبع رد می‌کند.

## وردپرس و ووکامرس بومی

پیش‌نیاز: Python 3، PHP با افزونه‌های `mysqli/curl/mbstring/dom/zip`، کلاینت MariaDB یا MySQL و یک دیتابیس **خالی و محلی**. نسخه‌های آزموده‌شده WordPress **7.1.3**، WooCommerce **11.2.1** و WP-CLI **2.12.0** هستند؛ این بررسی جای ماتریس تمام نسخه‌های حداقلِ افزونه را نمی‌گیرد. WP-CLI با SHA256 ثابت بررسی می‌شود. دانلود اولیه فقط وابستگی‌های عمومیِ همین نسخه‌هاست.

نمونهٔ سرویس جدا و دورریختنی؛ رمزها صرفاً مصنوعی‌اند:

```sh
docker run --detach --name gpante-native-tests --publish 127.0.0.1:13367:3306 \
  --env MARIADB_ROOT_PASSWORD=gpante-isolated-root-only \
  --env MARIADB_DATABASE=gpante_test_native \
  --env MARIADB_USER=gpante_test --env MARIADB_PASSWORD=gpante-isolated-ci-only \
  mariadb@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873
```

پس از آماده‌شدن دیتابیس:

```sh
export GPANTE_TEST_DB_HOST=127.0.0.1:13367
export GPANTE_TEST_DB_NAME=gpante_test_native
export GPANTE_TEST_DB_USER=gpante_test
export GPANTE_TEST_DB_PASSWORD=gpante-isolated-ci-only
export GPANTE_WP_TEST_DIR=/tmp/gpante-native-wordpress
export GPANTE_WP_TEST_URL=https://127.0.0.1:19443
python3 scripts/test-wordpress.py
```

پوشه باید هنوز وجود نداشته باشد و خارج مخزن باشد. اسکریپت هیچ نصب موجود یا دیتابیس دارای جدول را بازنویسی نمی‌کند؛ دیتابیس نیز ایجاد یا حذف نمی‌کند. نام دیتابیس باید با `gpante_test_` آغاز شود. اتصال دیتابیس و URL سایت فقط loopback مجازند. خرابی نصب ناقص را با مسیر و دیتابیس خالیِ تازه بررسی کنید؛ اسکریپت پاک‌سازی خودکار و مخرب ندارد.

این فرمان تست‌های واقعی تنظیمات/ووکامرس، دسته و رتبه‌بندی SQL، FAQ، متن سایت، چرخهٔ نمایه و قفل‌ها را اجرا می‌کند. بک‌اند مصنوعی نیز بازپخش همان `sendId`، شش نوبت کامل در هر صفحه، نشانگر صفحه، بازخورد متعلق به نشست، انقضای ثابت و پاک‌کردن را بررسی می‌کند. تماس HTTP خارجیِ پیش‌بینی‌نشده در این نصب مسدود است؛ هیچ مدل یا workflow تولید فراخوانی نمی‌شود.

برای اجرای دوباره روی همین محیطِ دارای نشان مالکیت:

```sh
python3 scripts/test-wordpress.py --run-only
```

CI همین راه‌انداز را روی سرویس مستقل MariaDB اجرا می‌کند. تست‌های بدل PHP همچنان بررسی سریع هستند؛ جای این بررسی SQL/WordPress را نمی‌گیرند. کار PostgreSQL مستقل است: CI دادهٔ قدیمی را پیش از migration003 می‌سازد، پس از مهاجرت حفظ متن/زمان/دفتر مالی را بررسی می‌کند و سپس مجموعه‌های آرشیو، هزینه، گفتگو، گزارش و تلاش مجدد پس از حذف فیزیکی را اجرا می‌کند.

## HTTP و مرورگر واقعی محلی

`openssl` برای گواهی خودامضا و `agent-browser` برای تست‌های مرورگر لازم‌اند. سرور اختیاری در پیش‌زمینه اجرا می‌شود؛ فقط محیطِ ساخته‌شده توسط همین اسکریپت را می‌پذیرد، کلیدهای مصنوعی فعال می‌کند و با Ctrl-C هر دو سرور را می‌بندد:

```sh
python3 scripts/test-wordpress.py --run-only --serve
```

در ترمینال دیگر همان متغیرهای بالا را تنظیم کنید. برای مرورگر، `agent-browser` باید در PATH باشد یا مسیر آن صریح تعیین شود:

```sh
export AGENT_BROWSER_BIN=/absolute/path/to/agent-browser
python3 tests/wordpress-settings-http.py
python3 tests/wordpress-archive-http.py
python3 tests/wordpress-appearance-http.py
python3 tests/wordpress-browser.py
```

سایر فایل‌های `wordpress-*-browser.py` نیز همان `GPANTE_WP_TEST_DIR`، `GPANTE_WP_TEST_URL` و `AGENT_BROWSER_BIN` را می‌خوانند. سرور HTTPS پورت انتخاب‌شده و PHP پورت بعدی را روی `127.0.0.1` می‌گیرد؛ هر دو باید آزاد باشند. غیرفعال‌کردن اعتبارسنجی گواهی فقط مربوط به همین URL محلی است. `admin-password` و شواهد مرورگر در پوشهٔ خصوصی آزمایش می‌مانند، نه Git.

پس از توقف آزمون، فقط کانتینر نمونهٔ خود را حذف کنید:

```sh
docker rm --force gpante-native-tests
```

این محیط، n8n واقعی، تماس مدل، ظرفیت بار، دستگاه iOS یا داده/پیکربندی تولید را بررسی نمی‌کند. ارزیاب پاسخ فقط قراردادهای صریح، منابع، شروط و استناد را می‌سنجد؛ قبولی آن اثبات دقت معنایی پاسخ نیست.
