<?php
// Deferred code integration for gpante.com. Not a WordPress plugin or auto-installer.
if (!defined('ABSPATH')) {
    exit;
}

define('GPANTE_SUPPORT_VERSION', '1.1.0');
define('GPANTE_SUPPORT_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1');
define('GPANTE_SUPPORT_COOKIE', '__Host-gpante_support');
require_once __DIR__ . '/catalog.php';

// This option is provisioned privately. It is never registered for REST or printed.
function gpante_support_runtime() {
    $config = get_option('gpante_support_runtime', array());
    if (!is_array($config) || ($config['enabled'] ?? false) !== true
        || ($config['backend_url'] ?? '') !== GPANTE_SUPPORT_BACKEND
        || !is_string($config['shared_key'] ?? null)
        || !preg_match('/^[\x21-\x7e]{32,256}$/D', $config['shared_key'])) {
        return false;
    }
    return $config;
}

function gpante_support_response($data, $status = 200, $headers = array()) {
    return new WP_REST_Response($data, $status, array_merge(array(
        'Cache-Control' => 'no-store, private', 'Vary' => 'Origin, Cookie', 'X-Content-Type-Options' => 'nosniff'
    ), $headers));
}

function gpante_support_failure($status, $code, $headers = array()) {
    $messages = array(
        400 => 'درخواست معتبر نیست. پرسشتان را با حداکثر ۴۰۰۰ نویسه بنویسید.',
        403 => 'برای گفت‌وگو، صفحهٔ سایت پانته را دوباره باز کنید.',
        413 => 'حجم پیام بیش از حد مجاز است. لطفاً متن کوتاه‌تری بنویسید.',
        415 => 'قالب درخواست معتبر نیست. لطفاً صفحه را تازه کنید.',
        429 => 'تعداد درخواست‌ها به سقف مجاز رسیده است. لطفاً بعداً دوباره تلاش کنید.'
    );
    return gpante_support_response(array('error' => array('code' => $code),
        'output' => $messages[$status] ?? 'دستیار در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.'
    ), $status, $headers);
}

function gpante_support_session_id() {
    $now = time();
    $cookie = isset($_COOKIE[GPANTE_SUPPORT_COOKIE]) && is_string($_COOKIE[GPANTE_SUPPORT_COOKIE])
        ? wp_unslash($_COOKIE[GPANTE_SUPPORT_COOKIE]) : '';
    $parts = explode('.', $cookie);
    $valid = count($parts) === 4 && $parts[0] === 'v1'
        && preg_match('/^[0-9]{10}$/D', $parts[1])
        && (int) $parts[1] <= $now && (int) $parts[1] > $now - 30 * DAY_IN_SECONDS
        && preg_match('/^[A-Za-z0-9_-]{43}$/D', $parts[2])
        && preg_match('/^[a-f0-9]{64}$/D', $parts[3]);
    if ($valid) {
        $valid = hash_equals(hash_hmac('sha256', 'gpante-support-cookie|' . implode('.', array_slice($parts, 0, 3)), wp_salt('auth')), $parts[3]);
    }
    if (!$valid) {
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Exception $error) {
            return false;
        }
        $value = 'v1.' . $now . '.' . $token;
        $cookie = $value . '.' . hash_hmac('sha256', 'gpante-support-cookie|' . $value, wp_salt('auth'));
        if (headers_sent() || !setcookie(GPANTE_SUPPORT_COOKIE, $cookie, array(
            'expires' => $now + 30 * DAY_IN_SECONDS, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict'
        ))) {
            return false;
        }
    } else {
        $token = $parts[2];
    }
    return hash_hmac('sha256', 'gpante-support-session|' . $token, wp_salt('auth'));
}

