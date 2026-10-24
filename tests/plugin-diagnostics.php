<?php
// Standalone administrator boundary/escaping checks; no WordPress database or network.
define('ABSPATH', __DIR__);
$admin = true;
$nonce = true;
$actions = array();
$menus = array();
$calls = array();
$no_cache = 0;
$checks = 0;
$result = array();
$health = array('complete' => false, 'invalidDocuments' => 2, 'initializing' => true,
    'documents' => 30, 'invalidIds' => range(1, 30), 'reconciledAt' => '<script>health</script>');
class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_message() { return $this->message; }
}
class DiagnosticDie extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
class DiagnosticRedirect extends RuntimeException {}
function add_action($hook, $callback) { $GLOBALS['actions'][$hook] = $callback; }
function add_submenu_page(...$args) { $GLOBALS['menus'][] = $args; }
function current_user_can($cap) { return $GLOBALS['admin'] && 'manage_options' === $cap; }
function __($text, $domain) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html__($text, $domain) { return esc_html($text); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE); }
function admin_url($path) { return 'https://example.invalid/wp-admin/' . $path; }
function gpante_support_archive_value($value) { return null === $value ? '—' : esc_html($value); }
function gpante_support_search_index_health() { return $GLOBALS['health']; }
function gpante_support_admin_request($payload) { $GLOBALS['calls'][] = $payload; return $GLOBALS['result']; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function nocache_headers() { ++$GLOBALS['no_cache']; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="' . esc_attr($action) . '">'; }
function submit_button($text, ...$unused) { echo '<button>' . esc_html($text) . '</button>'; }
function wp_unslash($value) { return stripslashes($value); }
function check_admin_referer($action) {
    check('gpante_support_synthetic' === $action, 'fixed nonce action');
    if (!$GLOBALS['nonce']) throw new DiagnosticDie(403, 'bad nonce');
}
function wp_die($message, $title, $options) { throw new DiagnosticDie($options['response'], $message); }
function wp_safe_redirect($url) { throw new DiagnosticRedirect($url); }
function check($condition, $message) {
    ++$GLOBALS['checks'];
    if (!$condition) throw new RuntimeException($message);
}
function render_page() { ob_start(); gpante_support_diagnostics_page(); return ob_get_clean(); }
function denied($status) {
    try { gpante_support_mark_synthetic(); throw new RuntimeException('mutation unexpectedly returned'); }
    catch (DiagnosticDie $error) { check($status === $error->status, 'expected mutation status ' . $status); }
}
require __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/diagnostics.php';
check(isset($actions['admin_menu'], $actions['admin_post_gpante_support_synthetic']), 'authenticated hooks registered');
check(count($actions) === 2, 'no anonymous mutation hook');
gpante_support_diagnostics_menu();
check($menus[0][0] === 'gpante-support-conversations' && $menus[0][3] === 'manage_options'
    && $menus[0][4] === 'gpante-support-diagnostics', 'capability protected submenu');
$admin = false;
check(render_page() === '' && !$calls && $no_cache === 0, 'subscriber cannot read local or service diagnostics');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('request_id' => str_repeat('a', 32));
denied(403);
check(!$calls, 'denied mutation does not contact backend');
$admin = true;
$result = array('summary' => array('total' => 21, 'synthetic' => 1, 'unclassified' => 20, 'errors' => 2,
    'helpful' => 3, 'unhelpful' => 4, 'secret' => 'private-summary'),
    'budget' => array('enabled' => true, 'monthlyBudgetNano' => 2000000000, 'usedNano' => 1000,
    'pricingValidUntil' => '<img src=x onerror=alert(1)>', 'activeLeases' => 0, 'shared_key' => 'private-key'), 'turns' => array());
for ($i = 1; $i <= 21; ++$i) {
    $result['turns'][] = array('requestId' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT),
        'createdAt' => '<script>turn</script>', 'status' => 'success', 'errorCode' => null,
        'trafficKind' => $i === 1 ? 'synthetic' : 'unclassified', 'feedback' => 'helpful',
        'messages' => array(array('content' => 'private-transcript')), 'sessionId' => 'private-session',
        'events' => array(array('event' => 'source', 'tool' => 'Knowledge_Search', 'source' => 'site',
        'status' => 'ok', 'query' => '<script>query</script>', 'coverage' => 'incomplete',
        'body' => 'private-body', 'url' => 'private-url', 'filters' => array('format' => 'dxf', 'secret' => 'private-filter'),
        'results' => array(array('id' => 123, 'rank' => 1, 'kind' => 'page', 'title' => 'private-title'))),
        array('event' => 'rejection', 'code' => 'source_required', 'body' => 'private-rejection')));
}
$_GET = array('operation' => 'markSynthetic', 'request_id' => str_repeat('b', 32));
$html = render_page();
check($calls === array(array('operation' => 'getDiagnostics')), 'GET ignores mutation filters and only calls fixed read');
check($no_cache === 1, 'administrator diagnostics are not cacheable');
check(substr_count($html, 'class="postbox"') === 20 && substr_count($html, 'name="action" value="gpante_support_synthetic"') === 19, 'twenty turns and only unclassified labels');
check(!str_contains($html, '<script>') && !str_contains($html, '<img ')
    && str_contains($html, '&lt;script&gt;') && str_contains($html, '&lt;img '), 'all technical/JSON values escaped');
foreach (array('private-summary', 'private-key', 'private-transcript', 'private-session', 'private-body', 'private-url', 'private-filter', 'private-title', 'private-rejection') as $secret) {
    check(!str_contains($html, $secret), 'unknown field excluded: ' . $secret);
}
check(str_contains($html, '&quot;format&quot;:&quot;dxf&quot;') && str_contains($html, '&quot;rank&quot;:1')
    && str_contains($html, 'source_required'), 'allowed nested decision metadata retained');
check(str_contains($html, '[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20]')
    && !str_contains($html, '19,20,21]'), 'invalid document IDs capped at twenty');
$before = count($calls);
$_SERVER['REQUEST_METHOD'] = 'GET';
denied(405);
check(count($calls) === $before, 'GET never mutates even with administrator session');
$_SERVER['REQUEST_METHOD'] = 'POST';
$nonce = false;
denied(403);
check(count($calls) === $before, 'invalid nonce never reaches service');
$nonce = true;
foreach (array('', str_repeat('A', 32), str_repeat('a', 31), str_repeat('a', 32) . "\n", array('a')) as $invalid) {
    $_POST = array('request_id' => $invalid);
    denied(400);
}
check(count($calls) === $before, 'malformed identifiers never reach service');
$_POST = array('request_id' => str_repeat('a', 32), 'operation' => 'setPolicy', 'userDays' => 3);
foreach (array(array('ok' => false), array('ok' => 'true'), array('ok' => 1), new WP_Error('unavailable', '<script>failure</script>')) as $invalid) {
    $result = $invalid;
    denied(502);
    check(end($calls) === array('operation' => 'markSynthetic', 'requestId' => str_repeat('a', 32)), 'mutation payload fixed');
}
$result = array('ok' => true);
try { gpante_support_mark_synthetic(); throw new RuntimeException('missing success redirect'); }
catch (DiagnosticRedirect $redirect) { check($redirect->getMessage() === admin_url('admin.php?page=gpante-support-diagnostics'), 'success redirects only to fixed diagnostics page'); }
$result = new WP_Error('unavailable', '<script>service failure</script>');
$html = render_page();
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'service errors escaped');
$result = array('summary' => array(), 'budget' => array(), 'turns' => 'invalid');
check(!str_contains(render_page(), 'class="postbox"'), 'malformed read payload does not render turns');
echo "Administrator diagnostics: $checks capability, nonce, POST, escaping, allowlist, bounds and read-only checks passed.\n";
