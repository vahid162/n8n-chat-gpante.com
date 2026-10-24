<?php
// Run only with WP-CLI on the isolated local WordPress fixture installation.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== (defined('GPANTE_SUPPORT_TEST_URL') ? GPANTE_SUPPORT_TEST_URL : 'https://127.0.0.1:9443')) throw new RuntimeException('Isolated local test site required');
require_once __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/catalog.php';
function faq_check($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function faq_item($id, $question, $answer) {
    return '<details id="' . $id . '" data-id="element-' . $id . '" data-faq-item><summary data-faq-question>' . $question . '</summary><div data-faq-answer>' . $answer . '</div></details>';
}
function faq_document($items) {
    return '<!doctype html><html><head><meta charset="utf-8"></head><body><details data-faq-item><summary>خارج از صفحه</summary><div data-faq-answer>نباید خوانده شود</div></details><div data-elementor-id="51415" data-elementor-type="wp-page">' . str_repeat('<div>مقدمهٔ عمومی صفحه</div>', 150) . $items . '</div></body></html>';
}
function faq_read($params) {
    $r = new WP_REST_Request('GET', '/gpante-support/v1/knowledge'); $r->set_query_params(['source' => 'faq'] + $params);
    return gpante_support_catalog_knowledge($r);
}
if (get_post(51415)) throw new RuntimeException('Reserved FAQ fixture ID already exists; no overwrite');
delete_transient('gpante_support_faq_cache');
$post = wp_insert_post(['import_id' => 51415, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture FAQ', 'post_name' => 'faq-ai'], true);
if (is_wp_error($post) || $post !== 51415) throw new RuntimeException('FAQ fixture creation failed');
$items = faq_item('faq-cdr', 'فرمت CDR چیست؟', '<p>فایل CorelDRAW یا کورل است.</p>')
    . faq_item('faq-slot', 'چرا زبانه داخل شیار جا نمی‌رود؟', '<p>ضخامت واقعی ورق و اندازه شیار را بررسی کنید.</p><p>نمونه اتصال را آزمایش کنید.</p>')
    . faq_item('faq-design', 'طراحی اختصاصی را چطور سفارش بدهم؟', '<p>با پشتیبانی هماهنگ کنید.</p><a href="https://gpante.com/contact-us/">تماس</a><a href="https://t.me/gpante_designer">تلگرام طراحی</a><a href="https://evil.example/">خارجی نامعتبر</a><a href="https://gpante.com/wp-content/private.zip">فایل خصوصی</a><script>PRIVATE_SCRIPT</script><iframe>PRIVATE_EMBED</iframe>')
    . faq_item('faq-a', 'راهنمای نمونه الف', 'پاسخ عمومی الف')
    . faq_item('faq-b', 'راهنمای نمونه ب', 'پاسخ عمومی ب')
    . '<details data-id="new-native-17" data-element_type="e-accordion-item"><summary>پرسش تازه المنتور</summary><div data-element_type="e-accordion-item-content">پاسخ تازه بدون ثبت دستی شناسه</div></details>'
    . faq_item('faq-long', 'پاسخ طولانی نمونه', str_repeat('محتوا ', 600) . 'واژهپایانی');
$html = faq_document($items); $status = 200; $content_type = 'text/html; charset=UTF-8'; $urls = [];
$http = static function ($pre, $args, $url) use (&$html, &$status, &$content_type, &$urls) {
    faq_check((bool)preg_match('~^https://gpante\.com/faq-ai/\?gpante_faq_source=[a-f0-9]{32}$~D', $url), 'FAQ fetch destination is fixed, versioned and contains no caller URL');
    faq_check($args['redirection'] === 0 && $args['sslverify'] === true && $args['cookies'] === [] && $args['timeout'] === 8 && $args['limit_response_size'] === 1048576, 'FAQ fetch is anonymous, TLS-verified and bounded without redirects');
    faq_check(!isset($args['headers']['Authorization']) && !isset($args['headers']['Cookie']), 'FAQ fetch sends no credential or visitor cookie');
    $urls[] = $url;
    return ['response' => ['code' => $status], 'headers' => ['content-type' => $content_type], 'body' => $html];
};
add_filter('pre_http_request', $http, 10, 3);
try {
    $parsed = gpante_support_faq_parse($html);
    faq_check(is_array($parsed) && count($parsed) === 7, 'all questions are extracted only from the approved page root');
    faq_check(count(gpante_support_faq_parse($html . "\n<!-- site cache footer -->")) === 7, 'a harmless cache comment after the complete document remains readable');
    $by = array_column($parsed, null, 'id');
    faq_check(str_contains($by['faq-slot']['content']['rendered'], 'کنید. نمونه'), 'block boundaries remain readable');
    faq_check(!str_contains($by['faq-design']['content']['rendered'], 'PRIVATE_') && count($by['faq-design']['links']) === 2, 'script/embed content and unapproved reference destinations are excluded');
    faq_check($by['faq-design']['links'][1]['url'] === 'https://t.me/gpante_designer', 'verified design support link is retained as a reference');
    faq_check($by['elementor-new-native-17']['link'] === 'https://gpante.com/faq-ai/', 'new native Elementor questions remain readable without a friendly-anchor mapping');
    faq_check($by['faq-long']['content']['truncated'] === true, 'long individual answers have an explicit truncation flag');
    $partial = substr($html, 0, strrpos($html, '</details>') + strlen('</details>'));
    faq_check(is_wp_error(gpante_support_faq_parse($partial)), 'truncated HTML cannot become a successful but incomplete FAQ');
    $read = faq_read(['search' => 'شیار']);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-slot'], 'a question beyond the first page-prefix/snippet is retrieved with its own complete answer');
    foreach (['کورل', 'Corel', 'CorelDRAW'] as $term) {
        $read = faq_read(['search' => $term]);
        faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-cdr'], 'Corel alias retrieves the same official FAQ: ' . $term);
    }
    faq_check(count($urls) === 1, 'unchanged published FAQ reuses its versioned public cache across searches');
    $read = faq_read(['search' => 'CorelDRAW 2024']);
    faq_check(!is_wp_error($read) && $read->get_data() === [] && $read->get_headers()['X-WP-Total'] === 0, 'explicit software version is not silently dropped');
    $read = faq_read(['search' => 'واژهپایانی']);
    faq_check(!is_wp_error($read) && $read->get_data()[0]['id'] === 'faq-long' && $read->get_data()[0]['content']['truncated'], 'search uses the full answer even when its returned snippet is bounded');
    $all = [];
    foreach ([1,2,3] as $page) {
        $read = faq_read(['page' => $page]);
        faq_check(!is_wp_error($read) && $read->get_headers()['X-WP-Total'] === 7 && $read->get_headers()['X-WP-TotalPages'] === 3, 'FAQ pagination reports all eligible questions');
        $all = array_merge($all, array_column($read->get_data(), 'id'));
    }
    faq_check(count($all) === 7 && count(array_unique($all)) === 7, 'FAQ pages have complete coverage without duplicates');
    $read = faq_read(['page' => 99]);
    faq_check(!is_wp_error($read) && $read->get_data() === [] && $read->get_headers()['X-WP-Total'] === 7, 'FAQ out-of-range page keeps an accurate filtered total');
    $before_url = end($urls);
    update_post_meta(51415, '_elementor_data', 'changed-public-elementor-data');
    $html = faq_document(str_replace('نمونه اتصال را آزمایش کنید.', 'پاسخ منتشرشده تغییر کرد.', $items));
    $read = faq_read(['search' => 'شیار']);
    faq_check(!is_wp_error($read) && str_contains($read->get_data()[0]['content']['rendered'], 'تغییر کرد') && end($urls) !== $before_url, 'Elementor meta edit changes the public read version and answer without an index rebuild');
    delete_transient('gpante_support_faq_cache');
    $ranking = '';
    foreach (['a','b','c','d'] as $id) $ranking .= faq_item('faq-rank-' . $id, 'راهنمای عمومی ' . $id, 'متن پاسخ درباره جایگذاری');
    $html = faq_document($ranking . faq_item('faq-rank-z', 'راهنمای جایگذاری', 'پاسخ عنوان مرتبط'));
    $read = faq_read(['search' => 'جایگذاری']);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-rank-z','faq-rank-a','faq-rank-b'] && $read->get_headers()['X-WP-Total'] === 5, 'question-title relevance outranks answer-only matches before pagination, with stable ID ties');
    $read = faq_read(['search' => 'جایگذاری', 'page' => 2]);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-rank-c','faq-rank-d'], 'remaining relevance-ranked FAQ matches keep complete second-page coverage');
    delete_transient('gpante_support_faq_cache');
    $html = faq_document(faq_item('faq-phone', 'اگر ایمیل ندارم می‌توانم خرید کنم؟', 'شماره تلفن الزامی است.')
        . faq_item('faq-mobile', 'چطور فایل را با موبایل باز کنم؟', 'نرم‌افزار سازگار لازم است.')
        . faq_item('faq-slot', 'اگر زبانه داخل شیار جا نشود چه کنم؟', 'ضخامت و مقیاس را بررسی کنید.')
        . faq_item('faq-open', 'اگر فایل باز نشد چه کنم؟', 'فرمت و نرم‌افزار سازگار را بررسی کنید.'));
    foreach (['شماره همراه', 'شماره موبایل', 'شماره تلفن'] as $term) {
        $read = faq_read(['search' => $term]);
        faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-phone'], 'controlled phone phrase finds checkout information: ' . $term);
    }
    $read = faq_read(['search' => 'موبایل']);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-mobile','faq-phone'], 'literal mobile title precedes an aliased phone-field answer');
    $read = faq_read(['search' => 'فایل موبایل']);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-mobile'], 'device context does not become checkout guidance');
    foreach (['جا نمیره', 'جا نمی‌ره', 'جا نمی رود'] as $term) {
        $read = faq_read(['search' => $term]);
        faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-slot'], 'negative colloquial fit retains the official answer: ' . $term);
    }
    $read = faq_read(['search' => 'فایل باز نمیشه']);
    faq_check(!is_wp_error($read) && array_column($read->get_data(), 'id') === ['faq-open'], 'negative colloquial file opening finds its official answer');
    foreach (['موبایل ۲۰۲۴', 'جا نمیره پلکسی'] as $term) {
        $read = faq_read(['search' => $term]);
        faq_check(!is_wp_error($read) && $read->get_data() === [], 'explicit version or material remains required: ' . $term);
    }
    delete_transient('gpante_support_faq_cache');
    $html = faq_document($items . faq_item('faq-cdr', 'تکراری', 'پاسخ تکراری'));
    faq_check(is_wp_error(faq_read([])), 'duplicate question identifiers fail closed rather than mixing answers');
    $html = faq_document('<details data-faq-item id="faq-incomplete"><summary>سؤال بی‌پاسخ</summary></details>');
    faq_check(is_wp_error(faq_read([])), 'missing answer is an unavailable source rather than an empty successful search');
    $html = faq_document($items); $status = 302;
    faq_check(is_wp_error(faq_read([])), 'redirected FAQ source is rejected');
    $status = 503;
    faq_check(is_wp_error(faq_read([])), 'failed page read is not no_matches');
    $status = 200; $content_type = 'application/json';
    faq_check(is_wp_error(faq_read([])), 'non-HTML source is rejected');
    $content_type = 'text/html'; $html = str_repeat('x', 1048576);
    faq_check(is_wp_error(faq_read([])), 'response-size boundary cannot be mistaken for a complete FAQ');
    $html = faq_document($items);
    faq_check(!is_wp_error(faq_read([])), 'healthy FAQ primes the public cache before visibility checks');
    $count = count($urls);
    faq_check(is_wp_error(faq_read(['categoryId' => 1])) && count($urls) === $count, 'article category cannot change the FAQ scope or trigger an HTTP read');
    foreach ([['post_status'=>'private'], ['post_status'=>'publish','post_password'=>'fixture-password']] as $change) {
        wp_update_post(['ID'=>51415]+$change);
        faq_check(is_wp_error(faq_read([])) && count($urls) === $count, 'private or password-protected FAQ is rejected before fetching');
    }
    foreach (['https://t.me/gpante_designer?x=1','https://t.me/gpante_designer.evil','https://gpante.com/faq-ai/#faq-x%0a','https://gpante.com/wp-admin/','https://gpante.com/file.zip',null] as $bad) faq_check(!gpante_support_faq_link($bad), 'unsafe FAQ reference is rejected');
    echo "Native FAQ source integration passed.\n";
} finally {
    remove_filter('pre_http_request', $http, 10);
    delete_transient('gpante_support_faq_cache');
    wp_delete_post(51415, true);
}
