<?php
// Isolated browser fixture only; supplements the shared offline backend.
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
    $page = max(1, (int)($input['page'] ?? 1));
    if ($operation === 'listSessions') {
        $body = array('sessions' => array(array('sessionId' => $sid,
            'firstAt' => '2026-10-28T00:00:00Z', 'lastAt' => '2026-10-28T00:01:00Z',
            'turns' => 4, 'messages' => 8, 'errors' => 0)),
            'hasMore' => $page < 2, 'page' => $page);
    } elseif ($operation === 'getSession') {
        $turns = array();
        foreach (array(1, null, 500000, 0) as $index => $cost) {
            $turns[] = array('createdAt' => '2026-10-28T00:00:00Z', 'status' => 'success',
                'durationMs' => $index === 1 ? null : 1250, 'costNano' => $cost,
                'usageComplete' => $index !== 2, 'calls' => 1, 'promptTokens' => 120, 'completionTokens' => 40,
                'messages' => array(array('role' => 'user', 'content' => 'Synthetic menu question ' . ($index + 1)),
                    array('role' => 'assistant', 'content' => '<script>window.gpanteMenuXss=1</script>')));
        }
        $body = array('turns' => $turns, 'hasMore' => $page < 2, 'page' => $page);
    } elseif ($operation === 'markSynthetic') {
        $valid = ($input['requestId'] ?? '') === $id;
        if ($valid) update_option('gptest_menu_synthetic', true, false);
        $body = array('ok' => $valid);
    } else {
        if (!empty($input['from']) && !empty($input['to']) && $input['from'] > $input['to']) {
            return array('headers' => array(), 'response' => array('code' => 400), 'body' => '{"error":"invalid_input"}');
        }
        $turns = array();
        for ($index = 1; $index <= 65; $index++) {
            $source = $index <= 20 ? 'site' : 'products';
            $query = $index <= 20 ? 'Initial source lookup' : 'Beyond page twenty';
            $turns[] = array('requestId' => $index === 1 ? $id : sprintf('%032x', $index),
                'sessionId' => $index === 1 ? $sid : sprintf('%064x', $index),
                'createdAt' => '2026-10-28T00:00:00Z', 'status' => 'success',
                'trafficKind' => $index === 1 && get_option('gptest_menu_synthetic', false) ? 'synthetic' : 'unclassified',
                'feedback' => $index % 2 ? 'helpful' : null,
                'extra' => 'FORBIDDEN-TURN-PAYLOAD',
                'events' => array(array('event' => 'source', 'tool' => 'Product_Search', 'source' => $source,
                    'status' => 'ok', 'query' => $query, 'coverage' => 'complete',
                    'filters' => array('format' => 'DXF', 'material' => 'wood', 'thickness' => '3',
                        'order' => 'relevance', 'category' => '42', 'secret' => 'FORBIDDEN-FILTER-PAYLOAD'),
                    'results' => array(array('id' => 'product-' . $index, 'rank' => 1, 'kind' => 'product',
                        'body' => 'FORBIDDEN-RESULT-PAYLOAD', 'url' => 'https://forbidden.invalid/private')),
                    'provider' => 'FORBIDDEN-SOURCE-PAYLOAD', 'content' => '<script>window.gpanteDiagnosticXss=1</script>'),
                    array('event' => 'rejection', 'code' => 'unsupported_reference', 'body' => 'FORBIDDEN-REJECTION-PAYLOAD')));
        }
        $filtered = array_values(array_filter($turns, static function ($turn) use ($input) {
            foreach (array('status' => 'status', 'traffic' => 'trafficKind') as $key => $field) {
                if (!empty($input[$key]) && $turn[$field] !== $input[$key]) return false;
            }
            $date = substr($turn['createdAt'], 0, 10);
            if ((!empty($input['from']) && $date < $input['from']) || (!empty($input['to']) && $date > $input['to'])) return false;
            if (!empty($input['source']) && $turn['events'][0]['source'] !== $input['source']) return false;
            $search = $input['search'] ?? '';
            $metadata = $turn['requestId'] . ' ' . ($turn['errorCode'] ?? '');
            foreach ($turn['events'] as $event) {
                if (($event['event'] ?? '') !== 'source') continue;
                foreach (array('query', 'tool', 'source', 'status') as $key) $metadata .= ' ' . ($event[$key] ?? '');
            }
            return $search === '' || stripos($metadata, $search) !== false;
        }));
        $body = array('summary' => array('total' => 65, 'synthetic' => (int)get_option('gptest_menu_synthetic', false),
            'unclassified' => 65 - (int)get_option('gptest_menu_synthetic', false), 'errors' => 0, 'helpful' => 33, 'unhelpful' => 0),
            'budget' => array('enabled' => true, 'monthlyBudgetNano' => 2000000000, 'usedNano' => 1234567,
                'pricingValidUntil' => '2099-01-01T00:00:00Z', 'activeLeases' => 0),
            'turns' => array_slice($filtered, ($page - 1) * 20, 20),
            'hasMore' => count($filtered) > $page * 20, 'page' => $page, 'windowDays' => 7);
        if (get_option('gptest_menu_diagnostics_mode') === 'missing') {
            $body['summary'] = array();
            $body['budget'] = array();
        }
        if (get_option('gptest_menu_diagnostics_mode') === 'offline') return new WP_Error('fixture_offline', 'Synthetic local failure');
    }
    return array('headers' => array(), 'response' => array('code' => 200), 'body' => wp_json_encode($body));
}, 11, 3);
