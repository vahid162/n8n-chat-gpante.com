# طرح اجرایی

افزونه 0.4.0؛ schema مستقل ظاهر ۲ با مهاجرت idempotent از ۱. چهار ردیف label/url در همان option غیرautoload؛ فرم بومی capability/nonce. متن ساده و محدود، مقصد HTTPS/مسیر داخلی؛ هیچ تماس سروری برای لینک وجود ندارد. نسخهٔ public appearance همان ۱ با فیلد افزایشی links است.

template مشترک: label مخفی دسترس‌پذیر، icon button در کادر ترکیبی، نقش log مستقل در ناحیهٔ scroll و nav لینک‌ها زیر پیام‌ها. آستانهٔ رنگ و escape موجود حفظ می‌شوند؛ CSS صرفاً داخل widget است و ابعاد panel تغییر نمی‌کنند.

history marker مختص widget، حفظ state برنامه، Back و popstate برای بستن، مصرف marker در بستن دستی، صف بازکردن مجددِ سریع و بازیابی marker پس از reload/Forward. کلیک بیرون preventDefault ندارد و focus بیرونی را نمی‌دزدد. درخواست در حال دریافت قطع نمی‌شود.

بررسی واحد URL/migration/policy/close و history؛ WordPress native save/nonce/نقش و مهاجرت؛ Chrome ابعاد دقیق، چهار/سه/صفر لینک، focus، Back/Forward/reload، خارج پنجره و لینک ناوبری. سپس CI، Release تغییرناپذیر و ارتقای مجاز با پشتیبان تنظیمات ظاهر و تطبیق مقادیر قبلی. rollback قدیمی به پشتیبان schema=1 ظاهر نیاز دارد.
