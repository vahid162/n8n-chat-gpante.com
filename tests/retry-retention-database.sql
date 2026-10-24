-- Isolated database only; every fixture rolls back and no model is called.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DO $$
DECLARE s TEXT:=repeat('8',64); c TEXT:=repeat('9',64); send_id TEXT:=repeat('7',32);
  a JSONB; r TEXT; ledger_before BIGINT; turns_before BIGINT;
BEGIN
  ASSERT current_database()='gpante_chat_archive_test','isolated database required';
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.request_counters;
  DELETE FROM gpante_chat.turns;
  DELETE FROM gpante_chat.send_guards;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','retained retry',2,send_id,NULL,NULL,NULL);
  ASSERT (a->>'allowed')::boolean,'admit current send';
  r:=a->>'requestId';
  PERFORM gpante_chat.finish_v3(r,'original answer','success',NULL);
  ASSERT (SELECT count(*)=1 FROM gpante_chat.send_guards WHERE session_id=s AND client_message_id=send_id),'accepted send gets text-free guard';

  UPDATE gpante_chat.turns SET created_at=clock_timestamp()-INTERVAL '31 days',user_expires_at=clock_timestamp()-INTERVAL '1 day' WHERE request_id=r;
  UPDATE gpante_chat.histories SET created_at=clock_timestamp()-INTERVAL '31 days' WHERE turn_id=r;
  SELECT coalesce(sum(cost_nano),0) INTO ledger_before FROM gpante_chat.model_calls;
  PERFORM gpante_chat.cleanup();
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.turns WHERE request_id=r),'physical archive cleanup removes old turn';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.histories WHERE turn_id=r),'physical archive cleanup removes text';
  SELECT count(*) INTO turns_before FROM gpante_chat.turns;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','retained retry',2,send_id,NULL,NULL,NULL);
  ASSERT a->>'reason'='prior_request_expired','deleted send cannot become another paid turn';
  ASSERT (SELECT count(*)=turns_before FROM gpante_chat.turns),'expired retry creates no turn';
  ASSERT (SELECT coalesce(sum(cost_nano),0)=ledger_before FROM gpante_chat.model_calls),'cleanup and replay preserve ledger';

  UPDATE gpante_chat.request_counters SET requests=30 WHERE counter_key='client:'||c;
  ASSERT gpante_chat.admit_v4(s,c,'sendMessage','retained retry',2,send_id,NULL,NULL,NULL)->>'reason'='rate_limit','deleted sends still pass the IP gate';
  DELETE FROM gpante_chat.request_counters;
  ASSERT NOT has_table_privilege('gpante_chat_admin','gpante_chat.send_guards','SELECT'),'administrator has no raw send guard access';
  ASSERT NOT has_function_privilege('gpante_chat_admin','gpante_chat.admit_v4(text,text,text,text,integer,text,text,text,text)','EXECUTE'),'administrator cannot dispatch public chat';

  UPDATE gpante_chat.send_guards SET idle_expires_at=clock_timestamp()+INTERVAL '1 hour' WHERE session_id=s;
  a:=gpante_chat.admit_v4(s,c,'loadPreviousSession',NULL,2,NULL,NULL,NULL,NULL);
  ASSERT (a->>'allowed')::boolean,'active session can read';
  ASSERT (SELECT idle_expires_at>clock_timestamp()+INTERVAL '30 days 4 minutes' FROM gpante_chat.send_guards WHERE session_id=s AND client_message_id=send_id),'history activity renews guard with response grace and without restoring text';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.histories WHERE turn_id=r),'renewal never restores transcript';

  a:=gpante_chat.admit_v4(repeat('6',64),c,'sendMessage','another visitor',2,send_id,NULL,NULL,NULL);
  ASSERT (a->>'allowed')::boolean,'same send ID in another session remains independent';
  PERFORM gpante_chat.finish_v3(a->>'requestId',NULL,'error','fixture_error');
  UPDATE gpante_chat.send_guards SET idle_expires_at=clock_timestamp()-INTERVAL '1 second' WHERE session_id=s;
  PERFORM gpante_chat.cleanup();
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.send_guards WHERE session_id=s),'idle guards expire independently';
  ASSERT EXISTS(SELECT 1 FROM gpante_chat.send_guards WHERE session_id=repeat('6',64)),'another active session guard remains';
END $$;
ROLLBACK;
