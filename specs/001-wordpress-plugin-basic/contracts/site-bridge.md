# قرارداد پایدار v1

مرورگر: POST `/wp-json/gpante-support/v1/chat`، JSON با action از sendMessage، loadPreviousSession، resetSession؛ chatInput فقط برای ارسال و حداکثر 4000 نویسه.
پاسخ عمومی به‌ترتیب `{output}`، `{messages:[{role,content}]}` یا `{ok:true}`؛ خطا محدود و بدون state داخلی.
کلید و sessionId/clientId از مرورگر پذیرفته نمی‌شوند. HTTPS، Origin دقیق home معتبر و Fetch Metadata هم‌مبدأ لازم‌اند. IP فقط REMOTE_ADDR معتبر است.
واسط به backend ثابت فعلی با X-Gpante-Chat-Key و شناسه‌های HMAC می‌فرستد؛ redirect ممنوع و timeout موجود حفظ می‌شود.
کاتالوگ GET `/products` و `/knowledge` قرارداد موجود src/catalog.cjs را حفظ می‌کند؛ خاموش‌بودن چت فقط نمایش/ارسال چت را می‌بندد و کاتالوگ عمومی مستقل می‌ماند.
تنظیمات مدیر فقط از Settings API و manage_options؛ هیچ route عمومی برای تنظیم ساخته نمی‌شود.
