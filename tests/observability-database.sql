-- Isolated optional metadata/readiness checks. No model calls; fixtures roll back.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
SET LOCAL TIME ZONE 'Pacific/Honolulu';
DO $$ BEGIN
  ASSERT current_database() IN ('gpante_chat_archive_test','gpante_chat_feature017_test'),'isolated database required';
END $$;
DELETE FROM gpante_chat.requests;
DELETE FROM gpante_chat.request_counters;
DELETE FROM gpante_chat.turns;
DELETE FROM gpante_chat.admission_refusals;
DELETE FROM gpante_chat.model_calls;
UPDATE gpante_chat.runtime SET enabled=true,monthly_budget_nano=2000000000,pricing_valid_until=clock_timestamp()+INTERVAL '30 days';
CREATE TEMP TABLE observability_before ON COMMIT DROP AS SELECT
  (SELECT to_jsonb(p) FROM gpante_chat.policy p) AS policy,
  (SELECT coalesce(jsonb_agg(to_jsonb(m)),'[]'::jsonb) FROM gpante_chat.model_calls m) AS ledger;
DO $$
DECLARE s TEXT:=repeat('a',64); c TEXT:=repeat('b',64); data JSONB; a JSONB; r TEXT; events JSONB; old_day DATE;
BEGIN
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'readiness'->>'canAcceptNewTurn'='true' AND data->'readiness'->>'reason'='ready','ready is an independent backend gate snapshot';
  ASSERT data->'readiness'->>'remainingNano'='2000000000' AND data->'readiness'->>'pricingReviewSoon'='false','budget remaining and pricing lead-time';
  ASSERT data->'refusals'->>'total'='0' AND data->'refusals'->>'calendarUtc'='true','new counters start at zero, not backfilled';
  UPDATE gpante_chat.runtime SET enabled=false;
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics"}')->'readiness'->>'reason'='disabled','disabled service distinct from management health';
  UPDATE gpante_chat.runtime SET enabled=true,pricing_valid_until=clock_timestamp()-INTERVAL '1 second';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics"}')->'readiness'->>'reason'='pricing_review','expired pricing blocks readiness';
  UPDATE gpante_chat.runtime SET pricing_valid_until=clock_timestamp()+INTERVAL '3 days',monthly_budget_nano=319999999;
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'readiness'->>'reason'='monthly_budget' AND data->'readiness'->>'pricingReviewSoon'='true','first-call reservation threshold and upcoming review';
  UPDATE gpante_chat.runtime SET monthly_budget_nano=2000000000;
  INSERT INTO gpante_chat.requests(request_id,session_id,client_id,expires_at) VALUES
    (md5('capacity-one'),repeat('c',64),repeat('d',64),clock_timestamp()+INTERVAL '1 minute'),
    (md5('capacity-two'),repeat('e',64),repeat('f',64),clock_timestamp()+INTERVAL '1 minute');
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics"}')->'readiness'->>'reason'='capacity','global capacity reflected without starting a request';
  FOR i IN 1..2 LOOP
    a:=gpante_chat.admit_v4(s,c,'sendMessage','private refused question',2,md5('refusal-'||i),NULL,NULL,NULL);
    ASSERT a->>'reason'='capacity','existing refusal response unchanged';
  END LOOP;
  ASSERT (SELECT count(*)=0 FROM gpante_chat.turns),'refusals do not create accepted turns';
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'summary'->>'total'='0' AND data->'refusals'->>'total'='2','refusals separate from accepted-turn errors';
  ASSERT data->'refusals'->'days'->0->>'date'=(clock_timestamp() AT TIME ZONE 'UTC')::date::text,'daily counters use UTC independently of DB timezone';
  ASSERT (data->'refusals')::text NOT LIKE '%private%' AND (data->'refusals')::text NOT LIKE '%'||s||'%' AND (data->'refusals')::text NOT LIKE '%'||c||'%','counts contain no question, identity or send id';
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.request_counters;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','private accepted question',2,md5('accepted-observability'),NULL,NULL,NULL);r:=a->>'requestId';
  ASSERT a->>'allowed'='true','valid admission retained';
  a:=gpante_chat.admit_v4(s,c,'sendMessage','private busy question',2,md5('busy-observability'),NULL,NULL,NULL);
  ASSERT a->>'reason'='session_busy','session retry guard retained';
  events:=jsonb_build_array(
    jsonb_build_object('event','source','tool','Knowledge_Read','source','site','status','api_error','targetId',456,'query','',
      'body','private lookup body','url','https://private.invalid','filters','{}'::jsonb,'results','[]'::jsonb),
    jsonb_build_object('event','execution','workflowVersion','1.9.0','sourceRevision',repeat('a',64),'modelRounds',4,'toolCalls',3,'body','private provider payload'),
    jsonb_build_object('event','execution','workflowVersion','1.9.0','sourceRevision',repeat('a',64),'modelRounds',5,'toolCalls',3));
  data:=gpante_chat.finish_v4(r,'private accepted answer','success',NULL,events);
  ASSERT data->>'saved'='true' AND data->>'diagnosticsSaved'='true','metadata never prevents answer persistence';
  SELECT d.events INTO events FROM gpante_chat.search_diagnostics d WHERE d.request_id=r;
  ASSERT jsonb_array_length(events)=2 AND events->0->>'targetId'='456','failed public lookup ID retained; invalid event rejected';
  ASSERT events->1->>'workflowVersion'='1.9.0' AND events->1->>'modelRounds'='4' AND events->1->>'toolCalls'='3','version and round counts survive SQL allowlist';
  ASSERT events::text NOT LIKE '%private%' AND events::text NOT LIKE '%http%' AND events::text NOT LIKE '%payload%','no arbitrary diagnostic body or URL retained';
  DELETE FROM gpante_chat.request_counters;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','invalid optional target fixture',2,md5('invalid-target'),NULL,NULL,NULL);r:=a->>'requestId';
  data:=gpante_chat.finish_v4(r,'answer survives invalid target metadata','success',NULL,
    '[{"event":"source","tool":"Product_Details","status":"api_error","targetId":"private@example.invalid"},
      {"event":"source","tool":"Knowledge_Read","targetId":9007199254740992}]');
  SELECT d.events INTO events FROM gpante_chat.search_diagnostics d WHERE d.request_id=r;
  ASSERT data->>'saved'='true' AND jsonb_array_length(events)=2 AND NOT events->0 ? 'targetId' AND NOT events->1 ? 'targetId','invalid/string/unsafe target IDs dropped without losing valid source status';
  old_day:=(clock_timestamp() AT TIME ZONE 'UTC')::date-7;
  INSERT INTO gpante_chat.admission_refusals VALUES(old_day,'rate_limit',9);
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'refusals'->>'total'='3','expired daily counts invisible before cleanup';
  PERFORM gpante_chat.cleanup();
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.admission_refusals WHERE day=old_day),'hourly cleanup removes old counters';
  ASSERT NOT has_table_privilege('gpante_chat_admin','gpante_chat.admission_refusals','SELECT')
    AND NOT has_function_privilege('gpante_chat_admin','gpante_chat.admin_api_before_observability(jsonb)','EXECUTE'),'admin limited to current fixed API';
  ASSERT NOT has_function_privilege('public','gpante_chat.admit_v4(text,text,text,text,integer,text,text,text,text)','EXECUTE'),'public SQL execution not granted';
  ASSERT (SELECT coalesce(jsonb_agg(to_jsonb(m)),'[]'::jsonb) FROM gpante_chat.model_calls m)=(SELECT ledger FROM observability_before),'no model reservations or accounting writes during audit';
  ASSERT (SELECT to_jsonb(p) FROM gpante_chat.policy p)=(SELECT policy FROM observability_before),'retention and clear policy preserved';
END $$;
SET LOCAL ROLE gpante_chat_admin;
DO $$
DECLARE data JSONB;
BEGIN
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'readiness'->>'canAcceptNewTurn'='true' AND data->'refusals'->>'total'='3','limited admin role can read new metadata';
  ASSERT data->>'windowDays'='7' AND data->>'page'='1','existing diagnostic API preserved';
  ASSERT gpante_chat.admin_api('{"operation":"getPolicy"}')->'policy'->>'archiveDays'='30','existing policy operation still delegates';
END $$;
ROLLBACK;
