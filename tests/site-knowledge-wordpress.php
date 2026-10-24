<?php
// Run only through WP-CLI against the isolated local fixture installation.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== 'https://127.0.0.1:9443') throw new RuntimeException('Isolated local test site required');
require_once __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/catalog.php';
function site_native_check($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function site_native_read($params = []) { $r = new WP_REST_Request('GET', '/gpante-support/v1/knowledge'); $r->set_query_params(['source'=>'site'] + $params); return gpante_support_catalog_knowledge($r); }
$GLOBALS['gpante_site_fixture_ids'] = [];
$ids =& $GLOBALS['gpante_site_fixture_ids'];
$saved_options = [];
foreach (['show_on_front','page_on_front'] as $name) $saved_options[$name] = get_option($name);
// page_link receives an ID, post_link a post object.
$page_link = static function($url, $id) { return (int) get_option('page_on_front') === (int) $id ? 'https://gpante.com/' : 'https://gpante.com/site-fixture-' . $id . '/'; };
$post_link = static function($url, $post) { return 'https://gpante.com/site-fixture-' . $post->ID . '/'; };
add_filter('page_link', $page_link, 10, 2); add_filter('post_link', $post_link, 10, 2);
function site_native_create($args) { $id = wp_insert_post($args + ['post_status'=>'publish','post_type'=>'page'], true); if (is_wp_error($id)) throw new RuntimeException('Fixture insert failed'); $GLOBALS['gpante_site_fixture_ids'][]=$id; return $id; }
try {
    $topic = 'سایتنمونه';
    $homepage = site_native_create(['post_title'=>'صفحه نخست عمومی', 'post_content'=>'آموزش نشانگرمتننخست']);
    update_option('show_on_front', 'page'); update_option('page_on_front', $homepage);
    $r = site_native_read(['search'=>'نشانگرمتننخست']);
    site_native_check(!is_wp_error($r) && array_column($r->get_data(), 'id') === [$homepage] && $r->get_data()[0]['link']==='https://gpante.com/' && !$r->get_data()[0]['navigation_only'], 'ordinary public front page body participates in search at exact site root');
    $r = site_native_read(['contentId'=>$homepage]);
    site_native_check(!is_wp_error($r) && str_contains($r->get_data()[0]['content']['rendered'], 'نشانگرمتننخست'), 'ordinary public front page body supports ID read');
    $landing = site_native_create(['post_title'=>"اموزش کورل $topic",'post_content'=>'آموزش برای دستگاه لیزر و CNC.']);
    $installer = site_native_create(['post_title'=>"نصب کورل ۲۰۲۶ $topic",'post_type'=>'post','post_content'=>'راهنمای نصب نرم افزار.']);
    $free = site_native_create(['post_title'=>"آموزش رایگان CorelDRAW 2026 $topic",'post_content'=>'گزینه رایگان.']);
    $private = site_native_create(['post_title'=>"$topic خصوصی",'post_status'=>'private','post_content'=>'SECRET_PRIVATE']);
    $password = site_native_create(['post_title'=>"$topic رمزدار",'post_password'=>'fixture','post_content'=>'SECRET_PASSWORD']);
    $draft = site_native_create(['post_title'=>"$topic پیش نویس",'post_status'=>'draft']);
    $elementor = site_native_create(['post_title'=>"برگه $topic",'post_content'=>'متن قدیمی مخفی نباید منبع شود']);
    update_post_meta($elementor, '_elementor_data', wp_slash(wp_json_encode([['elType'=>'e-paragraph','settings'=>['paragraph'=>['$$type'=>'escaped-html','value'=>'&lt;p&gt;درس المنتور یکتاعبارت&lt;/p&gt;']]]])));
    $r = site_native_read(['search'=>"کورل $topic",'intent'=>'learning']);
    if (is_wp_error($r)) throw new RuntimeException('Site source failed: '.$r->get_error_code());
    $ranked = array_column($r->get_data(), 'id');
    site_native_check(in_array($ranked[0], [$landing,$free], true) && array_search($landing,$ranked,true) < array_search($installer,$ranked,true) && $r->get_headers()['X-WP-Total'] === 3, 'learning pages outrank installation and aliases match across pages/posts');
    $r = site_native_read(['search'=>"اموزش کورل $topic",'intent'=>'learning']);
    site_native_check(!is_wp_error($r) && $r->get_headers()['X-WP-Total']===3,'learning intent spelling variant is not an extra content requirement');
    $r = site_native_read(['search'=>"کورل ۲۰۲۶ $topic",'intent'=>'installation']);
    site_native_check(!is_wp_error($r) && $r->get_data()[0]['id'] === $installer && $r->get_headers()['X-WP-Total'] === 2, 'installation keeps explicit normalized version and purpose ranking');
    $r = site_native_read(['search'=>"کورل رایگان 2026 $topic"]);
    site_native_check(!is_wp_error($r) && array_column($r->get_data(),'id') === [$free], 'free and version terms remain conjunctive');
    $r = site_native_read(['search'=>'یکتاعبارت']);
    site_native_check(!is_wp_error($r) && array_column($r->get_data(),'id') === [$elementor], 'native Elementor text is searchable without post_content copy');
    $modified = get_post($elementor)->post_modified;
    update_post_meta($elementor, '_elementor_data', wp_slash(wp_json_encode([['elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'عبارتتازه پس از ویرایش']]])));
    $r = site_native_read(['search'=>'یکتاعبارت']);
    site_native_check(!is_wp_error($r) && $r->get_data() === [] && get_post($elementor)->post_modified === $modified, 'Elementor metadata invalidates text cache despite unchanged post_modified');
    $r = site_native_read(['search'=>'عبارتتازه']);
    site_native_check(!is_wp_error($r) && array_column($r->get_data(),'id') === [$elementor], 'current Elementor text replaces cached old text');
    wp_update_post(['ID'=>$elementor,'post_excerpt'=>'خلاصهکهنه']);
    update_post_meta($elementor, '_elementor_data', '[]');
    $r = site_native_read(['contentId'=>$elementor]);
    site_native_check(!is_wp_error($r) && $r->get_data()[0]['content']['rendered']==='', 'clearing Elementor document removes prior text and stale stored excerpt from ID read');
    foreach (['عبارتتازه','متن قدیمی','خلاصهکهنه'] as $stale) {
        $r=site_native_read(['search'=>$stale]);
        site_native_check(!is_wp_error($r) && !in_array($elementor,array_column($r->get_data(),'id'),true), 'cleared Elementor body and excerpt do not reappear in search');
    }
    foreach (['broken-json','[broken-json','{}','null','["invalid"]'] as $invalid) {
        update_post_meta($elementor, '_elementor_data', $invalid);
        $r=site_native_read(['contentId'=>$elementor]);
        site_native_check(is_wp_error($r) && $r->get_error_code()==='gpante_support_catalog_unavailable', 'malformed authoritative Elementor ID read reports catalog unavailable');
        $r=site_native_read(['search'=>'خلاصهکهنه']);
        site_native_check(is_wp_error($r) && $r->get_error_code()==='gpante_support_catalog_unavailable', 'malformed authoritative Elementor search reports catalog unavailable');
    }
    delete_post_meta($elementor, '_elementor_data');
    $r=site_native_read(['contentId'=>$elementor]);
    site_native_check(!is_wp_error($r) && str_contains($r->get_data()[0]['content']['rendered'],'متن قدیمی'), 'deleting Elementor metadata restores ordinary stored content');
    update_post_meta($elementor, '_elementor_data', '');
    $r=site_native_read(['contentId'=>$elementor]);
    site_native_check(!is_wp_error($r) && $r->get_data()[0]['content']['rendered']==='', 'present empty meta value cannot reuse absent-meta cached body');
    $late = site_native_create(['post_title'=>"متن بلند $topic",'post_content'=>str_repeat('<p>مقدمه عمومی.</p>',1000) . '<p>نقطهمرتبط تبدیل کورل به DXF.</p><script>SECRET_SCRIPT</script><form>SECRET_FORM</form>']);
    $r = site_native_read(['contentId'=>$late,'search'=>'نقطهمرتبط']); $item=$r->get_data()[0];
    site_native_check($item['content']['truncated'] && mb_strlen($item['content']['rendered']) <= 6000 && str_contains($item['content']['rendered'],'نقطهمرتبط') && !str_contains($item['content']['rendered'],'SECRET'), 'detail read selects relevant late section and strips active/customer-dependent markup');
    foreach ([$private,$password,$draft] as $id) { $r=site_native_read(['contentId'=>$id]); site_native_check(!is_wp_error($r) && $r->get_data()===[], 'nonpublic direct reads expose no content'); }
    wp_update_post(['ID'=>$elementor,'post_status'=>'private']); $r=site_native_read(['search'=>'عبارتتازه']);
    site_native_check(!is_wp_error($r) && $r->get_data()===[], 'private transition wins over primed text cache');
    $nav=site_native_create(['post_title'=>"حساب $topic",'post_content'=>'[woocommerce_my_account]SECRET_ACCOUNT[/woocommerce_my_account]']);
    $r=site_native_read(['search'=>"حساب $topic"]); $item=$r->get_data()[0];
    site_native_check($item['navigation_only'] && !isset($item['excerpt']) && !isset($item['content']), 'account pages are title/link navigation only');
    site_native_check(site_native_read(['contentId'=>$nav])->get_data()===[], 'navigation page detail content cannot be read');
    foreach ([['categoryId'=>1],['intent'=>'unknown'],['contentId'=>'https://evil.example'],['contentId'=>$late,'page'=>1],['contentId'=>$late,'intent'=>'learning']] as $bad) site_native_check(is_wp_error(site_native_read($bad)), 'invalid site combination rejected');
    for($i=0;$i<7;$i++) site_native_create(['post_title'=>"پوششصفحه $i $topic",'post_content'=>'متن عمومی']);
    $all=[];
    for($page=1;$page<=3;$page++) { $r=site_native_read(['search'=>"پوششصفحه $topic",'page'=>$page]); site_native_check($r->get_headers()['X-WP-Total']===7 && $r->get_headers()['X-WP-TotalPages']===3,'site pagination preserves complete totals'); $all=array_merge($all,array_column($r->get_data(),'id')); }
    site_native_check(count($all)===7 && count(array_unique($all))===7,'all matching pages covered without duplicate or manual allowlist');
    $r=site_native_read(['search'=>"پوششصفحه $topic",'page'=>99]); site_native_check($r->get_data()===[] && $r->get_headers()['X-WP-Total']===7,'out-of-range site page retains total');
    wp_delete_post($all[0],true); $r=site_native_read(['search'=>"پوششصفحه $topic"]); site_native_check($r->get_headers()['X-WP-Total']===6,'deletion is immediately reflected');
    $product=new WC_Product_Simple(); $product->set_name("دوره کورل $topic"); $product->set_status('publish'); $product->set_regular_price('0'); $product->set_catalog_visibility('visible'); $pid=$product->save(); $ids[]=$pid;
    $r=site_native_read(['search'=>"دوره کورل $topic",'intent'=>'learning']);
    site_native_check(!is_wp_error($r) && $r->get_data()[0]['content_type']==='product' && $r->get_data()[0]['prices']['price']==='0','site search returns eligible course with current structured zero price');
    site_native_check(is_wp_error(site_native_read(['contentId'=>$pid])),'product content read requires Product_Details');
    $product->set_regular_price('123'); $product->save(); $r=site_native_read(['search'=>"دوره کورل $topic"]);
    site_native_check($r->get_data()[0]['prices']['price']!=='0','site search rereads current mutable product price');
    $product->set_catalog_visibility('hidden'); $product->save(); $r=site_native_read(['search'=>"دوره کورل $topic"]);
    site_native_check(!is_wp_error($r) && $r->get_data()===[],'hidden product excluded from shared search');
    $rollover=[];
    for($i=0;$i<105;$i++) $rollover[]=site_native_create(['post_title'=>"عبوربسته $topic $i",'post_type'=>$i%2?'post':'page','post_content'=>'متن عمومی']);
    $r=site_native_read(['search'=>"عبوربسته $topic",'page'=>35]);
    site_native_check(!is_wp_error($r) && $r->get_headers()['X-WP-Total']===105 && $r->get_headers()['X-WP-TotalPages']===35 && count($r->get_data())===3 && count(array_intersect(array_column($r->get_data(),'id'),$rollover))===3, 'automatic discovery crosses internal 100-document batches with exact totals and final page');
    echo "Native site knowledge integration passed.\n";
} finally { foreach ($saved_options as $name=>$value) { if ($value === false) delete_option($name); else update_option($name,$value); } remove_filter('page_link',$page_link,10); remove_filter('post_link',$post_link,10); foreach($ids as $id) { delete_transient('gpante_site_'.$id); wp_delete_post($id,true); } }
