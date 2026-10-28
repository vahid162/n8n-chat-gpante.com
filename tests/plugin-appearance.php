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
function get_option($name, $default = false) { if ('elementor_active_kit' === $name) return 777; if ('gpante_support_appearance' !== $name) throw new RuntimeException('Private option read'); return false === $GLOBALS['config'] ? $default : $GLOBALS['config']; }
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
check($config['schema_version']===3 && $config['color_mode']==='site' && count($config['links'])===4 && $config['texts']===$legacy['texts'] && $config['colors']===$legacy['colors'],'legacy appearance migrates without losing custom texts or colors');
$before=$writes;gpante_support_appearance_upgrade();check($writes===$before,'appearance migration idempotent');
check(abs(gpante_support_color_contrast('#000000','#ffffff')-21)<0.00001, 'known contrast 21:1');
check(gpante_support_color_contrast('#777777','#ffffff')<4.5 && gpante_support_color_contrast('#767676','#ffffff')>=4.5, 'contrast threshold not rounded');
$custom=$defaults;
$custom['color_mode']='custom';
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
$custom['color_mode']='custom';
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

// Public theme sources are the only additional reads permitted by this fixture.
function woodmart_get_opt($name) {
 check(in_array($name,['primary-color','secondary-color','text-font','body-background','pages-background'],true),'bounded theme getter');
 return $GLOBALS['theme_colors'][$name]??null;
}
function wp_get_global_settings($path) {
 check($path===['color','palette'],'only the public WordPress palette is read');
 return $GLOBALS['wp_palette']??[];
}
function get_post_meta($id,$key,$single) {
 check($id===777 && $key==='_elementor_page_settings' && $single===true,'only active Elementor kit palette');
 return $GLOBALS['kit_palette']??[];
}
$config=$defaults;
$config['texts']['title']='عنوان قبلی';
$config['links'][0]=['label'=>'راهنما','url'=>'/help/'];
$legacy=$config;unset($config['color_mode']);$config['schema_version']=2;
gpante_support_appearance_upgrade();
check($config['color_mode']==='site' && $config['texts']===$legacy['texts'] && $config['links']===$legacy['links'],'factory colors migrate automatically without resetting texts or links');
$legacy=$config;$legacy['schema_version']=2;unset($legacy['color_mode']);$legacy['colors']['primary']='#123456';$config=$legacy;
gpante_support_appearance_upgrade();
check($config['color_mode']==='custom' && $config['colors']===$legacy['colors'] && $config['links']===$legacy['links'],'legacy manual colors remain manual');
$GLOBALS['theme_colors']=['primary-color'=>['idle'=>'#285846'],'secondary-color'=>['idle'=>'#E4B23C'],'text-font'=>[['color'=>'#1D2E29']],'pages-background'=>['color'=>'#F5F7F2']];
$manual=gpante_support_get_appearance();check($manual['colors']===$legacy['colors'],'site palette cannot overwrite existing manual colors');
$auto=$config;$auto['color_mode']='site';$config=gpante_support_sanitize_appearance($auto);
$stored=$config;$before=$writes;$resolved=gpante_support_get_appearance();
check($resolved['colors']['primary']==='#285846' && $resolved['colors']['accent']==='#e4b23c' && $resolved['colors']['text']==='#1d2e29' && $resolved['colors']['messages']==='#f5f7f2','active WoodMart values supply default palette');
check($resolved['colors']['on_primary']==='#ffffff' && gpante_support_valid_appearance($resolved),'derived palette satisfies every contrast check');
check($resolved['texts']===$stored['texts'] && $resolved['links']===$stored['links'] && $config===$stored && $writes===$before,'theme resolution preserves saved data and performs no writes');
$GLOBALS['theme_colors']['primary-color']['idle']='#173f53';
check(gpante_support_get_appearance()['colors']['primary']==='#173f53' && $config===$stored,'theme changes apply on the next read without copying colors');
$old_form=$stored;unset($old_form['color_mode']);$old_form['colors']['primary']='#123456';
check(gpante_support_sanitize_appearance($old_form)['color_mode']==='custom','older settings forms retain manual color edits');
$auto=$stored;unset($auto['colors']);check(gpante_support_sanitize_appearance($auto)===$stored,'automatic mode retains backing manual values even when fields are omitted');
$bad=$stored;$bad['color_mode']='unknown';check(gpante_support_sanitize_appearance($bad)===$stored,'unknown color modes fail without partial changes');
$GLOBALS['wp_palette']=['theme'=>[['slug'=>'primary','color'=>'#345'],['slug'=>'contrast','color'=>'#111']],'custom'=>[['slug'=>'primary','color'=>'#123456']]];
$GLOBALS['theme_colors']=[];
check(gpante_support_site_colors()['primary']==='#123456','WordPress custom palette overrides theme palette');
define('ELEMENTOR_VERSION','fixture-only');
$GLOBALS['kit_palette']=['system_colors'=>[['_id'=>'primary','color'=>'#654321'],['_id'=>'secondary','color'=>'#abcdef'],['_id'=>'text','color'=>'#111111']]];
check(gpante_support_site_colors()['primary']==='#654321','active Elementor palette supplies semantic colors when theme getter has no value');
$GLOBALS['theme_colors']=['primary-color'=>['idle'=>'#285846']];
check(gpante_support_site_colors()['primary']==='#285846','active WoodMart palette has precedence over builder defaults');
foreach(['red','#fff;display:none','rgba(0,0,0,0)',[],new stdClass()] as $invalid) {
 $GLOBALS['theme_colors']['primary-color']['idle']=$invalid;
 check(gpante_support_site_colors()['primary']==='#654321','invalid theme values cannot replace a valid lower-priority palette');
}
check(gpante_support_theme_color('#AbC')==='#aabbcc','short theme hex expands to safe RGB');
$GLOBALS['theme_colors']=['primary-color'=>['idle'=>'#e4b23c'],'text-font'=>[['color'=>'#ffffff']],'pages-background'=>['color'=>'#000000']];
$readable=gpante_support_site_colors();
check($readable['on_primary']==='#000000' && $readable['messages']===$readable['surface'],'automatic low-contrast values use readable text and neutral backgrounds');
$resolved['colors']=$readable;check(gpante_support_valid_appearance($resolved),'extreme theme palette stays readable');
$config=$stored;$public=$routes['/appearance']['callback']();
check(array_keys($public)===['version','texts','colors','links'] && !isset($public['color_mode']) && $public['colors']===$readable,'public contract exposes only resolved color values');
echo "Site palette: source precedence, dynamic refresh, migration, manual override, safe RGB and contrast passed.\n";