function gpante_support_chat($request) {
    // Public endpoint: browser identity comes only from our signed, host-only cookie.
    if (!is_ssl() || !in_array($request->get_header('origin'), array('https://gpante.com', 'https://www.gpante.com'), true)
        || $request->get_header('sec-fetch-site') !== 'same-origin') {
        return gpante_support_failure(403, 'origin_rejected');
    }
    $config = gpante_support_runtime();
    if (!$config) {
        return gpante_support_failure(503, 'unavailable');
    }
    if (!preg_match('/^application\/json(?:\s*;\s*charset=utf-8)?$/iD', $request->get_header('content-type'))) {
        return gpante_support_failure(415, 'json_required');
    }
    $body = $request->get_body();
    if (strlen($body) > 32768) {
        return gpante_support_failure(413, 'body_too_large');
    }
    $input = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)
        || !in_array($input['action'] ?? null, array('sendMessage', 'loadPreviousSession', 'resetSession'), true)) {
        return gpante_support_failure(400, 'invalid_input');
    }
    $action = $input['action'];
    $text = is_string($input['chatInput'] ?? null) ? trim($input['chatInput']) : '';
    if ($action === 'sendMessage' && ($text === '' || preg_match_all('/./us', $text) > 4000)) {
        return gpante_support_failure(400, 'invalid_input');
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return gpante_support_failure(503, 'unavailable');
    }
    $session = gpante_support_session_id();
    if (!$session) {
        return gpante_support_failure(503, 'unavailable');
    }
    $payload = array('action' => $action, 'sessionId' => $session,
        'clientId' => hash_hmac('sha256', 'gpante-support-client|' . inet_pton($ip), wp_salt('auth')));
    if ($action === 'sendMessage') {
        $payload['chatInput'] = $text;
    }
    $result = wp_remote_post(GPANTE_SUPPORT_BACKEND, array(
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Gpante-Chat-Key' => $config['shared_key']),
        'body' => wp_json_encode($payload), 'timeout' => 155, 'redirection' => 0,
        'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => 131072
    ));
    if (is_wp_error($result)) {
        return gpante_support_failure(502, 'backend_unavailable');
    }
    $status = (int) wp_remote_retrieve_response_code($result);
    $data = json_decode(wp_remote_retrieve_body($result), true);
    if ($status < 200 || $status >= 300 || !is_array($data) || isset($data['error'])) {
        $headers = array();
        $retry = wp_remote_retrieve_header($result, 'retry-after');
        if ($status === 429 && is_string($retry) && preg_match('/^[0-9]{1,6}$/D', $retry)) {
            $headers['Retry-After'] = (string) max(1, min(86400, (int) $retry));
        }
        return gpante_support_failure($status === 429 ? 429 : 502, $status === 429 ? 'request_limit' : 'backend_unavailable', $headers);
    }
    if ($action === 'resetSession' && ($data['ok'] ?? false) === true) {
        return gpante_support_response(array('ok' => true));
    }
    if ($action === 'sendMessage' && is_string($data['output'] ?? null) && trim($data['output']) !== '' && strlen($data['output']) <= 32000) {
        return gpante_support_response(array('output' => $data['output']));
    }
    if ($action === 'loadPreviousSession' && isset($data['messages']) && is_array($data['messages']) && count($data['messages']) <= 100) {
        $messages = array();
        foreach ($data['messages'] as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, array('user', 'assistant'), true)
                || !is_string($message['content'] ?? null) || strlen($message['content']) > 32000) {
                return gpante_support_failure(502, 'invalid_backend_response');
            }
            $messages[] = array('role' => $message['role'], 'content' => $message['content']);
        }
        return gpante_support_response(array('messages' => $messages));
    }
    return gpante_support_failure(502, 'invalid_backend_response');
}

add_action('rest_api_init', function () {
    register_rest_route('gpante-support/v1', '/chat', array(
        'methods' => 'POST', 'callback' => 'gpante_support_chat', 'permission_callback' => '__return_true'
    ));
    gpante_support_register_catalog();
});

add_action('wp_enqueue_scripts', function () {
    if (!gpante_support_runtime() || !defined('GPANTE_SUPPORT_ASSET_BASE')) {
        return;
    }
    wp_enqueue_style('gpante-support-chat', rtrim(GPANTE_SUPPORT_ASSET_BASE, '/') . '/chat.css', array(), GPANTE_SUPPORT_VERSION);
    wp_enqueue_script('gpante-support-chat', rtrim(GPANTE_SUPPORT_ASSET_BASE, '/') . '/chat.js', array(), GPANTE_SUPPORT_VERSION, true);
});

add_action('wp_footer', function () {
    if (!gpante_support_runtime()) {
        return;
    }
    ?>
    <div id="gpante-support" dir="rtl" lang="fa" data-endpoint="<?php echo esc_url(wp_make_link_relative(rest_url('gpante-support/v1/chat'))); ?>">
        <section id="gpante-support-panel" class="gpante-chat-panel" role="dialog" aria-labelledby="gpante-support-title" tabindex="-1" hidden>
            <header class="gpante-chat-header">
                <div><strong id="gpante-support-title">دستیار پانته</strong><span>راهنمای طرح و فایل، خرید و دانلود</span></div>
                <button type="button" data-close aria-label="بستن گفت‌وگو">×</button>
            </header>
            <div class="gpante-chat-intro">دستیار هوش مصنوعی برای اطلاعات عمومی محصولات و راهنماهاست. به حساب یا سفارش شما دسترسی ندارد. تاریخچهٔ این مرورگر تا ۳۰ روز نگهداری می‌شود.</div>
            <div class="gpante-chat-toolbar"><button type="button" data-new>پاک‌کردن و گفت‌وگوی تازه</button></div>
            <div class="gpante-chat-confirm" data-confirm hidden>
                <p>تاریخچهٔ این گفت‌وگو از سرور پاک شود؟ این کار برگشت‌پذیر نیست.</p>
                <button type="button" data-clear>بله، پاک شود</button><button type="button" data-cancel>انصراف</button>
            </div>
            <div class="gpante-chat-messages" data-messages role="log" aria-label="پیام‌های گفت‌وگو" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
            <div class="gpante-chat-status" data-status role="status" aria-live="polite" aria-atomic="true"></div>
            <button class="gpante-chat-retry" type="button" data-retry hidden>تلاش دوباره</button>
            <form class="gpante-chat-form" data-form>
                <label for="gpante-support-input">پرسش شما</label>
                <textarea id="gpante-support-input" data-input rows="2" maxlength="4000" placeholder="مثلاً باکس MDF با ضخامت ۲٫۷ و فایل DXF می‌خواهم…" required></textarea>
                <div class="gpante-chat-compose"><small data-count aria-hidden="true">۰ / ۴۰۰۰</small><button type="submit" data-send disabled>ارسال پیام</button></div>
            </form>
        </section>
        <button class="gpante-chat-launcher" type="button" data-launcher aria-label="باز کردن دستیار هوش مصنوعی پانته" aria-controls="gpante-support-panel" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 2v-10A8.5 8.5 0 0 1 9.5 3H12a8 8 0 0 1 8 8.5Z"/><path d="M6 9h9M6 13h6"/></svg>
            <span>از پانته بپرسید</span>
        </button>
    </div>
    <?php
});

