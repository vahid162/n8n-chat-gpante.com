<?php
// Bounded presentation validation without network; real WordPress sanitation is also tested over HTTP.
define('ABSPATH', __DIR__);
$config = false;
$admin = true;
$autoload = true;
$writes = 0;
$autoload_checks = 0;
$errors = array();
$routes = array();
function get_option($name, $default = false) { if ('gpante_support_appearance' !== $name) throw new RuntimeException('Private option read'); return false === $GLOBALS['config'] ? $default : $GLOBALS['config']; }
function add_option($name, $value, $unused, $autoload) { $GLOBALS['config']=$value; $GLOBALS['autoload']=$autoload; ++$GLOBALS['writes']; }
function update_option($name,$value,$autoload) { $GLOBALS['config']=$value; $GLOBALS['autoload']=$autoload; ++$GLOBALS['writes']; }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($url,$protocols) { return $url; }
function wp_set_option_autoload($name, $value) { $GLOBALS['autoload']=$value; ++$GLOBALS['autoload_checks']; }
function current_user_can($cap) { return $GLOBALS['admin'] && 'manage_options' === $cap; }
function add_action(...$args) {}
function __($text, $domain) { return $text; }
function sanitize_text_field($text) { return trim(preg_replace('/\s+/u',' ',strip_tags($text))); }
function sanitize_textarea_field($text) { return trim(strip_tags($text)); }
function add_settings_error($name, $code, $message) { $GLOBALS['errors'][]=$code; }
function register_rest_route($namespace,$route,$args) { $GLOBALS['routes'][$route]=$args; }
function gpante_support_response($data) { return $data; }
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
require __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/appearance.php';
gpante_support_appearance_upgrade();
check($autoload === false && $writes === 1, 'new option is nonautoloaded');
$defaults=$config;
gpante_support_appearance_upgrade();
check($writes === 1, 'upgrade idempotent');
check($autoload_checks===0,'ordinary initialization performs no autoload query');
$autoload=true;gpante_support_appearance_upgrade(false);check($autoload===false,'activation repairs appearance autoload');
check(gpante_support_valid_appearance($defaults), 'default palette readable');
$config['schema_version']=1;unset($config['links']);$legacy=$config;gpante_support_appearance_upgrade();
check($config['schema_version']===2 && count($config['links'])===4 && $config['texts']===$legacy['texts'] && $config['colors']===$legacy['colors'],'legacy appearance migrates without losing custom texts or colors');
$before=$writes;gpante_support_appearance_upgrade();check($writes===$before,'appearance migration idempotent');
check(abs(gpante_support_color_contrast('#000000','#ffffff')-21)<0.00001, 'known contrast 21:1');
check(gpante_support_color_contrast('#777777','#ffffff')<4.5 && gpante_support_color_contrast('#767676','#ffffff')>=4.5, 'contrast threshold not rounded');
$custom=$defaults;
$custom['texts']['title']='راهنمای فروشگاه';
$custom['colors']['primary']='#123456';
check(gpante_support_sanitize_appearance($custom)===$custom,'valid custom settings');
$config=$custom;
check(gpante_support_sanitize_appearance($config)===$config,'repeated sanitization idempotent');
foreach (array('primary','surface','messages','user','assistant') as $key) {
 $invalid=$custom;
 $invalid['colors'][$key]=$key==='primary' ? $custom['colors']['on_primary'] : $custom['colors']['text'];
 check(gpante_support_sanitize_appearance($invalid)===$custom,'unreadable palette preserves all previous values');
}
foreach (array('#fff','red','#123456;display:none',array()) as $bad) {
 $invalid=$custom; $invalid['colors']['primary']=$bad;
 check(gpante_support_sanitize_appearance($invalid)===$custom,'invalid CSS rejected');
}
foreach (array('',str_repeat('م',81),array()) as $bad) {
 $invalid=$custom; $invalid['texts']['title']=$bad;
 check(gpante_support_sanitize_appearance($invalid)===$custom,'invalid text rejected');
}
$input=$custom; $input['texts']['title']='<b>عنوان امن</b>'; $input['shared_key']='must-never-be-public'; $input['texts']['policy']='bad';
$result=gpante_support_sanitize_appearance($input);
check($result['texts']['title']==='عنوان امن' && !isset($result['shared_key']) && !isset($result['texts']['policy']), 'strip HTML and ignore unowned fields');
$admin=false;
check(gpante_support_sanitize_appearance($defaults)===$custom,'subscriber denied');
$admin=true;
check(gpante_support_sanitize_appearance(array('restore_defaults'=>'1'))===$defaults,'native reset');
$config['schema_version']=99;
$future=$config;
gpante_support_appearance_upgrade();
check($config===$future && gpante_support_sanitize_appearance(array('restore_defaults'=>'1'))===$future,'future schema preserved');
check(gpante_support_get_appearance()===$defaults,'future schema uses safe public fallback');
$config=$custom;
$config['shared_key']='must-never-be-public';
$config['colors']['unexpected']='hidden';
gpante_support_appearance_routes();
check($routes['/appearance']['permission_callback']==='__return_true' && $routes['/appearance']['methods']==='GET','public read only');
$public=$routes['/appearance']['callback']();
check($public['version']===1 && count($public['texts'])===8 && count($public['colors'])===8,'bounded public contract');
check(!str_contains(json_encode($public),'must-never-be-public') && !isset($public['colors']['unexpected']),'public allowlist excludes private/unknown fields');
echo "Appearance: migration, validation, contrast, capability, reset and public allowlist passed.\n";

$custom=$defaults;
$custom['links'][0]=array('label'=>'طرح‌ها','url'=>'/shop/');
$custom['links'][1]=array('label'=>'راهنما','url'=>'https://example.com/help/');
$config=$defaults;
check(gpante_support_sanitize_appearance($custom)===$custom,'four optional links accept HTTPS and relative paths');
$config=$custom;
$old_form=$custom;unset($old_form['links']);
check(gpante_support_sanitize_appearance($old_form)===$custom,'older open settings form preserves configured links');
foreach(array('javascript:alert(1)','data:text/html,x','http://example.com/','//example.com/','https://u:p@example.com/','/\\evil','/%0aevil') as $bad){
 $input=$custom;$input['links'][0]['url']=$bad;
 check(gpante_support_sanitize_appearance($input)===$custom,'unsafe shortcut rejected');
}
$input=$custom;$input['links'][]=array('label'=>'fifth','url'=>'/shop/');
check(gpante_support_sanitize_appearance($input)===$custom,'fifth shortcut rejected');
$input=$custom;$input['links'][0]['url']='';
check(gpante_support_sanitize_appearance($input)===$custom,'label requires valid destination');
$input=$custom;$input['links'][0]['label']='';
check(gpante_support_sanitize_appearance($input)===$input,'blank label hides button and may keep destination');
echo "Shortcut URL validation and migration checks passed.\n";

$config=$custom;
$config['texts']['send']='نام دسترس‌پذیر قبلی';
$submission=$config;unset($submission['texts']['send']);
check(gpante_support_sanitize_appearance($submission)===$config,'hidden send field preserves its prior accessible name during native settings saves');
echo "Hidden send setting compatibility passed.\n";
