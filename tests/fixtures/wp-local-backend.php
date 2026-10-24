<?php
// Local WordPress fixture ONLY. Not included in the plugin ZIP.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || GPANTE_SUPPORT_LOCAL_TEST !== true) return;
add_filter('pre_http_request', function ($pre, $args, $url) {
 if ($url === 'https://n8n.gpantex.com/webhook/gpante-ai-support-admin-v1') {
  $input=json_decode($args['body'],true);
  $policy=get_option('gptest_policy',array('version'=>2,'userDays'=>30,'archiveDays'=>30,'allowClear'=>true,'revision'=>1));
  if($input['operation']==='setPolicy'){
   if($input['revision']!==$policy['revision'])return array('headers'=>array(),'response'=>array('code'=>409),'body'=>'{"error":"policy_conflict"}');
   global $wpdb;
   $now=(int)get_option('gptest_now',time());
   $keys=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('gptest_turns_').'%'));
   foreach($keys as $key) {
    $state=get_option($key);
    foreach($state['turns'] as &$turn) {
     $limit=$turn['created']+$input['userDays']*86400;
     $turn['expires']=!$turn['hidden'] && $turn['expires']>$now ? $limit : min($turn['expires'],$limit);
    }
    unset($turn);
    update_option($key,$state,false);
   }
   $policy['userDays']=$input['userDays'];$policy['allowClear']=$input['allowClear'];$policy['revision']++;update_option('gptest_policy',$policy,false);
  }
  if($input['operation']==='listSessions')$body=array('sessions'=>array(),'hasMore'=>false,'page'=>1);
  elseif($input['operation']==='getSession')$body=array('turns'=>array(array('createdAt'=>'2026-10-20T00:00:00Z','status'=>'success','messages'=>array(array('role'=>'user','content'=>'<script>window.gpanteStoredXss=1</script>')))),'hasMore'=>false,'page'=>1);
  else $body=array('policy'=>$policy);
  return array('headers'=>array(),'response'=>array('code'=>200),'body'=>wp_json_encode($body));
 }
 if ($url !== 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1') return $pre;
 $input = json_decode($args['body'], true);
 if ($input['action'] === 'loadPreviousSession') usleep(max(0,min(3000,(int)get_option('gptest_history_delay_ms',0)))*1000);
 $mode = get_option('gptest_backend_mode', 'success');
 if ($mode === 'network') return new WP_Error('fixture_offline', 'Synthetic local failure');
 if ($mode === 'error') return array('headers' => array(), 'response' => array('code' => 503), 'body' => '{"error":true}');
 if ($mode === 'malformed') return array('headers' => array(), 'response' => array('code' => 200), 'body' => '<html>invalid</html>');
 $key = 'gptest_turns_' . $input['sessionId'];
 $state = get_option($key, array('turns'=>array(),'nextId'=>1,'historyResetAt'=>null));
 $policy = get_option('gptest_policy',array('version'=>2,'userDays'=>30,'archiveDays'=>30,'allowClear'=>true,'revision'=>1));
 $now = (int)get_option('gptest_now',time());
 foreach($state['turns'] as &$turn) $turn['expires']=min($turn['expires'],$turn['created']+$policy['userDays']*86400);
 unset($turn);
 $visible = static fn($turn)=>!$turn['hidden'] && $turn['expires']>$now;
 $error = static fn($code)=>array('error'=>array('code'=>$code));
 if ($input['action'] === 'sendMessage') {
  $prior = null;
  foreach($state['turns'] as $turn) if(isset($input['sendId']) && $turn['sendId']===$input['sendId']) $prior=$turn;
  if($prior && !$visible($prior)) $body=$error('prior_request_expired');
  elseif($prior && $prior['input']!==$input['chatInput']) $body=$error('invalid_input');
  else {
   if(!$prior) {
    $prior=array('id'=>$state['nextId']++,'requestId'=>bin2hex(random_bytes(16)),'sendId'=>$input['sendId']??null,
     'input'=>$input['chatInput'],'output'=>'پاسخ آزمایشی: '.$input['chatInput']."\n[مشاهدهٔ محصول](https://gpante.com/product/local-test/)",
     'created'=>$now,'expires'=>$now+$policy['userDays']*86400,'hidden'=>false,'feedback'=>null);
    $state['turns'][]=$prior;
   }
   $body=array('output'=>$prior['output'],'requestId'=>$prior['requestId'],'feedback'=>$prior['feedback'],'expiresAt'=>gmdate('c',$prior['expires']));
  }
 } elseif ($input['action'] === 'resetSession') {
  if(!$policy['allowClear']) $body=$error('clear_disabled');
  else {
   foreach($state['turns'] as &$turn) $turn['hidden']=true;
   unset($turn);
   $state['historyResetAt']=gmdate('c',$now); $body=array('ok'=>true);
  }
 } elseif ($input['action'] === 'feedback') {
  $body=$error('feedback_unavailable');
  foreach($state['turns'] as &$turn) if($turn['requestId']===$input['feedbackRequestId'] && $visible($turn)) {
   $turn['feedback']=$input['feedbackValue']; $body=array('ok'=>true);
  }
  unset($turn);
 } else {
  $eligible=array_values(array_filter($state['turns'],static fn($turn)=>$visible($turn) && (!isset($input['historyCursor']) || $turn['id']<(int)$input['historyCursor'])));
  $selected=array_slice($eligible,-6); $messages=array();
  foreach($selected as $turn) foreach(array('user'=>'input','assistant'=>'output') as $role=>$field) $messages[]=array(
   'role'=>$role,'content'=>$turn[$field],'requestId'=>$turn['requestId'],'expiresAt'=>gmdate('c',$turn['expires']),'feedback'=>$turn['feedback']);
  $more=count($eligible)>6;
  $body=array('messages'=>$messages,'hasMore'=>$more,'nextCursor'=>$more?(string)$selected[0]['id']:null,'historyResetAt'=>$state['historyResetAt']);
 }
 update_option($key,$state,false);
 $body['policy']=$policy;
 return array('headers' => array(), 'response' => array('code' => 200), 'body' => wp_json_encode($body));
}, 10, 3);
// Native fixtures supply their own public HTTP responses; unexpected network access stays offline.
add_filter('pre_http_request', static function($pre) {
 return $pre === false ? new WP_Error('fixture_offline','External HTTP is disabled on the isolated test site') : $pre;
}, 99);
