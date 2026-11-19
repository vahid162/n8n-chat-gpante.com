-- Isolated test database only. All fixtures roll back, with no model calls.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
SET LOCAL TIME ZONE 'Pacific/Honolulu';
DO $$
DECLARE s TEXT:=repeat('a',64); c TEXT:=repeat('b',64); accepted TEXT:=repeat('1',32); blocked TEXT:=repeat('2',32);
  a JSONB; rid TEXT; new_rid TEXT; ledger JSONB; why TEXT; before_total INTEGER;
BEGIN
  ASSERT current_database() IN ('gpante_chat_archive_test','gpante_chat_feature017_test'),'isolated database required';
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.request_counters;
  DELETE FROM gpante_chat.turns;
  DELETE FROM gpante_chat.send_guards;
  DELETE FROM gpante_chat.model_calls;
  DELETE FROM gpante_chat.admission_refusals;
  UPDATE gpante_chat.policy SET allow_clear=true;
  UPDATE gpante_chat.runtime SET enabled=true,monthly_budget_nano=2000000000,pricing_valid_until=clock_timestamp()+INTERVAL '1 day';
  INSERT INTO gpante_chat.model_calls(request_id,call_index,month,cost_nano,state) VALUES
    ('today-reserved',1,date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date,320000000,'reserved'),
    ('last-month-burned',1,(date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')-INTERVAL '1 month')::date,2000000000,'burned');
  SELECT jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index) INTO ledger FROM gpante_chat.model_calls m;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','existing question',2,accepted,NULL,NULL,NULL);
  ASSERT a->>'allowed'='true' AND NOT a ? 'answerAvailability','successful send keeps old admission response shape';
  rid:=a->>'requestId';
  ASSERT gpante_chat.finish_v3(rid,'existing answer','success',NULL)->>'saved'='true','existing turn created';

  FOREACH why IN ARRAY ARRAY['pricing_review','monthly_budget','disabled'] LOOP
    UPDATE gpante_chat.runtime SET enabled=why<>'disabled',
      monthly_budget_nano=CASE WHEN why='monthly_budget' THEN 639999999 ELSE 2000000000 END,
      pricing_valid_until=clock_timestamp()+CASE WHEN why='pricing_review' THEN -INTERVAL '1 second' ELSE INTERVAL '1 day' END;
    a:=gpante_chat.admit_v4(s,c,'loadPreviousSession',NULL,2,NULL,NULL,NULL,NULL);
    ASSERT a->>'allowed'='true' AND a->>'answerAvailability'=why AND jsonb_array_length(a->'messages')=2,'history remains available during each block';
    ASSERT NOT (a ?| ARRAY['budget','readiness','monthlyBudgetNano','remainingNano','pricingValidUntil']),'no private readiness data in history';
    a:=gpante_chat.admit_v4(s,c,'sendMessage','unadmitted text',2,blocked,NULL,NULL,NULL);
    ASSERT a=jsonb_build_object('allowed',false,'reason',why),'fresh send known block gives only a fixed reason';
    ASSERT (SELECT count(*)=1 FROM gpante_chat.requests) AND (SELECT count(*)=1 FROM gpante_chat.turns)
      AND (SELECT count(*)=2 FROM gpante_chat.histories) AND (SELECT count(*)=1 FROM gpante_chat.send_guards),'known blocks preserve archive and do not claim the send ID';
    ASSERT gpante_chat.admit_v4(s,c,'sendMessage','existing question',2,accepted,NULL,NULL,NULL)->>'output'='existing answer','exact replay still works';
    ASSERT gpante_chat.admit_v4(s,c,'sendMessage','changed replay',2,accepted,NULL,NULL,NULL)->>'reason'='invalid_input','replay content still validated';
    ASSERT gpante_chat.admit_v4(s,c,'feedback',NULL,2,NULL,NULL,rid,'helpful')->>'ok'='true','owned feedback still works';
    ASSERT (SELECT refused=1 FROM gpante_chat.admission_refusals WHERE reason=why
      AND day=(clock_timestamp() AT TIME ZONE 'UTC')::date),'each blocked new send increments its own daily aggregate';
  END LOOP;
  ASSERT gpante_chat.admit_v4(s,c,'sendMessage','legacy request',1,blocked,NULL,NULL,NULL)->>'reason'='client_upgrade','legacy client rejection still applies first';
  ASSERT gpante_chat.admit_v4(s,c,'resetSession',NULL,2,NULL,NULL,NULL,NULL)->>'ok'='true','authorized clear still works';
  ASSERT gpante_chat.admit_v4(s,c,'sendMessage','existing question',2,accepted,NULL,NULL,NULL)->>'reason'='prior_request_expired','cleared send still cannot replay';
  ASSERT gpante_chat.admit_v4(s,c,'sendMessage','unadmitted text',2,'invalid ID',NULL,NULL,NULL)->>'reason'='invalid_input','send ID still validated';
  ASSERT (SELECT jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index) FROM gpante_chat.model_calls m)=ledger,'no refusal or read can change model accounting';
  SELECT (gpante_chat.admin_api('{"operation":"getDiagnostics"}') #>> '{summary,total}')::integer INTO before_total;
  ASSERT before_total=1,'blocked sends stay outside the accepted-turn count';
  DELETE FROM gpante_chat.request_counters;
  WHILE (SELECT coalesce(max(requests),0) FROM gpante_chat.request_counters WHERE counter_key='message:'||c)<6 LOOP
    ASSERT gpante_chat.admit_v4(s,c,'sendMessage','unadmitted text',2,blocked,NULL,NULL,NULL)->>'reason'='disabled','durable block still participates in rate limits';
  END LOOP;
  ASSERT gpante_chat.admit_v4(s,c,'sendMessage','unadmitted text',2,blocked,NULL,NULL,NULL)->>'reason'='rate_limit','known blocks cannot bypass existing send rate limits';
  DELETE FROM gpante_chat.request_counters;
  UPDATE gpante_chat.runtime SET enabled=true,monthly_budget_nano=640000000;
  a:=gpante_chat.admit_v4(s,c,'loadPreviousSession',NULL,2,NULL,NULL,NULL,NULL);
  ASSERT a->>'answerAvailability'='ready' AND jsonb_array_length(a->'messages')=0,'exact reserve threshold and recovery are available, with clear preserved';
  a:=gpante_chat.admit_v4(s,c,'sendMessage','unadmitted text',2,blocked,NULL,NULL,NULL);
  ASSERT a->>'allowed'='true','send ID from a known pre-admission refusal is reusable after recovery';
  new_rid:=a->>'requestId';
  UPDATE gpante_chat.runtime SET pricing_valid_until=clock_timestamp()-INTERVAL '1 second';
  ASSERT gpante_chat.reserve_call(new_rid,1)->>'reason'='pricing_review','model reservation rechecks pricing immediately before dispatch';
  ASSERT (SELECT jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index) FROM gpante_chat.model_calls m)=ledger,'reserve denial does not charge';
  ASSERT NOT has_function_privilege('public','gpante_chat.answer_availability(timestamptz)','EXECUTE')
    AND NOT has_function_privilege('gpante_chat_admin','gpante_chat.answer_availability(timestamptz)','EXECUTE'),'helper remains private';
END $$;
ROLLBACK;
