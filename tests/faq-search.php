<?php
// Exercise the actual FAQ parser and callback with only its WordPress/HTTP boundary replaced.
define('ABSPATH', __DIR__);
define('GPANTE_SUPPORT_VERSION', 'test');
class WP_Error {
    public function __construct(public $code, public $message = '', public $data = null) {}
}
class WP_REST_Response {
    public $headers = [];
    public function __construct(public $data, public $status = 200) {}
    public function header($name, $value) { $this->headers[$name] = $value; }
    public function get_data() { return $this->data; }
    public function get_headers() { return $this->headers; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function get_post($id) { return (object)['post_type'=>'page','post_status'=>'publish','post_password'=>'','post_modified_gmt'=>'test','post_content'=>'']; }
function get_post_meta(...$args) { return ''; }
function maybe_serialize($value) { return serialize($value); }
function get_transient($key) { return false; }
function set_transient(...$args) {}
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function wp_remote_get(...$args) { return ['body'=>$GLOBALS['faq_html']]; }
function wp_remote_retrieve_response_code($value) { return 200; }
function wp_remote_retrieve_header($value, $header) { return 'text/html'; }
function wp_remote_retrieve_body($value) { return $value['body']; }
require_once __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/catalog.php';

function faq_search_item($id, $question, $answer) {
    return '<details data-faq-item id="faq-' . $id . '"><summary>' . $question . '</summary><div data-faq-answer>' . $answer . '</div></details>';
}
function faq_search_document($items) {
    $GLOBALS['faq_html'] = '<!doctype html><html><body><div data-elementor-id="51415" data-elementor-type="wp-page">' . $items . '</div></body></html>';
}
function faq_search_ids($query) {
    $read = gpante_support_catalog_faq(gpante_support_catalog_search_text($query), 1);
    if (is_wp_error($read)) throw new RuntimeException('Unexpected FAQ error: ' . $read->code);
    return array_column($read->get_data(), 'id');
}
function faq_search_check($actual, $expected, $label) {
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    echo "PASS $label\n";
}

faq_search_document(
    faq_search_item('phone', 'اگر ایمیل ندارم می‌توانم خرید کنم؟', 'شماره تلفن الزامی است و ایمیل اختیاری است.')
    . faq_search_item('mobile', 'چطور فایل را با موبایل باز کنم؟', 'برای فایل طراحی، نرم‌افزار سازگار لازم است.')
    . faq_search_item('slot', 'اگر زبانه داخل شیار جا نشود چه کنم؟', 'ضخامت و مقیاس را بررسی کنید.')
    . faq_search_item('open', 'اگر فایل باز نشد چه کنم؟', 'فرمت و نرم افزار سازگار را بررسی کنید.')
    . faq_search_item('version', 'باز کردن فایل در CorelDRAW 2024', 'نسخه و فرمت را بررسی کنید.')
);
faq_search_check(faq_search_ids('شماره همراه'), ['faq-phone'], 'phone phrase finds the checkout answer');
faq_search_check(faq_search_ids('موبایل'), ['faq-mobile','faq-phone'], 'literal mobile title ranks ahead of phone-only answer');
faq_search_check(faq_search_ids('فایل موبایل'), ['faq-mobile'], 'file/device context does not become checkout');
foreach (['جا نمیره', 'جا نمی ره', 'جا نمی‌رود', 'جا نشدن'] as $query) {
    faq_search_check(faq_search_ids($query), ['faq-slot'], 'colloquial negative fit: ' . $query);
}
foreach (['فایل باز نمیشه', 'فایل باز نمی شود', 'فایل باز نشدن'] as $query) {
    faq_search_check(faq_search_ids($query), ['faq-open'], 'colloquial negative opening: ' . $query);
}
faq_search_check(faq_search_ids('موبایل ۲۰۲۴'), [], 'explicit version is not dropped for an alias');
faq_search_check(faq_search_ids('جا نمیره پلکسی'), [], 'explicit material is not dropped for colloquial wording');
faq_search_check(faq_search_ids('Corel 2024'), ['faq-version'], 'existing controlled Corel alias and version remain intact');
faq_search_check(faq_search_ids('شیار ساختگیناموجود'), [], 'unrecognized query terms remain required');

faq_search_document(faq_search_item('a', 'طرح مناسب اسب', 'توضیح') . faq_search_item('b', 'طرح مناسب', 'توضیح'));
faq_search_check(faq_search_ids('اسب'), ['faq-a','faq-b'], 'whole title word ranks before a title substring');
faq_search_document(faq_search_item('a', 'شیار و زبانه', 'توضیح') . faq_search_item('z', 'راهنمای شیار زبانه', 'توضیح'));
faq_search_check(faq_search_ids('شیار زبانه'), ['faq-z','faq-a'], 'complete title phrase ranks before separated title terms');
echo "FAQ search callback checks passed.\n";
