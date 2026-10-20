<?php
// Local WordPress fixture ONLY. Not included in the plugin ZIP.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || GPANTE_SUPPORT_LOCAL_TEST !== true) return;
add_filter('pre_http_request', function ($pre, $args, $url) {
 if ($url !== 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1') return $pre;
 $mode = get_option('gptest_backend_mode', 'success');
 if ($mode === 'network') return new WP_Error('fixture_offline', 'Synthetic local failure');
 if ($mode === 'error') return array('headers' => array(), 'response' => array('code' => 503), 'body' => '{"error":true}');
 if ($mode === 'malformed') return array('headers' => array(), 'response' => array('code' => 200), 'body' => '<html>invalid</html>');
 $input = json_decode($args['body'], true);
 $key = 'gptest_history_' . $input['sessionId'];
 $history = get_option($key, array());
 if ($input['action'] === 'sendMessage') {
  $output = 'پاسخ آزمایشی: ' . $input['chatInput'] . "\n[مشاهدهٔ محصول](https://gpante.com/product/local-test/)";
  $history[] = array('role' => 'user', 'content' => $input['chatInput']);
  $history[] = array('role' => 'assistant', 'content' => $output);
  update_option($key, $history, false);
  $body = array('output' => $output);
 } elseif ($input['action'] === 'resetSession') {
  delete_option($key);
  $body = array('ok' => true);
 } else $body = array('messages' => $history);
 return array('headers' => array(), 'response' => array('code' => 200), 'body' => wp_json_encode($body));
}, 10, 3);