// Rendering the local sample must not mount the public chat or change saved appearance.
function esc_html($text) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_html_e($text,$domain) { echo esc_html($text); }
function esc_attr_e($text,$domain) { echo esc_attr($text); }
function wp_json_encode($value) { return json_encode($value); }
function selected($expected,$actual) { if ($expected===$actual) echo 'selected'; }
function settings_errors($name) {}
function settings_fields($name) { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function submit_button() { echo '<button type="submit">Save</button>'; }
$config=$defaults;
$config['texts']['title']='عنوان <unsafe> نمایشی';
// Stored invalid data falls back to the already validated public defaults.
$before=$config;$before_writes=$writes;
ob_start();gpante_support_appearance_page();$page=ob_get_clean();
check($config===$before && $writes===$before_writes,'appearance page rendering never writes settings');
check(!str_contains($page,'<unsafe>'),'unsafe stored text is never rendered as HTML');
$sample=strstr($page,'<div class="gpante-admin-preview"');
$sample=strstr($sample,'</aside>',true);
check(false!==$sample && !preg_match('/<(?:input|button|textarea|a)\b/i',$sample),'sample is inert without real controls or navigable anchors');
check(!str_contains($sample,'id="gpante-support"') && !str_contains($sample,'data-endpoint') && !str_contains($sample,'wp-json'),'sample does not mount a chat controller or endpoint');
check(str_contains($page,'_wpnonce') && str_contains($page,'action="options.php"'),'native appearance form and nonce remain');
check(str_contains($page,'data-gpante-confirm=') && str_contains($page,'هر چهار دکمهٔ راهنما و لینک'),'full reset clearly requires confirmation of all four links');
check(substr_count($sample,'data-gpante-preview-link=')===4,'all four sample slots are available for unsaved link edits');
$admin=false;ob_start();gpante_support_appearance_page();$page=ob_get_clean();check($page==='','unauthorized viewer receives no appearance markup');
echo "Appearance presentation: inert local preview, safe fallback, nonce, capability and no settings writes passed.\n";
