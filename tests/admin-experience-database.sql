-- Isolated admin-only metadata fixtures. No model calls; every fixture rolls back.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
SET LOCAL TIME ZONE 'Pacific/Honolulu';
DO $$ BEGIN
  ASSERT current_database() IN ('gpante_chat_archive_test','gpante_chat_feature017_test'),'isolated database required';
END $$;
INSERT INTO gpante_chat.model_calls(request_id,call_index,month,cost_nano,state,prompt_tokens,completion_tokens) VALUES
  (md5('admin-current-reserved'),1,date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date,320000000,'reserved',NULL,NULL),
  (md5('admin-current-settled'),1,date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date,23456,'settled',70,2),
  (md5('admin-current-burned'),1,date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date,320000000,'burned',NULL,NULL),
  (md5('admin-prior-month'),1,(date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')-INTERVAL '1 month')::date,777777,'settled',100,5);
CREATE TEMP TABLE admin_experience_before ON COMMIT DROP AS SELECT
  (SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]'::jsonb) FROM gpante_chat.model_calls m) AS ledger,
  (SELECT to_jsonb(p) FROM gpante_chat.policy p) AS policy,
  (SELECT to_jsonb(r) FROM gpante_chat.runtime r) AS runtime,
  (SELECT proacl FROM pg_proc WHERE oid='gpante_chat.admin_api(jsonb)'::regprocedure) AS privileges;
DO $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); day TIMESTAMPTZ:=(ts AT TIME ZONE 'UTC')::date::timestamp AT TIME ZONE 'UTC';
  data JSONB; second_page JSONB; final_page JSONB; expected JSONB; original_summary JSONB; bad JSONB; wanted TEXT;
  sid TEXT:=repeat('a',64); private_text TEXT:='private transcript must stay outside diagnostics';
BEGIN
  DELETE FROM gpante_chat.requests;
  DELETE FROM gpante_chat.turns;
  INSERT INTO gpante_chat.requests(request_id,session_id,client_id,expires_at)
    VALUES(md5('admin-active-lease'),repeat('c',64),repeat('d',64),ts+INTERVAL '1 minute');
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status,error_code,traffic_kind)
    SELECT md5('admin-experience-'||g),sid,day-INTERVAL '12 hours',ts+INTERVAL '1 day',
      CASE WHEN g%5=0 THEN 'error' WHEN g%7=0 THEN 'interrupted' WHEN g%11=0 THEN 'legacy' WHEN g%13=0 THEN 'pending' ELSE 'success' END,
      CASE WHEN g%5=0 THEN 'diagnostic_fixture_error' END,CASE WHEN g%3=0 THEN 'synthetic' ELSE 'unclassified' END
    FROM generate_series(1,45) g;
  INSERT INTO gpante_chat.search_diagnostics(request_id,created_at,events)
    SELECT md5('admin-experience-'||g),ts-INTERVAL '1 hour',jsonb_build_array(jsonb_build_object('event','source',
      'tool','Knowledge_Search','source',CASE WHEN g%2=0 THEN 'site' ELSE 'products' END,'status','ok',
      'query',CASE WHEN g=41 THEN '<script>needle metadata</script>' ELSE 'fixture phrase' END,'filters','{}'::jsonb,'results','[]'::jsonb))
    FROM generate_series(1,45) g;
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status) VALUES
    (md5('admin-day-start'),sid,day-INTERVAL '2 days',ts+INTERVAL '1 day','success'),
    (md5('admin-day-end'),sid,day-INTERVAL '1 day 1 second',ts+INTERVAL '1 day','success'),
    (md5('admin-old-turn'),sid,ts-INTERVAL '8 days',ts+INTERVAL '1 day','success'),
    (md5('admin-recent-legacy'),sid,ts-INTERVAL '1 hour',ts+INTERVAL '1 day','legacy');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at) VALUES
    (sid,md5('admin-experience-1'),jsonb_build_object('type','human','data',jsonb_build_object('content',private_text)),day-INTERVAL '12 hours'),
    (sid,md5('admin-experience-1'),'{"type":"ai","data":{"content":"public fixture answer"}}',day-INTERVAL '12 hours');
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}'); original_summary:=data->'summary';
  ASSERT data->>'windowDays'='7' AND data->>'page'='1' AND (data->>'hasMore')::boolean,'default page has a seventh-day window and continuation';
  ASSERT jsonb_array_length(data->'turns')=20 AND data->'summary'->>'total'='48','summary covers the unfiltered window beyond the first twenty';
  ASSERT data->'budget'->>'activeLeases'='1' AND (data->'budget'->>'usedNano')::bigint>=640023456,'current reserved settled and burned usage remain budgeted';
  ASSERT NOT EXISTS(SELECT 1 FROM jsonb_array_elements(data->'turns') t WHERE t->>'sessionId' IS DISTINCT FROM sid),'admin links use the existing session id';
  ASSERT data::text NOT LIKE '%'||private_text||'%' AND data::text NOT LIKE '%messages%' AND data::text NOT LIKE '%clientId%','diagnostics do not copy transcripts or client identity';
  SELECT jsonb_agg(request_id ORDER BY created_at DESC,request_id DESC) INTO expected FROM
    (SELECT request_id,created_at FROM gpante_chat.turns WHERE created_at>ts-INTERVAL '7 days' ORDER BY created_at DESC,request_id DESC LIMIT 20) q;
  ASSERT (SELECT jsonb_agg(t->>'requestId') FROM jsonb_array_elements(data->'turns') t)=expected,'first page has deterministic timestamp/id ordering';
  second_page:=gpante_chat.admin_api('{"operation":"getDiagnostics","page":2}');
  final_page:=gpante_chat.admin_api('{"operation":"getDiagnostics","page":3}');
  ASSERT jsonb_array_length(second_page->'turns')=20 AND (second_page->>'hasMore')::boolean AND second_page->>'page'='2','page two reads rows beyond the first twenty';
  ASSERT jsonb_array_length(final_page->'turns')=8 AND NOT (final_page->>'hasMore')::boolean,'last page uses a twenty-first row for hasMore';
  ASSERT (SELECT count(DISTINCT t->>'requestId')=48 FROM jsonb_array_elements((data->'turns')||(second_page->'turns')||(final_page->'turns')) t),'pages do not repeat tied timestamps';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","page":1000}')->'turns'='[]'::jsonb,'bounded out-of-range page is empty';
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics","search":"NEEDLE metadata"}');
  ASSERT jsonb_array_length(data->'turns')=1 AND data->'turns'->0->>'requestId'=md5('admin-experience-41'),'case-insensitive metadata search covers all seven days';
  ASSERT data->'summary'=original_summary,'filters never narrow summary statistics';
  ASSERT jsonb_array_length(gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','search','needle metadata','status','success',
    'traffic','unclassified','source','products','from',((day AT TIME ZONE 'UTC')::date-1)::text,'to',((day AT TIME ZONE 'UTC')::date-1)::text))->'turns')=1,
    'search dates status traffic and source intersect across the full window';
  ASSERT gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','search',private_text))->'turns'='[]'::jsonb,'metadata search does not read transcript text';
  ASSERT jsonb_array_length(gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','search',md5('admin-experience-44')))->'turns')=1,'request id search';
  ASSERT jsonb_array_length(gpante_chat.admin_api('{"operation":"getDiagnostics","search":"diagnostic_fixture_error"}')->'turns')=9,'error code search';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","search":"%"}')->'turns'='[]'::jsonb,'search wildcard is literal';
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics","status":"error","traffic":"synthetic","source":"site"}');
  ASSERT jsonb_array_length(data->'turns')=1 AND data->'turns'->0->>'requestId'=md5('admin-experience-30'),'status traffic and source filters apply together before pagination';
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics","source":"site","page":2}');
  ASSERT jsonb_array_length(data->'turns')=2 AND NOT (data->>'hasMore')::boolean,'source filtering precedes page offset and continuation calculation';
  FOREACH wanted IN ARRAY ARRAY['pending','success','error','interrupted','legacy'] LOOP
    data:=gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','status',wanted));
    ASSERT jsonb_array_length(data->'turns')>0 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(data->'turns') t WHERE t->>'status'<>wanted),'all stored status filters';
  END LOOP;
  FOREACH wanted IN ARRAY ARRAY['synthetic','unclassified'] LOOP
    data:=gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','traffic',wanted));
    ASSERT jsonb_array_length(data->'turns')>0 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(data->'turns') t WHERE t->>'trafficKind'<>wanted),'all traffic filters';
  END LOOP;
  FOREACH wanted IN ARRAY ARRAY['products','articles','site','faq','guides','categories'] LOOP
    data:=gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','source',wanted));
    ASSERT NOT data ? 'error' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(data->'turns') t WHERE NOT EXISTS
      (SELECT 1 FROM jsonb_array_elements(t->'events') e WHERE e->>'event'='source' AND e->>'source'=wanted)),'known source filters use current bounded source events';
  END LOOP;
  data:=gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','from',((day AT TIME ZONE 'UTC')::date-2)::text,'to',((day AT TIME ZONE 'UTC')::date-2)::text));
  ASSERT jsonb_array_length(data->'turns')=2,'date boundaries use complete UTC calendar days independent of database timezone';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","from":"2024-02-29","to":"2024-02-29"}')->'turns'='[]'::jsonb,'valid leap day does not extend rolling seven-day retention';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","to":"2024-02-29"}')->'turns'='[]'::jsonb,'valid upper date outside retention is an empty filter rather than invalid input';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","from":"2099-01-01"}')->'turns'='[]'::jsonb,'valid future lower bound is an empty filter rather than invalid input';
  FOR bad IN SELECT v FROM jsonb_array_elements('[{"page":0},{"page":1001},{"page":1.5},{"page":"2"},{"page":null},{"search":null},{"search":[]},{"from":"2026-02-29"},{"from":"2024-02-30"},{"from":"0000-01-01"},{"from":"2026-13-01"},{"from":null},{"to":42},{"to":"2026-10-01T00:00:00Z"},{"from":"2026-10-02","to":"2026-10-01"},{"status":"human"},{"traffic":"human"},{"source":"unknown"},{"source":{}}]'::jsonb) v LOOP
    ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics"}'::jsonb||bad)->>'error'='invalid_input','malformed filter rejected: '||bad::text;
  END LOOP;
  ASSERT gpante_chat.admin_api(jsonb_build_object('operation','getDiagnostics','search',repeat('x',101)))->>'error'='invalid_input','search length enforced independently of workflow';
  ASSERT gpante_chat.admin_api('{"operation":"getDiagnostics","sql":"DROP TABLE anything","url":"https://private.invalid"}')->'summary'=original_summary,'unknown input fields cannot choose destinations or SQL';
  data:=gpante_chat.admit_v4(sid,repeat('b',64),'loadPreviousSession',NULL,2,NULL,NULL,NULL,NULL);
  ASSERT (data->>'allowed')::boolean AND data::text NOT LIKE '%sessionId%' AND data::text NOT LIKE '%'||sid||'%','public history never receives the administrator session link';
  ASSERT NOT has_table_privilege('gpante_chat_admin','gpante_chat.turns','SELECT')
    AND NOT has_function_privilege('gpante_chat_admin','gpante_chat.admin_api_archive_v3(jsonb)','EXECUTE'),'admin still has no direct table or retired entry access';
  ASSERT (SELECT prosecdef AND proconfig @> ARRAY['search_path=pg_catalog, gpante_chat','statement_timeout=8s'] FROM pg_proc WHERE oid='gpante_chat.admin_api(jsonb)'::regprocedure),'security definer search path and timeout preserved';
