<?php
// Local WordPress fixture ONLY. Not included in the plugin ZIP.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || GPANTE_SUPPORT_LOCAL_TEST !== true) return;
add_filter('pre_http_request', function ($pre, $args, $url) {
 if ($url === 'https://n8n.gpantex.com/webhook/gpante-ai-support-admin-v1') {
  $input=json_decode($args['body'],true);
  $policy=get_option('gptest_policy',array('version'=>2,'userDays'=>30,'archiveDays'=>30,'allowClear'=>true,'revision'=>1));
  if($input['operation']==='setPolicy'){
   if($input['revision']!==$policy['revision'])return array('headers'=>array(),'response'=>array('code'=>409),'body'=>'{"error":"policy_conflict"}');
   $policy['userDays']=$input['userDays'];$policy['allowClear']=$input['allowClear'];$policy['revision']++;update_option('gptest_policy',$policy,false);
  }
  if($input['operation']==='listSessions')$body=array('sessions'=>array(),'hasMore'=>false,'page'=>1);
  elseif($input['operation']==='getSession')$body=array('turns'=>array(array('createdAt'=>'2026-10-20T00:00:00Z','status'=>'success','messages'=>array(array('role'=>'user','content'=>'<script>window.gpanteStoredXss=1</script>')))),'hasMore'=>false,'page'=>1);
  else $body=array('policy'=>$policy);
  return array('headers'=>array(),'response'=>array('code'=>200),'body'=>wp_json_encode($body));
 }
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
 $body['policy']=get_option('gptest_policy',array('version'=>2,'userDays'=>30,'archiveDays'=>30,'allowClear'=>true,'revision'=>1));
 $body['expiresAt']=gmdate('c',time()+86400);
 if(isset($body['messages']))foreach($body['messages'] as &$message)$message['expiresAt']=gmdate('c',time()+86400);
 return array('headers' => array(), 'response' => array('code' => 200), 'body' => wp_json_encode($body));
}, 10, 3);
