# پیاده‌سازی

نسخهٔ افزونه 0.3.0؛ قرارداد اتصال/schema=2 و backend policy=2 حفظ می‌شوند. فایل بومی includes/appearance.php گزینهٔ gpante_support_appearance با schema=1 و بدون autoload را مدیریت می‌کند. Settings API، options.php، nonce و manage_options مرز نوشتن هستند؛ downgrade دادهٔ آینده را حفظ و مقدار عمومی امن نشان می‌دهد.

رابط PHP فقط مقادیر مجاز را escape می‌کند؛ CSS به متغیرهای محدود و scoped وابسته است. JavaScript متن را با textContent می‌گذارد، فقط رنگ RGB مجاز را به متغیرهای ثابت می‌دهد. GET عمومی appearance فقط متن/رنگ مجاز را بدون cache می‌دهد؛ هیچ کلید، transcript یا تماس n8n ندارد. مسیر از rest_url گرفته می‌شود. دریافت پس‌زمینه مانع بازکردن چت نیست.

گزینهٔ جدید در init و activation ایجاد می‌شود؛ غیرفعال‌کردن آن را حفظ و uninstall فقط دو option متعلق به افزونه را پاک می‌کند. بستهٔ runtime اکنون ۱۲ فایل دارد. template و assetهای embed یک منبع دارند و بازتولید می‌شوند.

بررسی: validation/contrast/permissions/reset/future schema در PHP؛ cache refresh/fallback/plain text در کنترلر؛ آزمون واقعی options.php با مدیر/مشترک و nonce؛ مرورگر واقعی برای رنگ و متن سفارشی، کش و سه اندازه؛ سپس CI، Release تغییرناپذیر، checksum و استقرار مجاز با حفظ تنظیمات.
