# Tasks: افزونهٔ پایهٔ چت جی‌پانته

**Input**: spec.md، plan.md، research.md، data-model.md و contracts/site-bridge.md

## Phase 1: Setup
- [x] T001 راه‌اندازی رسمی Spec Kit 1.1.3 و ثبت نیاز/اصول در .specify/ و specs/001-wordpress-plugin-basic/.
- [x] T002 آماده‌کردن agent-browser و Chrome محلی و ثبت ابزارها بیرون مخزن عمومی.

## Phase 2: Foundational
- [x] T003 انتقال canonical منابع موجود به wordpress-plugin/gpante-support-chat/ و ایجاد header/loader نسخهٔ 0.1.0 بدون وابستگی runtime تازه.

## Phase 3: User Story 1 - تنظیم امن مدیر
**Goal**: نصب خاموش و ذخیرهٔ معتبر با حفظ secret؛ آزمون مستقل PHP و WordPress محلی.
- [x] T004 [US1] آزمون تنظیم خاموش، «ASCII بدون فاصله، 32..256 نویسه»، کلید خالی و نقش غیرمدیر در tests/plugin-settings.php.
- [x] T005 [US1] Settings API با manage_options، nonce هسته، show_in_rest=false و nonautoload در wordpress-plugin/gpante-support-chat/includes/settings.php.
- [x] T006 [US1] بررسی نصب تازه، ذخیرهٔ مدیر، منع مشترک و nonce نامعتبر در tests/wordpress-plugin-integration.php روی WordPress محلی.

## Phase 4: User Story 2 - رابط عمومی
**Goal**: asset و رابط فارسی خودکار، با همان API و بدون افشای کلید؛ آزمون مرورگر و قرارداد.
- [x] T007 [US2] حفظ bridge/catalog، origin دقیق HTTPS سایت، template ترجمه‌پذیر و enqueue بومی در wordpress-plugin/gpante-support-chat/.
- [x] T008 [US2] اصلاح سازندهٔ embed/preview و import آزمون‌ها برای منبع واحد در scripts/build-widget.cjs، scripts/render-widget.php و tests/widget-*.cjs.
- [x] T009 [US2] اجرای مرورگر واقعی در اندازه‌های دسکتاپ، موبایل عمودی/افقی و ثبت ارسال، خطا/retry/reset و console در گزارش specs/001-wordpress-plugin-basic/verification.md.

## Phase 5: User Story 3 - ارتقا
**Goal**: حفظ تنظیم و مسیر uninstall محدود؛ آزمون تغییر نسخهٔ تنظیم و lifecycle.
- [x] T010 [US3] migration idempotent برای schema_version=1، حفظ نسخهٔ آینده و autoload=false در includes/settings.php و تست‌های tests/plugin-settings.php.
- [x] T011 [US3] حفظ تنظیم در deactivation/reactivation و حذف فقط option در wordpress-plugin/gpante-support-chat/uninstall.php با آزمون محلی.

## Final Phase: Polish & Cross-Cutting Concerns
- [x] T012 اجرای lint/WPCS، تست‌های PHP و Node و بازبینی امنیت روی wordpress-plugin/gpante-support-chat/ و phpcs.xml.dist.
- [x] T013 تولید ZIP allowlist توسط scripts/build-plugin.py و بررسی header/version/نبود دادهٔ خصوصی.
- [x] T014 تکمیل readme.txt، راهنمای نصب/ارتقا و مهارت؛ تطبیق خروجی با نیازها و ثبت موارد باقی‌مانده در specs/001-wordpress-plugin-basic/verification.md.

## Dependencies & Execution Order
T001/T002 -> T003 -> US1 -> US2 -> US3 -> T012/T013/T014.
فایل‌های bridge، settings و loader قرارداد مشترک دارند؛ این تغییرات ترتیبی ادغام می‌شوند.
تحقیق استانداردها و آماده‌سازی ابزارهای خارج مخزن مستقل‌اند؛ آزمون canonical بعد از ادغام است.

## Parallel Examples
پس از تثبیت قرارداد و ادغام، بررسی خواندنی استانداردها می‌تواند هم‌زمان با آماده‌سازی محیط مجزا انجام شود. دو نویسنده هم‌زمان روی runtime یا فایل تولیدی کار نمی‌کنند.

## Implementation Strategy
ابتدا تنظیم امن و نصب خاموش، سپس رابط/قرارداد، سپس lifecycle و بسته‌بندی. تحویل شامل هر سه داستان است؛ کوچک‌بودن نسخه مجوز حذف ایمنی یا آزمون ارتقا نیست. نصب تولید انجام نمی‌شود.
