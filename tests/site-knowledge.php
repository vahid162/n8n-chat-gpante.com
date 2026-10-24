<?php
// Focused static extraction/ranking checks; no WordPress state or HTTP.
define('ABSPATH', __DIR__);
function strip_shortcodes($text) { return preg_replace('/\[[^\]]*\]/', '', $text); }
function wp_strip_all_tags($text, $breaks = false) { return strip_tags($text); }
function metadata_exists($type, $id, $key) { return array_key_exists($id, $GLOBALS['site_metadata'] ?? []); }
function get_post_meta($id, $key, $single) { return $key === '_elementor_edit_mode' ? ($GLOBALS['site_modes'][$id] ?? '') : ($GLOBALS['site_metadata'][$id] ?? ''); }
function wp_json_encode($value) { return json_encode($value); }
function get_transient($key) { return $GLOBALS['site_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['site_cache'][$key] = $value; }
require_once __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/catalog.php';
function site_check($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
site_check(function_exists('gpante_support_site_text'), 'site source text extraction exists');
$text = gpante_support_site_text('<p>آموزش عمومی</p><script>SECRET_SCRIPT</script><form>SECRET_FORM</form><nav>SECRET_NAV</nav>[private_download]');
site_check(str_contains($text, 'آموزش عمومی') && !str_contains($text, 'SECRET') && !str_contains($text, 'private_download'), 'unsafe nodes and shortcodes never become public text');
$elements = [['elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'عنوان آموزشی','__dynamic__'=>[]]], ['elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>متن آموزشی</p>']], ['elType'=>'widget','widgetType'=>'form','settings'=>['title'=>'SECRET_FORM']], ['elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'SECRET_DYNAMIC','__dynamic__'=>['title'=>'tag']]], ['elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'SECRET_PRIVATE','hide_desktop'=>'hidden-desktop']]];
$elements[] = ['elType'=>'widget','widgetType'=>'e-paragraph','settings'=>['paragraph'=>'متن المنتور تازه']];
$elements[] = ['elType'=>'e-flexbox','settings'=>['display_condition'=>'member-only'],'elements'=>[['elType'=>'e-paragraph','settings'=>['paragraph'=>['$$type'=>'escaped-html','value'=>'SECRET_CONDITIONAL_CHILD']]]]];
$typed = gpante_support_site_elementor_text([
    ['elType'=>'e-paragraph','settings'=>['paragraph'=>['$$type'=>'escaped-html','value'=>'&lt;p&gt;متن ایستای نسل چهار&lt;/p&gt;&lt;script&gt;SECRET_TYPED&lt;/script&gt;']]],
    ['elType'=>'e-heading','settings'=>['title'=>['$$type'=>'dynamic','value'=>'SECRET_DYNAMIC_TYPED']]],
]);
site_check(str_contains($typed, 'متن ایستای نسل چهار') && !str_contains($typed, 'SECRET'), 'native Elementor4 typed static text is extracted while dynamic and escaped scripts are excluded');
$text = gpante_support_site_elementor_text($elements);
site_check(str_contains($text, 'متن المنتور تازه') && str_contains($text, 'عنوان آموزشی') && str_contains($text, 'متن آموزشی') && !str_contains($text, 'SECRET'), 'Elementor exposes only visible static text widgets');
$groups = gpante_support_site_search_groups('کورل رایگان 2026');
site_check(gpante_support_site_matches('جعبه MDF 3', gpante_support_site_search_groups('باکس MDF 3')), 'shared site search preserves existing product family alias');
site_check(!gpante_support_site_matches('آموزش کورل 2025 رایگان', $groups), 'explicit software version is required');
site_check(!gpante_support_site_matches('آموزش کورل 2026', $groups), 'explicit free term is required');
site_check(gpante_support_site_matches('آموزش CorelDRAW رایگان 2026', $groups), 'controlled Corel aliases preserve all explicit terms');
site_check(gpante_support_site_score('اموزش کورل برای لیزر', 'page', 'learning', 'کورل') > gpante_support_site_score('نصب کورل', 'post', 'learning', 'کورل'), 'learning landing outranks installation article');
site_check(gpante_support_site_score('نصب کورل 2026', 'post', 'installation', 'کورل 2026') > gpante_support_site_score('آموزش کورل 2026', 'page', 'installation', 'کورل 2026'), 'installation intent prioritizes matching installer');
$late = str_repeat('متن مقدمه ', 1000) . ' بخش مربوط به CorelDRAW رایگان و نحوه یادگیری.';
$section = gpante_support_site_section($late, 'کورل رایگان', 6000);
site_check(mb_strlen($section['rendered']) <= 6000 && $section['truncated'] && str_contains($section['rendered'], 'CorelDRAW'), 'bounded detailed read finds relevant section beyond prefix');
$post = (object) ['ID'=>99, 'post_content'=>'STALE_BODY', 'post_excerpt'=>'STALE_EXCERPT'];
site_check(str_contains(gpante_support_site_post_text($post), 'STALE_BODY'), 'absent Elementor metadata retains ordinary stored content');
$GLOBALS['site_metadata'][99] = '';
site_check(str_contains(gpante_support_site_post_text($post), 'STALE_BODY'), 'inactive Elementor metadata does not override current ordinary editor body');
$GLOBALS['site_modes'][99] = 'builder';
site_check(gpante_support_site_post_text($post) === '', 'present empty meta value cannot reuse the absent-meta cache fingerprint');
$GLOBALS['site_metadata'][99] = [];
site_check(gpante_support_site_post_text($post) === '', 'present empty Elementor document invalidates absent-meta cache and suppresses stale body and excerpt');
$GLOBALS['site_metadata'][99] = '[]';
site_check(gpante_support_site_post_text($post) === '', 'cleared Elementor JSON document remains authoritative empty text');
foreach (['broken-json', '[broken-json', '{}', 'null', '["invalid"]', '[{"elType":"container","elements":"invalid"}]'] as $invalid) {
    $GLOBALS['site_metadata'][99] = $invalid;
    $threw = false;
    try { gpante_support_site_post_text($post); } catch (Throwable $error) { $threw = true; }
    site_check($threw, 'malformed authoritative Elementor document fails instead of resurrecting stale text: ' . $invalid);
}
echo "Focused site knowledge checks passed.\n";
