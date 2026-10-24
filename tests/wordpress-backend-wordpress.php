<?php
// Exercise the local HTTP backend fixture, never a model or production workflow.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== (defined('GPANTE_SUPPORT_TEST_URL') ? GPANTE_SUPPORT_TEST_URL : 'https://127.0.0.1:9443')) throw new RuntimeException('Isolated local test site required');
function backend_check($value,$message) { if(!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function backend_read($input) {
    $response=wp_remote_post('https://n8n.gpantex.com/webhook/gpante-ai-support-v1',array('body'=>wp_json_encode($input)));
    if(is_wp_error($response)) throw new RuntimeException('Local backend fixture not installed');
    return json_decode(wp_remote_retrieve_body($response),true);
}
$sid=hash('sha256','native-local-backend-fixture'); $other=hash('sha256','native-other-backend-fixture');
$policy_sid=hash('sha256','native-policy-backend-fixture');
$saved=array(); foreach(array('gptest_now','gptest_policy','gptest_backend_mode') as $key) $saved[$key]=get_option($key,null);
try {
    delete_option('gptest_turns_'.$sid); delete_option('gptest_turns_'.$other);
    update_option('gptest_backend_mode','success',false);
    update_option('gptest_now',1700000000,false);
    update_option('gptest_policy',array('version'=>2,'userDays'=>7,'archiveDays'=>30,'allowClear'=>true,'revision'=>1),false);
    $send=array('sessionId'=>$sid,'action'=>'sendMessage','chatInput'=>'پیام اول','sendId'=>str_repeat('a',32));
    $first=backend_read($send); $again=backend_read($send);
    backend_check($first['requestId']===$again['requestId'] && $first['expiresAt']===$again['expiresAt'],'same send ID replays the saved answer and fixed expiry');
    for($i=1;$i<8;$i++) backend_read(array_merge($send,array('sendId'=>md5('local-'.$i),'chatInput'=>'پیام '.$i)));
    update_option('gptest_now',1700003600,false);
    $latest=backend_read(array('sessionId'=>$sid,'action'=>'loadPreviousSession'));
    backend_check(count($latest['messages'])===12 && $latest['hasMore'] && $latest['nextCursor']==='3','newest history contains six complete turns and a stable older cursor');
    $older=backend_read(array('sessionId'=>$sid,'action'=>'loadPreviousSession','historyCursor'=>$latest['nextCursor']));
    backend_check(count($older['messages'])===4 && !$older['hasMore'] && $older['messages'][0]['requestId']===$first['requestId'],'older page completes history without overlap');
    backend_check($older['messages'][0]['expiresAt']===$first['expiresAt'],'history reads never extend the original turn expiry');
    $feedback=array('action'=>'feedback','feedbackRequestId'=>$first['requestId'],'feedbackValue'=>'helpful');
    backend_check((backend_read(array_merge($feedback,array('sessionId'=>$other)))['error']['code']??null)==='feedback_unavailable','another session cannot rate the original turn');
    backend_check(backend_read(array_merge($feedback,array('sessionId'=>$sid)))['ok']===true && backend_read($send)['feedback']==='helpful','owned feedback is persisted and returned on replay');
    backend_read(array('sessionId'=>$sid,'action'=>'resetSession'));
    $cleared=backend_read(array('sessionId'=>$sid,'action'=>'loadPreviousSession'));
    backend_check($cleared['messages']===array() && $cleared['historyResetAt']===gmdate('c',1700003600),'clear hides history and returns a reset marker');
    backend_check((backend_read($send)['error']['code']??null)==='prior_request_expired','cleared send ID cannot become a new turn');
    backend_check(is_wp_error(wp_remote_get('https://example.invalid/fixture-must-stay-offline')),'unexpected external HTTP is blocked');

    $policy_send=array_merge($send,array('sessionId'=>$policy_sid,'sendId'=>str_repeat('c',32)));
    $before=backend_read($policy_send);
    $set_policy=static function($days) {
        $p=get_option('gptest_policy');
        return wp_remote_post('https://n8n.gpantex.com/webhook/gpante-ai-support-admin-v1',array('body'=>wp_json_encode(array(
            'operation'=>'setPolicy','userDays'=>$days,'allowClear'=>true,'revision'=>$p['revision']))));
    };
    $set_policy(30);
    $extended=backend_read($policy_send);
    backend_check(strtotime($extended['expiresAt'])===1700003600+30*86400,'increasing policy extends an existing still-live turn from its original timestamp');
    update_option('gptest_now',1700003600+8*86400,false);
    $set_policy(7);
    $set_policy(30);
    backend_check((backend_read($policy_send)['error']['code']??null)==='prior_request_expired','increasing policy never restores a turn expired by an intermediate decrease');
} finally {
    delete_option('gptest_turns_'.$sid); delete_option('gptest_turns_'.$other); delete_option('gptest_turns_'.$policy_sid);
    foreach($saved as $key=>$value) if($value===null) delete_option($key); else update_option($key,$value,false);
}
