-- Isolated current-schema diagnostics fixtures. No model calls; all data rolls back.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DO $$ BEGIN
  ASSERT current_database() IN ('gpante_chat_archive_test','gpante_chat_feature017_test'),'isolated database required';
END $$;
CREATE TEMP TABLE diagnostic_ledger_before ON COMMIT DROP AS
  SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]'::jsonb) AS rows FROM gpante_chat.model_calls m;
CREATE TEMP TABLE diagnostic_policy_before ON COMMIT DROP AS SELECT to_jsonb(p) AS row FROM gpante_chat.policy p;
DO $$
DECLARE s TEXT:=repeat('a',64); c TEXT:=repeat('b',64); a JSONB; b JSONB; r TEXT; events JSONB; item JSONB;
  previous JSONB; private_query TEXT; mixed_token TEXT:='token_fixture_AbCdGhIjKlMnOpQrStUvWxYz1234'; i INTEGER; old_turn TEXT:=md5('diagnostic-eight-days');
  expired_turn TEXT:=md5('diagnostic-thirty-one-days');
BEGIN
  DELETE FROM gpante_chat.requests; DELETE FROM gpante_chat.request_counters; DELETE FROM gpante_chat.turns;
  UPDATE gpante_chat.runtime SET enabled=true,pricing_valid_until=clock_timestamp()+INTERVAL '1 day';
  a:=gpante_chat.admit_v4(s,c,'sendMessage','private question transcript',2,repeat('1',32),NULL,NULL,NULL);
  ASSERT (a->>'allowed')::boolean,'current admission'; r:=a->>'requestId';
  ASSERT gpante_chat.diagnostic_text(mixed_token,120)='[redacted]','mixed ASCII credentials redacted by database boundary';
  private_query:='كورس ۲۰۲۶ '||mixed_token||' alice@example.invalid https://private.invalid/source +989121234567 '||repeat('a',32)||E'\n';
  events:=jsonb_build_array(jsonb_build_object('event','source','tool','Knowledge_Search','source','site','status','ok',
    'query',private_query,'coverage','incomplete','body','private-source-body','url','private-source-url','secret','private-key',
    'filters',jsonb_build_object('format','dxf','material','https://private.invalid/material','thickness',mixed_token,'password','private-filter'),
    'results',jsonb_build_array(jsonb_build_object('id',101,'rank',99,'kind','page','title','private-title','body','private-hit-body'),
      jsonb_build_object('id','../../private','kind','page'),jsonb_build_object('id','faq-email','kind','faq'))),
    jsonb_build_object('event','rejection','code','source_required','body','private-rejection'),
    jsonb_build_object('event','rejection','code','invalid-Mixed-secret'),
    jsonb_build_object('event','source','tool','Unknown_Admin','body','private-admin'));
  b:=gpante_chat.finish_v4(r,'private answer transcript','success',NULL,events);
  ASSERT (b->>'saved')::boolean AND (b->>'diagnosticsSaved')::boolean,'answer and bounded diagnostics saved';
  SELECT d.events INTO previous FROM gpante_chat.search_diagnostics d WHERE request_id=r;
  ASSERT jsonb_array_length(previous)=2,'only known source/rejection event types accepted';
  ASSERT previous->0->>'query' LIKE 'کورس 2026 %' AND previous->0->>'query' LIKE '%[redacted]%','Persian normalization and redaction';
  ASSERT previous::text NOT LIKE '%alice@example%' AND previous::text NOT LIKE '%https://%' AND previous::text NOT LIKE '%989121234567%'
    AND previous::text NOT LIKE '%'||repeat('a',32)||'%' AND lower(previous::text) NOT LIKE '%'||lower(mixed_token)||'%','contact/URL/token data not retained';
  ASSERT previous->0->'filters'->>'thickness'='[redacted]','mixed credentials redacted in stored filters';
  ASSERT previous::text NOT LIKE '%private-%' AND previous::text NOT LIKE '%transcript%','unexpected body/title/secret/URL fields excluded';
  ASSERT previous->0->'filters' ? 'format' AND NOT previous->0->'filters' ? 'password','filter allowlist';
  ASSERT jsonb_array_length(previous->0->'results')=2 AND previous->0->'results'->0->>'rank'='1'
    AND previous->0->'results'->1->>'rank'='2','valid identities reranked and unsafe identity excluded';
  ASSERT NOT (gpante_chat.finish_v4(r,'changed transcript','success',NULL,'[]')->>'saved')::boolean,'duplicate finish denied';
  ASSERT (SELECT d.events=previous FROM gpante_chat.search_diagnostics d WHERE request_id=r),'duplicate finish cannot replace diagnostics';
  ASSERT (SELECT count(*)=2 FROM gpante_chat.histories WHERE turn_id=r),'diagnostics do not duplicate conversation';
  FOR i IN 1..3 LOOP
    DELETE FROM gpante_chat.request_counters;
    a:=gpante_chat.admit_v4(s,c,'sendMessage','optional diagnostic fixture',2,md5('invalid-meta-'||i),NULL,NULL,NULL);r:=a->>'requestId';
    events:=CASE i WHEN 1 THEN '{"body":"private-object"}'::jsonb WHEN 2 THEN to_jsonb(repeat('x',17000)) ELSE NULL END;
    b:=gpante_chat.finish_v4(r,'answer retained despite invalid metadata','success',NULL,events);
    ASSERT (b->>'saved')::boolean AND (SELECT status='success' FROM gpante_chat.turns WHERE request_id=r),'invalid diagnostics never roll back successful answer';
    ASSERT (SELECT d.events='[]'::jsonb FROM gpante_chat.search_diagnostics d WHERE request_id=r),'invalid or oversized metadata yields empty diagnostics';
  END LOOP;
  DELETE FROM gpante_chat.request_counters;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','object query fixture',2,md5('object-query'),NULL,NULL,NULL);r:=a->>'requestId';
  b:=gpante_chat.finish_v4(r,'answer','success',NULL,jsonb_build_array(jsonb_build_object('event','source','tool','Knowledge_Search',
    'query',jsonb_build_object('body','private-object-body'))));
  ASSERT (b->>'saved')::boolean AND (SELECT d.events->0->>'query'='' FROM gpante_chat.search_diagnostics d WHERE request_id=r),'non-string query cannot copy an unexpected body';
  DELETE FROM gpante_chat.request_counters;
  a:=gpante_chat.admit_v4(s,c,'sendMessage','bounded fixture',2,md5('bounded-meta'),NULL,NULL,NULL);r:=a->>'requestId';
  item:=jsonb_build_object('event','source','tool','Product_Search','source','products','status','ok','query',repeat('ط',150),
    'filters',jsonb_build_object('format',repeat('غ',80)),
    'results',(SELECT jsonb_agg(jsonb_build_object('id',g,'kind','product')) FROM generate_series(1,8) g));
  SELECT jsonb_agg(item) INTO events FROM generate_series(1,13);
  b:=gpante_chat.finish_v4(r,'answer','success',NULL,events);
  SELECT d.events INTO previous FROM gpante_chat.search_diagnostics d WHERE request_id=r;
  ASSERT (b->>'saved')::boolean AND jsonb_array_length(previous)<=12 AND octet_length(previous::text)<=8192,'event count and byte cap';
  ASSERT length(previous->0->>'query')=120 AND length(previous->0->'filters'->>'format')=40
    AND jsonb_array_length(previous->0->'results')=5,'query/filter/result caps';
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status) VALUES
    (old_turn,s,clock_timestamp()-INTERVAL '8 days',clock_timestamp()+INTERVAL '1 day','success'),
    (expired_turn,s,clock_timestamp()-INTERVAL '31 days',clock_timestamp()-INTERVAL '1 day','success');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at) VALUES
    (s,old_turn,'{"type":"human","data":{"content":"archive eight day question"}}',clock_timestamp()-INTERVAL '8 days'),
    (s,expired_turn,'{"type":"human","data":{"content":"expired archive question"}}',clock_timestamp()-INTERVAL '31 days');
  INSERT INTO gpante_chat.search_diagnostics(request_id,created_at,events) VALUES
    (old_turn,clock_timestamp()-INTERVAL '8 days','[]'),(expired_turn,clock_timestamp()-INTERVAL '31 days','[]');
  b:=gpante_chat.cleanup();
  ASSERT (b->>'diagnosticsRemoved')::integer>=1,'scheduled cleanup removes stale diagnostics';
  ASSERT EXISTS(SELECT 1 FROM gpante_chat.turns WHERE request_id=old_turn)
    AND EXISTS(SELECT 1 FROM gpante_chat.histories WHERE turn_id=old_turn),'seven-day diagnostics do not shorten thirty-day archive';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.search_diagnostics WHERE request_id=old_turn)
    AND NOT EXISTS(SELECT 1 FROM gpante_chat.turns WHERE request_id=expired_turn),'diagnostic and archive lifetimes independently applied';
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status)
    SELECT md5('diagnostic-cap-'||g),s,clock_timestamp(),clock_timestamp()+INTERVAL '1 day','success' FROM generate_series(1,2001) g;
  INSERT INTO gpante_chat.search_diagnostics(request_id,created_at,events)
    SELECT md5('diagnostic-cap-'||g),clock_timestamp()-make_interval(secs=>g),'[]'::jsonb FROM generate_series(1,2001) g;
  PERFORM gpante_chat.cleanup();
  ASSERT (SELECT count(*)=2000 FROM gpante_chat.search_diagnostics),'finite global row cap';
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status,user_feedback)
    VALUES(md5('diagnostic-privileged-label'),s,clock_timestamp(),clock_timestamp()+INTERVAL '1 day','success','helpful');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at) VALUES
    (s,md5('diagnostic-privileged-label'),'{"type":"human","data":{"content":"private privileged transcript"}}',clock_timestamp());
  ASSERT NOT has_function_privilege('gpante_chat_admin','gpante_chat.finish_v4(text,text,text,text,jsonb)','EXECUTE'),'admin role cannot finish public turns';
  ASSERT NOT has_table_privilege('gpante_chat_admin','gpante_chat.turns','SELECT'),'administrator accesses only fixed API';
  ASSERT NOT has_function_privilege('gpante_chat_admin','gpante_chat.admin_api_archive_v3(jsonb)','EXECUTE'),'retired admin entry cannot bypass current wrapper';
