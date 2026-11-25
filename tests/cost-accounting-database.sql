-- Isolated test database after 010. All fixtures roll back, with no model calls.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
SET LOCAL TIME ZONE 'Pacific/Honolulu';
DO $$
DECLARE m DATE:=date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date;
  s TEXT:=repeat('a',64); c TEXT:=repeat('b',64); rid TEXT; result JSONB;
BEGIN
  ASSERT current_database() IN ('gpante_chat_archive_test','gpante_chat_feature017_test'),'isolated database required';
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.request_counters;
  DELETE FROM gpante_chat.turns;
  DELETE FROM gpante_chat.send_guards;
  DELETE FROM gpante_chat.model_calls;
  DELETE FROM gpante_chat.admission_refusals;
  UPDATE gpante_chat.runtime SET enabled=true,monthly_budget_nano=2000000000,pricing_valid_until=clock_timestamp()+INTERVAL '1 day';
  INSERT INTO gpante_chat.model_calls(request_id,call_index,month,cost_nano,state) VALUES
    ('previous-month',1,(m-INTERVAL '1 month')::date,5000000000,'settled'),
    ('current-settled',1,m,2000000001,'settled'),
    ('current-unknown',1,m,320000000,'burned');
  INSERT INTO gpante_chat.admission_refusals(day,reason,refused) VALUES((clock_timestamp() AT TIME ZONE 'UTC')::date,'monthly_budget',3);

  result:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT result#>>'{readiness,reason}'='ready' AND result#>>'{readiness,canAcceptNewTurn}'='true','former cap does not affect admin readiness';
  ASSERT result#>>'{budget,budgetLimitEnabled}'='false' AND result#>'{budget,monthlyBudgetNano}'='null'::jsonb
    AND result#>'{readiness,remainingNano}'='null'::jsonb,'private report describes cost accounting without a ceiling';
  ASSERT result#>>'{budget,settledNano}'='2000000001' AND result#>>'{budget,usedNano}'='2320000001','admin separates settled costs from conservative estimates within the UTC month';
  ASSERT result#>>'{refusals,total}'='3','existing budget refusals remain in the seven-day report';
  ASSERT (SELECT monthly_budget_nano=2000000000 FROM gpante_chat.runtime WHERE singleton),'reporting does not rewrite the prior limit value';

  result:=gpante_chat.admit_v4(s,c,'loadPreviousSession',NULL,2,NULL,NULL,NULL,NULL);
  ASSERT result->>'answerAvailability'='ready' AND NOT (result ?| ARRAY['budget','readiness','settledNano','remainingNano']),
    'public readiness continues to expose only its coarse state';
  result:=gpante_chat.admit_v4(s,c,'sendMessage','cost accounting fixture',2,repeat('1',32),NULL,NULL,NULL);
  ASSERT result->>'allowed'='true','new turn allowed above the old cutoff';
  rid:=result->>'requestId';
  ASSERT gpante_chat.reserve_call(rid,1)->>'allowed'='true','per-call reservation allowed above the old cutoff';
  ASSERT gpante_chat.reserve_call(rid,1)->>'reason'='duplicate_or_unsettled','duplicate reservations still fail';
  ASSERT gpante_chat.reserve_call(rid,2)->>'reason'='duplicate_or_unsettled','next round still waits for settlement';
  ASSERT gpante_chat.reserve_call(rid,5)->>'reason'='expired_request','four-call limit remains';
  result:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT result#>>'{budget,settledNano}'='2000000001' AND result#>>'{budget,usedNano}'='2640000001','pending reservation stays out of settled costs';
  ASSERT gpante_chat.settle_call(rid,1,100,10,110)->>'confirmed'='true','known usage settles';
  ASSERT gpante_chat.settle_call(rid,1,100,10,110)->>'confirmed'='false','settlement remains exactly-once';
  result:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT result#>>'{budget,settledNano}'='2000042001' AND result#>>'{budget,usedNano}'='2320042001','confirmed usage replaces the pending estimate';
  ASSERT (SELECT month=m AND month=date_trunc('month',created_at AT TIME ZONE 'UTC')::date AND cost_nano=42000 AND state='settled'
    FROM gpante_chat.model_calls WHERE request_id=rid AND call_index=1),'ledger records the intended UTC month and exact cost';

  UPDATE gpante_chat.runtime SET pricing_valid_until=clock_timestamp()-INTERVAL '1 second';
  ASSERT gpante_chat.reserve_call(rid,2)->>'reason'='pricing_review','per-call pricing validation remains';
  ASSERT gpante_chat.admit_v4(repeat('c',64),c,'sendMessage','pricing fixture',2,NULL,NULL,NULL,NULL)->>'reason'='pricing_review',
    'pre-admission pricing validation remains';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics"}')#>>'{readiness,reason}'='pricing_review','expired pricing remains visible to admin';
  UPDATE gpante_chat.runtime SET enabled=false;
  ASSERT gpante_chat.admit_v4(repeat('c',64),c,'sendMessage','disabled fixture',2,NULL,NULL,NULL,NULL)->>'reason'='disabled','runtime kill switch remains';
  ASSERT NOT has_function_privilege('public','gpante_chat.reserve_call(text,integer)','EXECUTE')
    AND NOT has_function_privilege('gpante_chat_admin','gpante_chat.answer_availability(timestamptz)','EXECUTE')
    AND NOT has_function_privilege('public','gpante_chat.admin_api(jsonb)','EXECUTE')
    AND has_function_privilege('gpante_chat_admin','gpante_chat.admin_api(jsonb)','EXECUTE'),'private accounting and administrator grants remain scoped';
END $$;
ROLLBACK;
