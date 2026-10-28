<?php
// Human-readable administration values, with no database or network.
define('ABSPATH', __DIR__);
function __($text, $domain) { return $text; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_html__($value, $domain) { return esc_html($value); }
function number_format_i18n($value, $decimals=0) { return number_format($value, $decimals, '.', ','); }
function wp_date($format, $timestamp, $zone=null) { return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone ?? new DateTimeZone('UTC'))->format($format); }
function check($yes, $message) { if (!$yes) throw new RuntimeException($message); }
require __DIR__.'/../wordpress-plugin/gpante-support-chat/includes/admin-ui.php';
check(gpante_support_admin_money(1250000)==='$0.00125', 'Nano amounts retain meaningful precision');
check(gpante_support_admin_money(1)==='$0.000000001', 'Tiny nonzero costs never round to zero');
check(gpante_support_admin_money(2000000000)==='$2.00', 'Whole dollars include a currency unit');
check(gpante_support_admin_money(0)==='$0.00', 'Zero is known');
foreach ([null, -1, '2000', INF, '<script>'] as $bad) check(gpante_support_admin_money($bad)==='نامشخص', 'Invalid money stays unknown');
check(gpante_support_admin_duration(2700)==='2.7 ثانیه', 'Duration carries readable units');
check(gpante_support_admin_duration(1)==='0.001 ثانیه', 'Small durations retained');
check(gpante_support_admin_duration(null)==='نامشخص', 'Missing duration not invented');
check(gpante_support_admin_number(1234)==='1,234', 'Counts grouped');
check(gpante_support_admin_number(null)==='نامشخص', 'Missing count not zero');
check(gpante_support_admin_time('2026-10-28T12:34:56+00:00')==='2026/10/28 12:34:56 UTC', 'Dates labelled UTC');
check(gpante_support_admin_time('2026-10-28T14:34:56+02:00')==='2026/10/28 12:34:56 UTC', 'Offset normalized without changing filter boundaries');
foreach ([null, '<script>', '2026-02-30T12:00:00Z'] as $bad) check(gpante_support_admin_time($bad)==='نامشخص', 'Malformed time not normalized silently');
check(gpante_support_admin_label('unclassified','traffic')==='طبقه‌بندی‌نشده', 'Unknown traffic never labelled human');
check(gpante_support_admin_label('success')==='موفق', 'Known status translated');
check(!str_contains(gpante_support_admin_badge('<script>'), '<script>'), 'Unknown status cannot inject markup');
$reference=gpante_support_admin_reference(str_repeat('a',64));
check(str_contains($reference,'data-gpante-copy') && str_contains($reference,str_repeat('a',64)), 'Full reference remains available for copying');
check(str_contains($reference,'<details>') && !str_contains($reference,'screen-reader-text'), 'Manual copy is accessible if browser clipboard and fallback both fail');
check(!str_contains(gpante_support_admin_reference('<script>'), '<script>'), 'Invalid reference not reflected');
echo "Admin presentation: exact currency, units, unknown values, UTC dates, labels and references passed.\n";
