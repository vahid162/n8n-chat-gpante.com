# Implementation Plan: افزونهٔ پایهٔ چت جی‌پانته

**Branch**: `feat/support-chat-v1` | **Date**: 2026-10-20 | **Spec**: [spec.md](spec.md)

**Input**: `specs/001-wordpress-plugin-basic/spec.md`

## Summary
کد موجود bridge/catalog/assets به یک افزونهٔ مستقل 0.1.0 منتقل و بازاستفاده می‌شود.
Settings API و مهاجرت کوچکِ نسخه‌دار، تنظیمات خصوصی را نگه می‌دارند؛ کد مدل و SQL تغییر نمی‌کند.

## Technical Context
**Language/Version**: PHP >=8.1، JavaScript بدون framework؛ WordPress >=6.6.
**Primary Dependencies**: WordPress، WooCommerce با Store API مورد نیازِ موجود؛ حداقل نسخهٔ Woo بدون آزمون اختراع نمی‌شود.
**Storage**: یک گزینهٔ nonautoload با همان نام gpante_support_runtime؛ چت در project_db باقی می‌ماند.
**Testing**: Node test/VM، PHP، WordPress/WooCommerce مجزای محلی، WPCS و Chrome با agent-browser.
**Target Platform**: افزونهٔ نصب‌شدنی وردپرس، رابط فارسی/RTL عمومی.
**Project Type**: WordPress plugin + existing n8n product repository.
**Performance Goals**: asset فقط هنگام نمایش ویجت؛ آزمون رابط بدون مدل و SLA جدید.
**Constraints**: عدم تغییر تولید؛ backend ثابت؛ کلید در سرور؛ schema/API پایدار؛ نصب تازه خاموش.
**Scale/Scope**: کل کاتالوگ مجاز؛ اندازهٔ پاسخ محدود؛ بدون قابلیت سفارش یا index/service تازه.

## Constitution Check
پیش از طراحی و پس از آن: پنج اصل برقرارند. منابع موجود بازاستفاده، state جدا و تست‌ها محلی‌اند.
چارچوب یا جدول تازه لازم نیست. دامنهٔ ساخت، شامل نصب gpante.com یا انتشار Main نیست.

## Project Structure
### Documentation (this feature)
`specs/001-wordpress-plugin-basic/{spec,plan,research,data-model,quickstart,tasks}.md` و contracts/.
### Source Code (repository root)
```text
wordpress-plugin/gpante-support-chat/
  gpante-support-chat.php
  includes/settings.php
  includes/bridge.php
  includes/catalog.php
  templates/widget.php
  assets/chat.js
  assets/chat.css
  readme.txt
  uninstall.php
scripts/render-widget.php
scripts/build-widget.cjs
scripts/build-plugin.py
tests/plugin-settings.php
phpcs.xml.dist
```
**Structure Decision**: فایل‌های کوچک با توابع prefixed و API بومی؛ بدون کلاس واسط یا autoloader.
مسیرهای مرجع قدیمی PHP با wrapper ثابت حفظ می‌شوند؛ asset و template یک منبع canonical دارند.
نسخهٔ افزونه، نسخهٔ schema و API v1 مستقل‌اند. تنظیم نسخهٔ آینده overwrite/downgrade نمی‌شود.

## Complexity Tracking
هیچ استثنا از اصول لازم نیست. migration فقط یک آرایهٔ option و schema_version=1 است.
