# قرارداد پایدار v1

مرورگر: POST `/wp-json/gpante-support/v1/chat` با JSON و action از sendMessage، loadPreviousSession، resetSession؛ chatInput فقط برای ارسال و حداکثر ۴۰۰۰ نویسه. policyVersion=2 اعلام پذیرش قرارداد نگهداری است.

پاسخ موفق، policy عمومی معتبر نسخهٔ ۲ را دارد. ارسال `{output,expiresAt,policy}`، تاریخچه `{messages:[{role,content,expiresAt}],policy}` و پاک‌کردن `{ok:true,policy}` است. role فقط user/assistant و expiresAt مهلت معتبر نمایش است؛ خطا محدود و بدون state داخلی است.

از افزونهٔ 0.7.0، `products:[{url,title,thumbnail}]` فیلد افزایشی و اختیاری در پاسخ ارسال و پیام دستیار تاریخچه است؛ user مقدار خالی می‌گیرد. bridge این metadata را از محصول عمومی و attachment بومی وردپرس دوباره می‌سازد؛ metadata backend/model را عبور نمی‌دهد. بدون عکس معتبر، فهرست خالی و متن قابل استفاده است. cache فقط در همان درخواست و حداکثر ۳۰ lookup مستقل، بدون محدودکردن پوشش کاتالوگ است. مرورگر قدیمی می‌تواند فیلد افزایشی را نادیده بگیرد؛ نبود آن نیز برای مرورگر جدید معتبر است. متن، policy و ذخیره‌سازی n8n تغییر نمی‌کنند؛ endpoint تازه‌ای لازم نیست.

کلید و sessionId/clientId از مرورگر پذیرفته نمی‌شوند. HTTPS، Origin دقیق home معتبر و Fetch Metadata هم‌مبدأ لازم‌اند. IP فقط REMOTE_ADDR معتبر است. واسط به backend ثابت فعلی با X-Gpante-Chat-Key و شناسه‌های HMAC می‌فرستد؛ redirect ممنوع و timeout موجود حفظ می‌شود.

کاتالوگ GET `/products`، `/knowledge` و `/categories` قرارداد موجود src/catalog.cjs را حفظ می‌کند؛ خاموش‌بودن چت فقط نمایش/ارسال چت را می‌بندد و کاتالوگ عمومی مستقل می‌ماند. تنظیمات مدیر فقط از Settings API و manage_options است؛ route عمومی برای تنظیم ساخته نمی‌شود.
