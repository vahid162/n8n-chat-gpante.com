<?php
// Isolated behavioral checks for option validation and schema migration; no network.
define('ABSPATH', __DIR__);
define('GPANTE_SUPPORT_SCHEMA_VERSION', 2);
define('GPANTE_SUPPORT_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1');
$config = false;
$autoload = true;
$admin = true;
$errors = array();
$writes = 0;
$autoload_checks = 0;
function get_option($name, $default = false) { return false === $GLOBALS['config'] ? $default : $GLOBALS['config']; }
function add_option($name, $value, $unused, $autoload) { $GLOBALS['config'] = $value; $GLOBALS['autoload'] = $autoload; ++$GLOBALS['writes']; }
function update_option($name, $value, $autoload) { $GLOBALS['config'] = $value; $GLOBALS['autoload'] = $autoload; ++$GLOBALS['writes']; }
function wp_set_option_autoload($name, $autoload) { $GLOBALS['autoload'] = $autoload; ++$GLOBALS['autoload_checks']; }
function current_user_can($cap) { return $GLOBALS['admin'] && 'manage_options' === $cap; }
function add_action(...$args) {}
function __($text, $domain) { return $text; }
function add_settings_error($name, $code, $message) { $GLOBALS['errors'][] = array($code, $message); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/settings.php';
gpante_support_upgrade();
check($config['enabled'] === false && $config['shared_key'] === '' && $autoload === false, 'fresh install disabled and not autoloaded');
$defaults = $config;
foreach (array('', 'short', str_repeat('a', 31), str_repeat('a', 257), str_repeat('a', 32) . ' ', str_repeat('م', 32), array()) as $key) {
 $result = gpante_support_sanitize_settings(array('enabled' => '1', 'shared_key' => $key));
 check($result === $defaults, 'invalid/missing key cannot activate or alter valid settings');
}
foreach (array(str_repeat('a', 32), str_repeat('Z', 256), str_repeat('!&$<>', 10)) as $key) {
 check(gpante_support_valid_key($key), 'valid printable ASCII byte range is preserved');
}
$config = gpante_support_sanitize_settings(array('enabled' => '1', 'shared_key' => str_repeat('fixture-only-', 4), 'backend_url' => 'https://evil.invalid'));
check($config['enabled'] === true && $config['backend_url'] === GPANTE_SUPPORT_BACKEND, 'valid admin activation has fixed destination');
check(gpante_support_sanitize_settings($config) === $config, 'Settings API repeated sanitization is idempotent');
$config['admin_key']=str_repeat('fixture-admin-only-',3);
$check_admin=gpante_support_sanitize_settings(array('enabled'=>'1','shared_key'=>'','admin_key'=>''));
check($check_admin['admin_key']===$config['admin_key'],'blank admin key preserved independently');
check(gpante_support_sanitize_settings(array('admin_key'=>'short'))===$config,'invalid admin key cannot overwrite settings');
$config['extension_field'] = 'preserve';
$old = $config;
$config = gpante_support_sanitize_settings(array('shared_key' => ''));
check($config['enabled'] === false && $config['shared_key'] === $old['shared_key'] && $config['extension_field'] === 'preserve', 'blank key preserves secret while checkbox can disable');
$admin = false;
check(gpante_support_sanitize_settings(array('enabled' => '1', 'shared_key' => str_repeat('b', 40))) === $config, 'non-admin cannot mutate');
$admin = true;
unset($config['schema_version']);
$legacy = $config;
$autoload = true;
gpante_support_upgrade();
check($config === array_merge($legacy, array('schema_version' => 2)) && $autoload === false, 'legacy config migrates without losing values');
$before = $writes;
gpante_support_upgrade();
check($writes === $before, 'migration is idempotent');
$checks=$autoload_checks;gpante_support_upgrade();check($autoload_checks===$checks,'ordinary initialization performs no autoload query');
$autoload=true;gpante_support_upgrade(false);check($autoload===false,'activation repairs autoload without changing settings');
$config['schema_version'] = 99;
$future = $config;
gpante_support_upgrade();
check($config === $future && gpante_support_sanitize_settings(array('enabled' => '1')) === $future, 'future schema cannot be downgraded');
check(!str_contains(json_encode($errors), str_repeat('fixture-only-', 4)), 'errors never include the secret');
echo "Plugin settings and schema checks passed.\n";

// The administrator can inspect configuration without revealing or replacing either key.
function esc_html($text) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function esc_html_e($text,$domain) { echo esc_html($text); }
function checked($expected,$actual) { if ($expected===$actual) echo 'checked'; }
function settings_errors($name) {}
function settings_fields($name) { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function submit_button() { echo '<button type="submit">Save</button>'; }
$config=$defaults;
$config['shared_key']=str_repeat('fixture-only-',4);
$before=$config;$before_writes=$writes;
ob_start();gpante_support_settings_page();$page=ob_get_clean();
check($config===$before && $writes===$before_writes,'rendering connection settings never writes values');
check(!str_contains($page,$config['shared_key']),'stored connection key is never rendered');
check(substr_count($page,'type="password"')===2 && substr_count($page,'value=""')===2,'both replacement keys remain blank');
check(str_contains($page,'_wpnonce') && str_contains($page,'action="options.php"'),'native form and nonce are preserved');
check(str_contains($page,'gpante-admin-badge is-configured') && str_contains($page,'gpante-admin-badge is-unconfigured'),'each key reports its own configured state');
$details=strstr($page,'<details');
check(false!==$details && !str_contains(strstr($details,'>',true),'open') && str_contains($details,'id="gpante-chat-key"') && str_contains($details,'id="gpante-admin-key"'),'sensitive replacement fields are collapsed and retain their IDs');
$config['admin_key']=str_repeat('fixture-admin-',4);$config['shared_key']='';
ob_start();gpante_support_settings_page();$page=ob_get_clean();
check(str_contains($page,'gpante-admin-badge is-configured') && str_contains($page,'gpante-admin-badge is-unconfigured') && !str_contains($page,$config['admin_key']),'administrator key status is independent and its secret is absent');
$admin=false;ob_start();gpante_support_settings_page();$page=ob_get_clean();check($page==='','unauthorized viewer receives no settings markup');
echo "Connection presentation: independent statuses, collapsed blank replacements, nonce, no writes or secrets passed.\n";