END $$;
SET LOCAL ROLE gpante_chat_admin;
DO $$
DECLARE data JSONB;
BEGIN
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics","page":2}');
  ASSERT data->>'page'='2' AND jsonb_array_length(data->'turns')=20 AND data->'turns'->0->>'sessionId'=repeat('a',64),'limited admin role can page and open session links';
  ASSERT (gpante_chat.admin_api(jsonb_build_object('operation','markSynthetic','requestId',md5('admin-recent-legacy')))->>'ok')::boolean,'existing privileged synthetic labeling delegates correctly';
  ASSERT gpante_chat.admin_api('{"operation":"markSynthetic","requestId":"bad"}')->>'error'='invalid_input','synthetic validation preserved';
  ASSERT gpante_chat.admin_api('{"operation":"getPolicy"}')->'policy'->>'archiveDays'='30','existing getPolicy delegated';
  ASSERT jsonb_array_length(gpante_chat.admin_api(jsonb_build_object('operation','getSession','sessionId',repeat('a',64)))->'turns')=20,'existing getSession delegated with archived transcripts';
  ASSERT jsonb_array_length(gpante_chat.admin_api('{"operation":"listSessions","search":"private transcript"}')->'sessions')=1,'existing archive search delegated';
  ASSERT gpante_chat.admin_api('{"operation":"setPolicy","revision":1,"allowClear":true}')->>'error'='invalid_input','existing policy validation delegated';
  ASSERT gpante_chat.admin_api('{"operation":"executeSql"}')->>'error'='invalid_operation','unknown operations stay rejected';
END $$;
SET LOCAL ROLE gpante_chat_app;
DO $$
DECLARE data JSONB;
BEGIN
  data:=gpante_chat.admin_api('{"operation":"getDiagnostics"}');
  ASSERT (data->'budget'->>'usedNano')::bigint=(SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls WHERE month=date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date),'budget remains UTC calendar-month accounting';
  ASSERT (SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index),'[]'::jsonb) FROM gpante_chat.model_calls m)=(SELECT ledger FROM admin_experience_before),'all reservations and settlement states preserved';
  ASSERT (SELECT to_jsonb(p) FROM gpante_chat.policy p)=(SELECT policy FROM admin_experience_before),'policy unchanged by diagnostics';
  ASSERT (SELECT to_jsonb(r) FROM gpante_chat.runtime r)=(SELECT runtime FROM admin_experience_before),'runtime and conservative budget unchanged';
  ASSERT (SELECT proacl FROM pg_proc WHERE oid='gpante_chat.admin_api(jsonb)'::regprocedure)=(SELECT privileges FROM admin_experience_before),'function privileges unchanged by reads';
END $$;
ROLLBACK;
