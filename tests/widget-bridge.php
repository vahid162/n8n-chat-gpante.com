<?php
// Focused bridge checks without a WordPress install or network calls.
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$runtime = array();
$outbound = array();
$remote_result = array('status' => 200, 'body' => '{"output":"سلام"}');
$policy=array('version'=>2,'userDays'=>30,'archiveDays'=>30,'allowClear'=>true,'revision'=>1);
$ssl = true;
$registered_actions = array();
function add_action($hook, $callback) { $GLOBALS['registered_actions'][$hook][] = $callback; }
function get_option($name, $default = false) { return $GLOBALS['runtime']; }
function __($text, $domain) { return $text; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function wp_salt($scheme = 'auth') { return 'fixture-salt-not-a-live-secret-' . $scheme; }
function is_ssl() { return $GLOBALS['ssl']; }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE); }
function wp_unslash($value) { return $value; }
function wp_remote_post($url, $args) { $GLOBALS['outbound'][] = array($url, $args);  $r=$GLOBALS['remote_result']; if(is_array($r) && $r['status']===200 && is_array($d=json_decode($r['body'],true))){$d['policy']=$GLOBALS['policy']; if(isset($d['output']))$d['expiresAt']=gmdate('c',time()+86400); if(isset($d['messages']))foreach($d['messages'] as &$m)$m['expiresAt']=gmdate('c',time()+86400); $r['body']=json_encode($d);} return $r; }
function is_wp_error($result) { return $result instanceof WP_Error; }
function wp_remote_retrieve_response_code($result) { return $result['status']; }
function wp_remote_retrieve_body($result) { return $result['body']; }
function wp_remote_retrieve_header($result, $name) { return $result[$name] ?? ''; }
class WP_Error {}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200, public $headers = array()) {}
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
    public function get_headers() { return $this->headers; }
}
class WP_REST_Request {
    public function __construct(public $body, public $headers) {}
    public function get_body() { return $this->body; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? ''; }
}
define('GPANTE_SUPPORT_VERSION', '0.1.0');
define('GPANTE_SUPPORT_SCHEMA_VERSION', 2);
define('GPANTE_SUPPORT_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1');
define('GPANTE_SUPPORT_COOKIE', '__Host-gpante_support');
function home_url($path = '') { return 'https://gpante.com/'; }
function wp_parse_url($url) { return parse_url($url); }
require __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/bridge.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function request($payload, $headers = array()) {
    if(is_array($payload) && !isset($payload['policyVersion'])) $payload['policyVersion']=2;
    return gpante_support_chat(new WP_REST_Request(is_string($payload) ? $payload : json_encode($payload), array_merge(array(
        'origin' => 'https://gpante.com', 'sec-fetch-site' => 'same-origin', 'content-type' => 'application/json'
    ), $headers)));
}
function enabled() {
    $GLOBALS['runtime'] = array('enabled' => true, 'backend_url' => GPANTE_SUPPORT_BACKEND, 'shared_key' => str_repeat('fixture-only-', 4));
}
function cookie_for($token, $created = null) {
    $value = 'v1.' . ($created ?? time()) . '.' . $token;
    return $value . '.' . hash_hmac('sha256', 'gpante-support-cookie|' . $value, wp_salt('auth'));
}

