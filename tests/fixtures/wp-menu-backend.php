<?php
// Isolated browser menu fixture only; supplements the shared offline backend.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || GPANTE_SUPPORT_LOCAL_TEST !== true
    || !defined('GPANTE_SUPPORT_TEST_URL') || home_url() !== GPANTE_SUPPORT_TEST_URL) return;
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($url !== 'https://n8n.gpantex.com/webhook/gpante-ai-support-admin-v1') return $pre;
    $input = json_decode($args['body'], true);
    $operation = $input['operation'] ?? '';
    if (!in_array($operation, array('listSessions', 'getSession', 'getDiagnostics', 'markSynthetic'), true)) return $pre;
    $calls = get_option('gptest_menu_calls', array());
    $calls[] = $input;
    update_option('gptest_menu_calls', array_slice($calls, -100), false);
    $id = str_repeat('a', 32);
    $sid = str_repeat('b', 64);
    if ($operation === 'listSessions') {
        $body = array('sessions' => array(array('sessionId' => $sid,
            'firstAt' => '2026-10-24T00:00:00Z', 'lastAt' => '2026-10-24T00:01:00Z',
            'turns' => 1, 'messages' => 2, 'errors' => 0)),
            'hasMore' => $input['page'] < 2, 'page' => $input['page']);
    } elseif ($operation === 'getSession') {
        $body = array('turns' => array(array('createdAt' => '2026-10-24T00:00:00Z', 'status' => 'success',
            'messages' => array(array('role' => 'user', 'content' => 'Synthetic menu question'),
                array('role' => 'assistant', 'content' => '<script>window.gpanteMenuXss=1</script>')))),
            'hasMore' => $input['page'] < 2, 'page' => $input['page']);
    } elseif ($operation === 'markSynthetic') {
        $valid = ($input['requestId'] ?? '') === $id;
        if ($valid) update_option('gptest_menu_synthetic', true, false);
        $body = array('ok' => $valid);
    } else {
        $body = array('summary' => array('total' => 1, 'synthetic' => (int)get_option('gptest_menu_synthetic', false),
            'unclassified' => (int)!get_option('gptest_menu_synthetic', false), 'errors' => 0, 'helpful' => 0, 'unhelpful' => 0),
            'budget' => array('enabled' => true, 'monthlyBudgetNano' => 2000000000, 'usedNano' => 0,
                'pricingValidUntil' => '2099-01-01T00:00:00Z', 'activeLeases' => 0),
            'turns' => array(array('requestId' => $id, 'createdAt' => '2026-10-24T00:00:00Z', 'status' => 'success',
                'trafficKind' => get_option('gptest_menu_synthetic', false) ? 'synthetic' : 'unclassified',
                'events' => array())));
    }
    return array('headers' => array(), 'response' => array('code' => 200), 'body' => wp_json_encode($body));
}, 11, 3);
