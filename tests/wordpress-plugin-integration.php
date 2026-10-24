<?php
// Run only via wp eval-file on the isolated fixture install.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== (defined('GPANTE_SUPPORT_TEST_URL') ? GPANTE_SUPPORT_TEST_URL : 'https://127.0.0.1:9443')) throw new RuntimeException('Local test site required');
function gptest_check($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
wp_set_current_user(get_user_by('login', 'gpante_admin')->ID);
$config = get_option('gpante_support_runtime');
gptest_check($config['enabled'] === false && $config['shared_key'] === '', 'activation defaults disabled');
$autoload = $GLOBALS['wpdb']->get_var("SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'gpante_support_runtime'");
gptest_check(in_array($autoload, array('no','off','auto-off'), true), 'secret option not autoloaded');
gpante_support_register_settings();
$registered = get_registered_settings()['gpante_support_runtime'];
gptest_check($registered['show_in_rest'] === false, 'setting excluded from REST');
$key = str_repeat('fixture-only-', 4);
update_option('gpante_support_runtime', gpante_support_sanitize_settings(array('enabled' => '1', 'shared_key' => $key)));
update_option('gpante_support_runtime', gpante_support_sanitize_settings(array('enabled' => '1', 'shared_key' => '')));
gptest_check(get_option('gpante_support_runtime')['shared_key'] === $key, 'blank form retains secret');
ob_start(); gpante_support_settings_page(); $html=ob_get_clean();
gptest_check(!str_contains($html, $key) && str_contains($html, '_wpnonce'), 'form contains nonce and no stored secret');
$subscriber=get_user_by('login','gpante_subscriber');
if (!$subscriber) $subscriber_id=wp_insert_user(array('user_login'=>'gpante_subscriber','user_pass'=>'local-fixture-subscriber-only','role'=>'subscriber','user_email'=>'subscriber@example.invalid')); else $subscriber_id=$subscriber->ID;
wp_set_current_user($subscriber_id);
$old=get_option('gpante_support_runtime');
gptest_check(gpante_support_sanitize_settings(array('shared_key'=>str_repeat('b',40))) === $old, 'subscriber cannot mutate config');
ob_start(); gpante_support_settings_page(); $html=ob_get_clean();
gptest_check($html === '', 'subscriber cannot render settings');
wp_set_current_user(get_user_by('login', 'gpante_admin')->ID);
// Migration runs outside admin_init on normal requests; emulate an old stored option.
remove_filter('sanitize_option_gpante_support_runtime','gpante_support_sanitize_settings');
$legacy=$old; unset($legacy['schema_version']); $legacy['extension']='keep';
update_option('gpante_support_runtime',$legacy,true);
gpante_support_upgrade();
$next=get_option('gpante_support_runtime');
gptest_check($next['schema_version'] === 2 && $next['shared_key'] === $key && $next['extension'] === 'keep', 'legacy upgrade preserves settings');
gpante_support_upgrade();
gptest_check(get_option('gpante_support_runtime') === $next, 'upgrade idempotent');
$future=$next; $future['schema_version']=99; update_option('gpante_support_runtime',$future,false);
gpante_support_upgrade();
gptest_check(get_option('gpante_support_runtime') === $future && gpante_support_runtime() === false, 'future schema preserved and fails closed');
update_option('gpante_support_runtime',$next,false);
require_once ABSPATH.'wp-admin/includes/plugin.php';
deactivate_plugins('gpante-support-chat/gpante-support-chat.php');
gptest_check(get_option('gpante_support_runtime') === $next, 'deactivation preserves config');
activate_plugin('gpante-support-chat/gpante-support-chat.php');
gptest_check(get_option('gpante_support_runtime') === $next, 'reactivation preserves config');
// Test actual WooCommerce schema/visibility and source pagination.
$ids=array();
try {
 foreach (array('visible','catalog','search','hidden','private','password') as $kind) {
  $product=new WC_Product_Simple(); $product->set_name('باکس آزمون '.$kind); $product->set_regular_price($kind === 'visible' ? '0' : '10');
  $product->set_catalog_visibility(in_array($kind,array('visible','catalog','search','hidden'),true)?$kind:'visible');
  $product->set_status($kind === 'private' ? 'private':'publish');
  $id=$product->save(); $ids[$kind]=$id;
  if ($kind === 'password') wp_update_post(array('ID'=>$id,'post_password'=>'fixture-protected'));
 }
 $request=new WP_REST_Request('GET','/gpante-support/v1/products'); $request->set_query_params(array('search'=>'باکس آزمون'));
 $result=gpante_support_catalog_products($request);
 gptest_check(!is_wp_error($result),'actual WooCommerce provider returns schema');
 $data=$result->get_data(); $actual=array_column($data,'id'); sort($actual); $expected=array_values(array_intersect_key($ids,array_flip(array('visible','catalog','search')))); sort($expected);
 gptest_check($actual === $expected,'actual WooCommerce visibility excludes hidden private passworded');
 $free=array_values(array_filter($data,fn($p)=>$p['id']===$ids['visible']))[0];
 gptest_check($free['prices']['price'] === '0','zero price survives actual WooCommerce schema');
 $request->set_query_params(array('search'=>'باکس آزمون','page'=>'99')); $result=gpante_support_catalog_products($request);
 gptest_check(!is_wp_error($result) && $result->get_data() === array() && (int)$result->get_headers()['X-WP-Total'] === 3,'out of range retains complete source total');
} finally { foreach($ids as $id) wp_delete_post($id,true); }
// Network uninstall must remove owned data on every site and preserve unrelated data.
$original_blog=get_current_blog_id();
$uninstall_blogs=is_multisite() ? get_sites(['fields'=>'ids','number'=>0]) : [$original_blog];
foreach($uninstall_blogs as $blog) {
 if(is_multisite()) switch_to_blog($blog);
 gpante_support_upgrade(); gpante_support_appearance_upgrade(); gpante_support_search_index_upgrade();
 update_option('gptest_unrelated','preserve',false);
 set_transient('gpante_support_faq_cache','fixture',300);
 wp_schedule_single_event(time()+300,'gpante_support_search_index_update',[(int)$blog]);
 wp_schedule_single_event(time()+300,'gptest_unrelated_hook');
 if(is_multisite()) restore_current_blog();
}
define('WP_UNINSTALL_PLUGIN',true);
require WP_PLUGIN_DIR.'/gpante-support-chat/uninstall.php';
gptest_check(get_current_blog_id()===$original_blog,'uninstall restores the original blog context');
foreach($uninstall_blogs as $blog) {
 if(is_multisite()) switch_to_blog($blog);
 gptest_check(get_option('gpante_support_runtime',false)===false && get_option('gpante_support_appearance',false)===false && get_transient('gpante_support_faq_cache')===false,'uninstall removes owned options and cache on site '.$blog);
 $table=gpante_support_search_index_table();
 gptest_check($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s',$GLOBALS['wpdb']->esc_like($table)))!==$table,'uninstall removes only the current site owned index on site '.$blog);
 gptest_check(wp_next_scheduled('gpante_support_search_index_update',[(int)$blog])===false && wp_next_scheduled('gpante_support_search_index_batch')===false,'uninstall removes owned scheduled work on site '.$blog);
 gptest_check(get_option('gptest_unrelated')==='preserve' && wp_next_scheduled('gptest_unrelated_hook')!==false,'uninstall preserves unrelated options and cron on site '.$blog);
 wp_unschedule_hook('gptest_unrelated_hook');
 if(is_multisite()) restore_current_blog();
}
// Restore test config for real-browser checks.
update_option('gpante_support_runtime',$next,false);
echo 'WordPress '.get_bloginfo('version').' / WooCommerce '.WC_VERSION.' / PHP '.PHP_VERSION."\n";
