<?php
// Isolated behavioral checks for option validation and schema migration; no network.
define('ABSPATH', __DIR__);
define('GPANTE_SUPPORT_SCHEMA_VERSION', 1);
define('GPANTE_SUPPORT_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1');
$config = false;
$autoload = true;
$admin = true;
$errors = array();
$writes = 0;
function get_option($name, $default = false) { return false === $GLOBALS['config'] ? $default : $GLOBALS['config']; }
function add_option($name, $value, $unused, $autoload) { $GLOBALS['config'] = $value; $GLOBALS['autoload'] = $autoload; ++$GLOBALS['writes']; }
function update_option($name, $value, $autoload) { $GLOBALS['config'] = $value; $GLOBALS['autoload'] = $autoload; ++$GLOBALS['writes']; }
function wp_set_option_autoload($name, $autoload) { $GLOBALS['autoload'] = $autoload; }
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
check($config === array_merge($legacy, array('schema_version' => 1)) && $autoload === false, 'legacy config migrates without losing values');
$before = $writes;
gpante_support_upgrade();
check($writes === $before, 'migration is idempotent');
$config['schema_version'] = 99;
$future = $config;
gpante_support_upgrade();
check($config === $future && gpante_support_sanitize_settings(array('enabled' => '1')) === $future, 'future schema cannot be downgraded');
check(!str_contains(json_encode($errors), str_repeat('fixture-only-', 4)), 'errors never include the secret');
echo "Plugin settings and schema checks passed.\n";