check(request(array('action' => 'loadPreviousSession'))->get_status() === 503, 'missing runtime must stay disabled');
enabled();
foreach (array('https://evil.example', 'https://gpante.com.evil.example', 'http://gpante.com', 'https://gpante.com:443', '') as $origin) {
    check(request(array('action' => 'loadPreviousSession'), array('origin' => $origin))->get_status() === 403, 'reject non-exact Origin');
}
foreach (array('cross-site', 'same-site', 'none', '') as $site) {
    check(request(array('action' => 'loadPreviousSession'), array('sec-fetch-site' => $site))->get_status() === 403, 'reject missing or non-same-origin Fetch Metadata');
}
$ssl = false;
check(request(array('action' => 'loadPreviousSession'))->get_status() === 403, 'session cookies require HTTPS');
$ssl = true;
check(count($outbound) === 0, 'rejected requests cannot reach backend');
foreach (array('{broken', '[]', '{"action":"invented"}', array('action' => 'sendMessage', 'chatInput' => '  '), array('action' => 'sendMessage', 'chatInput' => str_repeat('م', 4001))) as $payload) {
    check(request($payload)->get_status() === 400, 'reject malformed input and excessive text');
}
check(request(str_repeat(' ', 32769))->get_status() === 413, 'bound raw request size before JSON decoding');
check(request(array('action' => 'loadPreviousSession'), array('content-type' => 'text/plain'))->get_status() === 415, 'require JSON');
$runtime['backend_url'] = 'https://evil.example/';
check(request(array('action' => 'loadPreviousSession'))->get_status() === 503, 'fixed destination cannot be configured away');
enabled();
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
$token = rtrim(strtr(base64_encode(str_repeat('a', 32)), '+/', '-_'), '=');
$_COOKIE[GPANTE_SUPPORT_COOKIE] = cookie_for($token);
$response = request(array('action' => 'sendMessage', 'chatInput' => ' سلام ', 'sessionId' => 'attacker_session', 'clientId' => 'attacker_ip'));
check($response->get_status() === 200 && $response->get_data()['output'] === 'سلام', 'valid send returns output');
list($url, $options) = $outbound[count($outbound) - 1];
$sent = json_decode($options['body'], true);
check($url === GPANTE_SUPPORT_BACKEND && $options['timeout'] === 155 && $options['redirection'] === 0, 'fixed HTTPS backend, timeout and no redirect');
check($options['headers']['X-Gpante-Chat-Key'] === $runtime['shared_key'], 'server supplies scoped shared key');
check($sent['sessionId'] !== 'attacker_session' && $sent['clientId'] !== 'attacker_ip' && $sent['chatInput'] === 'سلام', 'browser cannot choose trusted identity');
check(request(array('action'=>'sendMessage','chatInput'=>'old client','policyVersion'=>0))->get_status()===403,'old browser cannot silently accept changed archive semantics');
$first_session = $sent['sessionId'];
$first_client = $sent['clientId'];
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
request(array('action' => 'sendMessage', 'chatInput' => str_repeat('م', 4000)));
$again = json_decode(end($outbound)[1]['body'], true);
check($again['sessionId'] === $first_session && $again['clientId'] === $first_client, 'signed session is stable; forwarded headers cannot change identity');
foreach (array(cookie_for($token, time() - 30 * DAY_IN_SECONDS - 1), cookie_for($token) . 'x', cookie_for($token, time() + 1000)) as $bad_cookie) {
    $_COOKIE[GPANTE_SUPPORT_COOKIE] = $bad_cookie;
    request(array('action' => 'sendMessage', 'chatInput' => 'سلام'));
    $sent = json_decode(end($outbound)[1]['body'], true);
    check($sent['sessionId'] !== $first_session, 'expired, forged and future cookies get a new server session');
}
$_COOKIE[GPANTE_SUPPORT_COOKIE] = cookie_for(rtrim(strtr(base64_encode(str_repeat('b', 32)), '+/', '-_'), '='));
request(array('action' => 'sendMessage', 'chatInput' => 'سلام'));
check(json_decode(end($outbound)[1]['body'], true)['sessionId'] !== $first_session, 'separate signed cookies stay isolated');
$remote_result = array('status' => 200, 'body' => '{"messages":[{"role":"user","content":"سلام"},{"role":"assistant","content":"درود"}]}');
check(count(request(array('action' => 'loadPreviousSession'))->get_data()['messages']) === 2, 'history crosses bridge with allowed roles only');
$remote_result = array('status' => 200, 'body' => '{"messages":[{"role":"system","content":"internal"}]}');
check(request(array('action' => 'loadPreviousSession'))->get_status() === 502, 'internal message roles cannot cross bridge');
$remote_result = array('status' => 200, 'body' => '{"ok":true}');
check(request(array('action' => 'resetSession'))->get_data()['ok'] === true, 'explicit reset reaches backend');
$remote_result = array('status' => 429, 'body' => '{"error":{"code":"monthly_limit"},"output":"private backend detail"}', 'retry-after' => '120');
$response = request(array('action' => 'sendMessage', 'chatInput' => 'سلام'));
check($response->get_status() === 429 && $response->get_headers()['Retry-After'] === '120', 'propagate bounded retry delay');
check(!str_contains(json_encode($response->get_data()), 'private'), 'backend errors cannot expose private detail');
foreach (array(new WP_Error(), array('status' => 500, 'body' => 'private secret'), array('status' => 200, 'body' => '<html>private</html>')) as $broken) {
    $remote_result = $broken;
    $response = request(array('action' => 'sendMessage', 'chatInput' => 'سلام'));
    check($response->get_status() >= 500 && !str_contains(json_encode($response->get_data()), 'private'), 'transport and malformed response errors fail closed');
}
check(!str_contains(json_encode($response->get_data()), $runtime['shared_key']), 'public response has no shared key');
define('GPANTE_SUPPORT_INLINE_WIDGET', true);
define('GPANTE_SUPPORT_ASSET_BASE', 'https://gpante.com/assets/gpante-support');
$enqueued = array();
function wp_enqueue_style(...$args) { $GLOBALS['enqueued'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['enqueued'][] = $args; }
enabled();
ob_start();
foreach ($registered_actions['wp_footer'] as $callback) $callback();
$footer = ob_get_clean();
foreach ($registered_actions['wp_enqueue_scripts'] as $callback) $callback();
check($footer === '' && $enqueued === array(), 'manual embed must not duplicate PHP markup or assets');
$remote_result = array('status' => 200, 'body' => '{"output":"سلام"}');
check(request(array('action' => 'sendMessage', 'chatInput' => 'سلام'))->get_status() === 200, 'manual widget mode leaves the server bridge available');
fwrite(STDOUT, "Widget bridge security and action checks passed.\n");