END $$;
SET LOCAL ROLE gpante_chat_admin;
DO $$
DECLARE data JSONB; changed JSONB;
BEGIN
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->>'windowDays'='7' AND jsonb_array_length(data->'turns')=20,'bounded administrator diagnostic window';
  ASSERT data->'summary'->>'synthetic'='0' AND (data->'summary'->>'helpful')::integer>=1,'feedback counts and no inferred synthetic labels';
  ASSERT data::text NOT LIKE '%private%' AND data::text NOT LIKE '%transcript%'
    AND data::text NOT LIKE '%messages%' AND data::text NOT LIKE '%admin_key%'
    AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(data->'turns') t WHERE coalesce(t->>'sessionId','') !~ '^[a-f0-9]{64}$'),
    'diagnostic DTO exposes only admin session links without transcript or credentials';
  ASSERT data->'budget' ?& ARRAY['enabled','monthlyBudgetNano','pricingValidUntil','usedNano','activeLeases'],'budget state read-only DTO';
  ASSERT gpante_chat.admin_api('{"operation":"markSynthetic","requestId":"bad"}')->>'error'='invalid_input','label identifier validation';
  ASSERT gpante_chat.admin_api(jsonb_build_object('operation','markSynthetic','requestId',repeat('f',32)))->>'error'='turn_unavailable','label requires existing archived turn';
  changed:=gpante_chat.admin_api(jsonb_build_object('operation','markSynthetic','requestId',md5('diagnostic-privileged-label')));
  ASSERT (changed->>'ok')::boolean,'trusted administrator marks known synthetic turn';
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT data->'summary'->>'synthetic'='1' AND data->'turns'->0->>'trafficKind'='synthetic','trusted label reflected in diagnostics';
  ASSERT gpante_chat.admin_api('{"operation":"getPolicy"}')->'policy'->>'archiveDays'='30','existing policy API and archive contract retained';
END $$;
SET LOCAL ROLE gpante_chat_app;
DO $$ BEGIN
  ASSERT (SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]'::jsonb) FROM gpante_chat.model_calls m)
    =(SELECT rows FROM diagnostic_ledger_before),'diagnostics never alter reservation/settlement ledger';
  ASSERT (SELECT to_jsonb(p) FROM gpante_chat.policy p)=(SELECT row FROM diagnostic_policy_before),'diagnostics do not change retention policy';
END $$;
ROLLBACK;
