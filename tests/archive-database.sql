-- Isolated test database only; all fixtures and role changes roll back. No model calls.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DO $$
DECLARE s TEXT:=repeat('a',64); c TEXT:=repeat('b',64); r TEXT; a JSONB; b JSONB; d INTEGER; i INTEGER;
  before_ledger JSONB; after_ledger JSONB; rev INTEGER;
BEGIN
  ASSERT current_database()='gpante_chat_archive_test','isolated database required';
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.request_counters;
  DELETE FROM gpante_chat.turns;
  SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]') INTO before_ledger FROM gpante_chat.model_calls m;
  ASSERT NOT (gpante_chat.admit(s,c,'resetSession')->>'allowed')::boolean,'old destructive contract blocked';
  a:=gpante_chat.admit_v3(s,c,'sendMessage','question',0);
  ASSERT NOT (a->>'allowed')::boolean,'old sending client blocked';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.turns),'rejected text not saved';
  a:=gpante_chat.admit_v3(s,c,'sendMessage','accepted question',2); r:=a->>'requestId';
  ASSERT (a->>'allowed')::boolean,'new admission';
  ASSERT (SELECT count(*)=1 FROM gpante_chat.histories WHERE turn_id=r),'question saved before model';
  ASSERT (SELECT status='pending' FROM gpante_chat.turns WHERE request_id=r),'pending truthful';
  ASSERT NOT (gpante_chat.admit_v3(s,c,'resetSession',NULL,2)->>'allowed')::boolean,'reset cannot race accepted message';
  ASSERT (gpante_chat.finish_v3(r,'answer','success',NULL)->>'saved')::boolean,'success recorded';
  ASSERT NOT (gpante_chat.finish_v3(r,'duplicate','success',NULL)->>'saved')::boolean,'same request cannot double log';
  ASSERT jsonb_array_length(gpante_chat.admit_v3(s,c,'loadPreviousSession',NULL,0)->'messages')=2,'complete turn available';
  ASSERT jsonb_array_length(gpante_chat.admit_v3(repeat('c',64),c,'loadPreviousSession',NULL,2)->'messages')=0,'visitor isolation';
  ASSERT (gpante_chat.admit_v3(s,c,'resetSession',NULL,2)->>'ok')::boolean,'clear enabled';
  ASSERT jsonb_array_length(gpante_chat.admit_v3(s,c,'loadPreviousSession',NULL,2)->'messages')=0,'cleared excluded from model context';
  ASSERT (SELECT count(*)=2 FROM gpante_chat.histories WHERE turn_id=r),'clear preserves archive';
  ASSERT jsonb_array_length(gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',s))->'turns')=1,'admin reads cleared';
  a:=gpante_chat.admit_v3(s,c,'sendMessage','failed accepted question',2); r:=a->>'requestId';
  ASSERT (gpante_chat.finish_v3(r,NULL,'error','model_tool_duplicate_id')->>'saved')::boolean,'error finished';
  ASSERT (SELECT count(*)=1 FROM gpante_chat.histories WHERE turn_id=r),'failure has question and no invented answer';
  ASSERT (SELECT status='error' AND error_code='model_tool_duplicate_id' FROM gpante_chat.turns WHERE request_id=r),'precise error metadata';
  b:=gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',s));
  ASSERT b->'turns'->1->>'errorCode'='model_tool_duplicate_id','fixed reason available in existing admin archive';
  a:=gpante_chat.admit_v3(s,c,'sendMessage','interrupted question',2); r:=a->>'requestId';
  UPDATE gpante_chat.requests SET expires_at=clock_timestamp()-INTERVAL '1 second' WHERE request_id=r;
  b:=gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',s));
  ASSERT (b->'turns'->2->>'status')='interrupted','interruption visible before scheduled cleanup';
  PERFORM gpante_chat.cleanup();
  ASSERT (SELECT status='interrupted' AND duration_ms IS NULL FROM gpante_chat.turns WHERE request_id=r),'interruption has no invented duration';
  DELETE FROM gpante_chat.request_counters;
  -- Each configurable day boundary is checked just before and after expiry.
  FOREACH d IN ARRAY ARRAY[3,7,15,30] LOOP
    SELECT revision INTO rev FROM gpante_chat.policy;
    a:=gpante_chat.admin_api(jsonb_build_object('operation','setPolicy','userDays',d,'allowClear',false,'revision',rev));
    ASSERT (a->'policy'->>'userDays')::integer=d,'all global retention choices';
    ASSERT NOT (gpante_chat.admit_v3(s,c,'resetSession',NULL,2)->>'allowed')::boolean,'disabled clear enforced server side';
    INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status) VALUES
      ('edge-before-'||d,repeat('d',64),clock_timestamp()-make_interval(days=>d)+INTERVAL '3 seconds',clock_timestamp()+INTERVAL '3 seconds','success'),
      ('edge-after-'||d,repeat('d',64),clock_timestamp()-make_interval(days=>d)-INTERVAL '3 seconds',clock_timestamp()-INTERVAL '3 seconds','success');
    INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at)
      SELECT session_id,request_id,'{"type":"human","data":{"content":"boundary"}}'::jsonb,created_at FROM gpante_chat.turns WHERE request_id IN ('edge-before-'||d,'edge-after-'||d);
    a:=gpante_chat.admit_v3(repeat('d',64),repeat('e',64),'loadPreviousSession',NULL,2);
    ASSERT jsonb_array_length(a->'messages')=1,'deadline excludes expired from user/model';
    b:=gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',repeat('d',64)));
    ASSERT jsonb_array_length(b->'turns')=CASE WHEN d=30 THEN 1 ELSE 2 END,'archive independent up to fixed thirty days';
    DELETE FROM gpante_chat.turns WHERE session_id=repeat('d',64);
  END LOOP;
  -- Increasing duration cannot resurrect expired or cleared turns.
  UPDATE gpante_chat.policy SET user_days=3;
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status) VALUES
    ('expired-short',repeat('f',64),clock_timestamp()-INTERVAL '4 days',clock_timestamp()-INTERVAL '1 day','success'),
    ('still-live',repeat('f',64),clock_timestamp()-INTERVAL '1 day',clock_timestamp()+INTERVAL '2 days','success');
  SELECT revision INTO rev FROM gpante_chat.policy;
  a:=gpante_chat.admin_api(jsonb_build_object('operation','setPolicy','userDays',30,'allowClear',true,'revision',rev));
  ASSERT (SELECT user_expires_at<clock_timestamp() FROM gpante_chat.turns WHERE request_id='expired-short'),'expired never resurrected';
  ASSERT (SELECT user_expires_at>clock_timestamp()+INTERVAL '28 days' FROM gpante_chat.turns WHERE request_id='still-live'),'live can extend';
  ASSERT (SELECT hidden_at IS NOT NULL FROM gpante_chat.turns WHERE request_id IN (SELECT turn_id FROM gpante_chat.histories WHERE message->'data'->>'content'='answer')),'cleared never resurrected';
  a:=gpante_chat.admin_api(jsonb_build_object('operation','setPolicy','userDays',7,'allowClear',true,'revision',rev));
  ASSERT a->>'error'='policy_conflict','stale settings cannot overwrite new policy';
  -- Admin reads do not depend on money or public enabled state.
  UPDATE gpante_chat.runtime SET enabled=false,monthly_budget_nano=0;
  ASSERT gpante_chat.admin_api('{"operation":"getPolicy"}') ? 'policy','admin independent of budget';
  -- Paging covers every message, not the model twelve-message window.
  FOR i IN 1..25 LOOP
    INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status) VALUES('page-'||lpad(i::text,2,'0'),repeat('1',64),clock_timestamp(),clock_timestamp()+INTERVAL '3 days','legacy');
    INSERT INTO gpante_chat.histories(session_id,turn_id,message) VALUES(repeat('1',64),'page-'||lpad(i::text,2,'0'),jsonb_build_object('type','human','data',jsonb_build_object('content','needle '||i)));
  END LOOP;
  a:=gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',repeat('1',64),'page',1));
  b:=gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',repeat('1',64),'page',2));
  ASSERT jsonb_array_length(a->'turns')=20 AND (a->>'hasMore')::boolean,'page one bound';
  ASSERT jsonb_array_length(b->'turns')=5 AND NOT (b->>'hasMore')::boolean,'page two complete';
  a:=gpante_chat.admin_api('{"operation":"listSessions","search":"needle","status":"legacy"}');
  ASSERT jsonb_array_length(a->'sessions')=1,'bounded literal text/status search';
  ASSERT gpante_chat.admin_api('{"operation":"listSessions","from":"2026-99-99"}')->>'error'='invalid_input','bad date';
  ASSERT gpante_chat.admin_api('{"operation":"listSessions","page":0}')->>'error'='invalid_input','bad page';
  ASSERT gpante_chat.admin_api('{"operation":"dropDatabase"}')->>'error'='invalid_operation','no arbitrary operations';
  ASSERT gpante_chat.admin_api('{"operation":"setPolicy","revision":1,"allowClear":true}')->>'error'='invalid_input','missing settings';
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status)
    VALUES('past-archive',s,clock_timestamp()-INTERVAL '31 days',clock_timestamp()-INTERVAL '1 day','legacy');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at) VALUES(s,'past-archive','{"type":"human","data":{"content":"expired archive"}}',clock_timestamp()-INTERVAL '31 days');
  a:=gpante_chat.admin_api('{"operation":"listSessions","search":"expired archive"}');
  ASSERT jsonb_array_length(a->'sessions')=0,'archive read cutoff before physical deletion';
  PERFORM gpante_chat.cleanup();
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.histories WHERE turn_id='past-archive'),'cleanup cascades transcript';
  SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]') INTO after_ledger FROM gpante_chat.model_calls m;
  ASSERT before_ledger=after_ledger,'retention does not alter monetary ledger';
END $$;
RESET ROLE;
CREATE ROLE gpante_chat_archive_test_admin NOLOGIN;
GRANT USAGE ON SCHEMA gpante_chat TO gpante_chat_archive_test_admin;
GRANT EXECUTE ON FUNCTION gpante_chat.admin_api(JSONB) TO gpante_chat_archive_test_admin;
SET LOCAL ROLE gpante_chat_archive_test_admin;
DO $$ BEGIN
 ASSERT NOT has_table_privilege(current_user,'gpante_chat.histories','SELECT'),'admin role has no direct tables';
 ASSERT NOT has_function_privilege(current_user,'gpante_chat.admit_v3(text,text,text,text,integer)','EXECUTE'),'admin cannot call model admission';
 ASSERT gpante_chat.admin_api('{"operation":"getPolicy"}') ? 'policy','limited admin can read via fixed API';
END $$;
ROLLBACK;
