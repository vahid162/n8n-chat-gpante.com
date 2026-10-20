-- Run once as gpante_chat_app on project_db, after 002. Never on n8n's internal DB.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying archive migration';
  END IF;
END $$;
CREATE TABLE gpante_chat.policy (
  singleton BOOLEAN PRIMARY KEY DEFAULT true CHECK(singleton),
  user_days INTEGER NOT NULL DEFAULT 30 CHECK(user_days IN (3,7,15,30)),
  allow_clear BOOLEAN NOT NULL DEFAULT true,
  revision INTEGER NOT NULL DEFAULT 1,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
INSERT INTO gpante_chat.policy DEFAULT VALUES;
CREATE TABLE gpante_chat.turns (
  request_id TEXT PRIMARY KEY,
  session_id TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL,
  user_expires_at TIMESTAMPTZ NOT NULL,
  hidden_at TIMESTAMPTZ,
  status TEXT NOT NULL CHECK(status IN ('pending','success','error','interrupted','legacy')),
  error_code TEXT,
  finished_at TIMESTAMPTZ,
  duration_ms INTEGER,
  calls INTEGER,
  prompt_tokens BIGINT,
  completion_tokens BIGINT,
  cost_nano BIGINT,
  usage_complete BOOLEAN
);
CREATE INDEX turns_session_idx ON gpante_chat.turns(session_id,created_at,request_id);
CREATE INDEX turns_created_idx ON gpante_chat.turns(created_at);
ALTER TABLE gpante_chat.histories ADD COLUMN turn_id TEXT;
-- Legacy rows have no durable request ID. Do not invent pairing or technical metadata.
INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status)
 SELECT 'legacy:'||id,session_id,created_at,created_at+INTERVAL '30 days','legacy'
 FROM gpante_chat.histories;
UPDATE gpante_chat.histories SET turn_id='legacy:'||id;
ALTER TABLE gpante_chat.histories ALTER COLUMN turn_id SET NOT NULL;
ALTER TABLE gpante_chat.histories ADD CONSTRAINT histories_turn_fk FOREIGN KEY(turn_id)
 REFERENCES gpante_chat.turns(request_id) ON DELETE CASCADE;
CREATE INDEX histories_turn_idx ON gpante_chat.histories(turn_id,id);

CREATE FUNCTION gpante_chat.public_policy() RETURNS JSONB LANGUAGE sql STABLE
 SET search_path=pg_catalog,gpante_chat AS $$
 SELECT jsonb_build_object('version',2,'userDays',user_days,'archiveDays',30,'allowClear',allow_clear,'revision',revision)
 FROM gpante_chat.policy WHERE singleton
$$;

-- Old clients must not silently invoke the previous physical deletion contract.
CREATE OR REPLACE FUNCTION gpante_chat.admit(sid TEXT,cid TEXT,requested_action TEXT)
RETURNS JSONB LANGUAGE sql AS $$ SELECT '{"allowed":false,"reason":"client_upgrade"}'::jsonb $$;
CREATE OR REPLACE FUNCTION gpante_chat.finish_request(rid TEXT,user_text TEXT,assistant_text TEXT)
RETURNS JSONB LANGUAGE sql AS $$ SELECT '{"saved":false}'::jsonb $$;

CREATE FUNCTION gpante_chat.admit_v3(sid TEXT,cid TEXT,requested_action TEXT,user_text TEXT,contract INTEGER)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); rid TEXT; history JSONB; n INTEGER; cfg gpante_chat.policy;
BEGIN
  IF sid IS NULL OR cid IS NULL OR sid !~ '^[a-f0-9]{64}$' OR cid !~ '^[a-f0-9]{64}$'
    OR requested_action IS NULL OR requested_action NOT IN ('sendMessage','loadPreviousSession','resetSession') THEN
    RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
  END IF;
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  SELECT * INTO STRICT cfg FROM gpante_chat.policy WHERE singleton;
  IF requested_action IN ('sendMessage','resetSession') AND contract IS DISTINCT FROM 2 THEN
    RETURN jsonb_build_object('allowed',false,'reason','client_upgrade');
  END IF;
  IF requested_action='sendMessage' AND (user_text IS NULL OR length(user_text) NOT BETWEEN 1 AND 4000) THEN
    RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
  END IF;
  -- IP counters cover all actions; clearing cookies does not reset them.
  INSERT INTO gpante_chat.request_counters VALUES('client:'||cid,ts,0) ON CONFLICT DO NOTHING;
  UPDATE gpante_chat.request_counters SET window_start=ts,requests=0
    WHERE counter_key='client:'||cid AND window_start <= ts-INTERVAL '60 seconds';
  SELECT requests INTO n FROM gpante_chat.request_counters WHERE counter_key='client:'||cid;
  IF n>=30 THEN RETURN jsonb_build_object('allowed',false,'reason','rate_limit','retry_after',60); END IF;
  UPDATE gpante_chat.request_counters SET requests=requests+1 WHERE counter_key='client:'||cid;
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE session_id=sid AND NOT finished AND expires_at>ts) THEN
    RETURN jsonb_build_object('allowed',false,'reason','session_busy','retry_after',10);
  END IF;
  IF requested_action='resetSession' THEN
    IF NOT cfg.allow_clear THEN RETURN jsonb_build_object('allowed',false,'reason','clear_disabled'); END IF;
    UPDATE gpante_chat.turns SET hidden_at=ts WHERE session_id=sid AND hidden_at IS NULL;
    RETURN jsonb_build_object('allowed',true,'ok',true,'policy',gpante_chat.public_policy());
  END IF;
  SELECT coalesce(jsonb_agg(jsonb_build_object('role',CASE message->>'type' WHEN 'human' THEN 'user' ELSE 'assistant' END,
    'content',message->'data'->>'content','expiresAt',user_expires_at) ORDER BY id),'[]'::jsonb) INTO history
    FROM (SELECT h.*,t.user_expires_at FROM gpante_chat.histories h JOIN gpante_chat.turns t ON t.request_id=h.turn_id
      WHERE t.session_id=sid AND t.created_at>ts-INTERVAL '30 days' AND t.user_expires_at>ts AND t.hidden_at IS NULL
      AND t.status IN ('success','legacy') AND message->>'type' IN ('human','ai') ORDER BY h.id DESC LIMIT 12) h;
  IF requested_action='loadPreviousSession' THEN
    RETURN jsonb_build_object('allowed',true,'messages',history,'policy',gpante_chat.public_policy());
  END IF;
  IF (SELECT count(*) FROM gpante_chat.requests WHERE NOT finished AND expires_at>ts)>=2 THEN
    RETURN jsonb_build_object('allowed',false,'reason','capacity','retry_after',10);
  END IF;
  INSERT INTO gpante_chat.request_counters VALUES('message:'||cid,ts,0) ON CONFLICT DO NOTHING;
  UPDATE gpante_chat.request_counters SET window_start=ts,requests=0
    WHERE counter_key='message:'||cid AND window_start<=ts-INTERVAL '60 seconds';
  SELECT requests INTO n FROM gpante_chat.request_counters WHERE counter_key='message:'||cid;
  IF n>=6 THEN RETURN jsonb_build_object('allowed',false,'reason','rate_limit','retry_after',60); END IF;
  UPDATE gpante_chat.request_counters SET requests=requests+1 WHERE counter_key='message:'||cid;
  rid:=md5(random()::text||clock_timestamp()::text||sid);
  INSERT INTO gpante_chat.requests VALUES(rid,sid,cid,ts+INTERVAL '3 minutes',false);
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status)
    VALUES(rid,sid,ts,ts+make_interval(days=>cfg.user_days),'pending');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at)
    VALUES(sid,rid,jsonb_build_object('type','human','data',jsonb_build_object('content',user_text)),ts);
  RETURN jsonb_build_object('allowed',true,'requestId',rid,'messages',history,'policy',gpante_chat.public_policy(),
    'expiresAt',ts+make_interval(days=>cfg.user_days));
END $$;

CREATE FUNCTION gpante_chat.finish_v3(rid TEXT,assistant_text TEXT,outcome TEXT,code TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); t gpante_chat.turns;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  SELECT * INTO t FROM gpante_chat.turns WHERE request_id=rid FOR UPDATE;
  IF NOT FOUND OR t.status<>'pending' OR t.created_at<=ts-INTERVAL '30 days'
    OR NOT EXISTS(SELECT 1 FROM gpante_chat.requests WHERE request_id=rid AND NOT finished AND expires_at>ts) THEN
    RETURN jsonb_build_object('saved',false);
  END IF;
  IF outcome NOT IN ('success','error') OR outcome IS NULL OR
    (outcome='success' AND (assistant_text IS NULL OR length(assistant_text) NOT BETWEEN 1 AND 8000)) THEN
    RETURN jsonb_build_object('saved',false);
  END IF;
  UPDATE gpante_chat.requests SET finished=true WHERE request_id=rid;
  UPDATE gpante_chat.turns SET status=outcome,finished_at=ts,
    duration_ms=least(2147483647,floor(extract(epoch FROM (ts-created_at))*1000))::integer,
    error_code=CASE WHEN outcome='error' THEN CASE WHEN code ~ '^[a-z_]{1,64}$' THEN code ELSE 'unavailable' END END,
    calls=(SELECT count(*) FROM gpante_chat.model_calls WHERE request_id=rid),
    prompt_tokens=(SELECT sum(prompt_tokens) FROM gpante_chat.model_calls WHERE request_id=rid),
    completion_tokens=(SELECT sum(completion_tokens) FROM gpante_chat.model_calls WHERE request_id=rid),
    cost_nano=(SELECT sum(cost_nano) FROM gpante_chat.model_calls WHERE request_id=rid),
    usage_complete=(SELECT coalesce(bool_and(state='settled'),true) FROM gpante_chat.model_calls WHERE request_id=rid)
    WHERE request_id=rid;
  IF outcome='success' THEN
    INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at)
      VALUES(t.session_id,rid,jsonb_build_object('type','ai','data',jsonb_build_object('content',assistant_text)),t.created_at);
  END IF;
  RETURN jsonb_build_object('saved',true);
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.cleanup() RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE removed INTEGER; interrupted INTEGER; ts TIMESTAMPTZ:=clock_timestamp();
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  UPDATE gpante_chat.turns t SET status='interrupted',error_code='execution_interrupted'
    WHERE status='pending' AND NOT EXISTS(SELECT 1 FROM gpante_chat.requests r WHERE r.request_id=t.request_id AND NOT r.finished AND r.expires_at>ts);
  GET DIAGNOSTICS interrupted=ROW_COUNT;
  DELETE FROM gpante_chat.turns WHERE created_at<=ts-INTERVAL '30 days';
  GET DIAGNOSTICS removed=ROW_COUNT;
  DELETE FROM gpante_chat.request_counters WHERE window_start<ts-INTERVAL '1 day';
  DELETE FROM gpante_chat.requests WHERE expires_at<ts-INTERVAL '1 day';
  UPDATE gpante_chat.model_calls SET state='burned' WHERE state='reserved' AND created_at<ts-INTERVAL '5 minutes';
  RETURN jsonb_build_object('removed',removed,'interrupted',interrupted);
END $$;

-- Only the dedicated admin DB role receives EXECUTE on this fixed-operation function.
CREATE FUNCTION gpante_chat.admin_api(input JSONB) RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
 SET search_path=pg_catalog,gpante_chat SET statement_timeout='8s' AS $$
DECLARE op TEXT:=input->>'operation'; ts TIMESTAMPTZ:=clock_timestamp(); cfg gpante_chat.policy;
  rows JSONB; has_more BOOLEAN; page_no INTEGER; start_at TIMESTAMPTZ; end_at TIMESTAMPTZ;
  query_text TEXT; wanted_status TEXT; visibility TEXT; sid TEXT;
BEGIN
  IF op='getPolicy' THEN RETURN jsonb_build_object('policy',gpante_chat.public_policy()); END IF;
  IF op='setPolicy' THEN
    IF coalesce(input->>'userDays','') NOT IN ('3','7','15','30') OR jsonb_typeof(input->'allowClear') IS DISTINCT FROM 'boolean'
      OR coalesce(input->>'revision','') !~ '^[0-9]{1,9}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
    PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
    SELECT * INTO STRICT cfg FROM gpante_chat.policy WHERE singleton FOR UPDATE;
    IF cfg.revision<>(input->>'revision')::integer THEN RETURN '{"error":"policy_conflict"}'::jsonb; END IF;
    -- Expired/cleared rows never return. Existing live rows may follow a longer policy.
    UPDATE gpante_chat.turns SET user_expires_at=created_at+make_interval(days=>(input->>'userDays')::integer)
      WHERE user_expires_at>ts AND hidden_at IS NULL;
    UPDATE gpante_chat.policy SET user_days=(input->>'userDays')::integer,allow_clear=(input->>'allowClear')::boolean,
      revision=revision+1,updated_at=ts WHERE singleton;
    RETURN jsonb_build_object('policy',gpante_chat.public_policy());
  END IF;
  IF op NOT IN ('listSessions','getSession') OR op IS NULL THEN RETURN '{"error":"invalid_operation"}'::jsonb; END IF;
  IF coalesce(input->>'page','1') !~ '^[0-9]{1,4}$' OR (input->>'page')::integer NOT BETWEEN 1 AND 1000 THEN
    RETURN '{"error":"invalid_input"}'::jsonb;
  END IF;
  page_no:=coalesce((input->>'page')::integer,1);
  sid:=input->>'sessionId';
  IF op='getSession' AND (sid IS NULL OR sid !~ '^[a-f0-9]{64}$') THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  IF op='getSession' THEN
    SELECT coalesce(jsonb_agg(payload ORDER BY created_at,request_id),'[]'::jsonb),count(*)>20 INTO rows,has_more FROM
      (SELECT t.created_at,t.request_id,jsonb_build_object('requestId',t.request_id,'createdAt',t.created_at,
        'archiveExpiresAt',t.created_at+INTERVAL '30 days','userExpiresAt',t.user_expires_at,'clearedAt',t.hidden_at,
        'status',CASE WHEN t.status='pending' AND NOT EXISTS(SELECT 1 FROM gpante_chat.requests r WHERE r.request_id=t.request_id AND NOT r.finished AND r.expires_at>ts) THEN 'interrupted' ELSE t.status END,
        'errorCode',t.error_code,'durationMs',t.duration_ms,
        'calls',CASE WHEN t.status='legacy' THEN NULL ELSE coalesce(t.calls,m.calls) END,
        'promptTokens',coalesce(t.prompt_tokens,m.prompt_tokens),'completionTokens',coalesce(t.completion_tokens,m.completion_tokens),
        'costNano',coalesce(t.cost_nano,m.cost_nano),
        'usageComplete',CASE WHEN t.status='legacy' THEN NULL ELSE coalesce(t.usage_complete,m.usage_complete) END,
        'messages',(SELECT coalesce(jsonb_agg(jsonb_build_object('role',CASE h.message->>'type' WHEN 'human' THEN 'user' ELSE 'assistant' END,
          'content',h.message->'data'->>'content') ORDER BY h.id),'[]'::jsonb) FROM gpante_chat.histories h WHERE h.turn_id=t.request_id)) AS payload
       FROM gpante_chat.turns t LEFT JOIN LATERAL
        (SELECT count(*) AS calls,sum(prompt_tokens) AS prompt_tokens,sum(completion_tokens) AS completion_tokens,
          sum(cost_nano) AS cost_nano,coalesce(bool_and(state='settled'),true) AS usage_complete
         FROM gpante_chat.model_calls WHERE request_id=t.request_id) m ON true
       WHERE t.session_id=sid AND t.created_at>ts-INTERVAL '30 days'
       ORDER BY t.created_at,t.request_id LIMIT 21 OFFSET (page_no-1)*20) q;
    RETURN jsonb_build_object('turns',rows-20,'hasMore',has_more,'page',page_no);
  END IF;
  query_text:=coalesce(input->>'search',''); wanted_status:=coalesce(input->>'status',''); visibility:=coalesce(input->>'visibility','');
  IF length(query_text)>100 OR wanted_status NOT IN ('','pending','success','error','interrupted','legacy')
    OR visibility NOT IN ('','visible','cleared','expired') THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  IF coalesce(input->>'from','')<>'' AND input->>'from' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  IF coalesce(input->>'to','')<>'' AND input->>'to' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  start_at:=coalesce(nullif(input->>'from','')::date::timestamp AT TIME ZONE 'UTC',ts-INTERVAL '30 days');
  end_at:=coalesce((nullif(input->>'to','')::date+1)::timestamp AT TIME ZONE 'UTC',ts);
  IF end_at<start_at THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  WITH eligible AS (
    SELECT t.*,CASE WHEN status='pending' AND NOT EXISTS(SELECT 1 FROM gpante_chat.requests r WHERE r.request_id=t.request_id AND NOT r.finished AND r.expires_at>ts) THEN 'interrupted' ELSE status END AS live_status
    FROM gpante_chat.turns t WHERE t.created_at>ts-INTERVAL '30 days' AND t.created_at>=start_at AND t.created_at<end_at
  ), hits AS (
    SELECT * FROM eligible t WHERE (wanted_status='' OR live_status=wanted_status)
      AND (visibility='' OR (visibility='visible' AND hidden_at IS NULL AND user_expires_at>ts)
        OR (visibility='cleared' AND hidden_at IS NOT NULL) OR (visibility='expired' AND hidden_at IS NULL AND user_expires_at<=ts))
      AND (query_text='' OR EXISTS(SELECT 1 FROM gpante_chat.histories h WHERE h.turn_id=t.request_id AND strpos(lower(h.message->'data'->>'content'),lower(query_text))>0))
  ), sessions AS (
    SELECT session_id,min(created_at) AS first_at,max(created_at) AS last_at,count(*) AS turn_count,
      (SELECT count(*) FROM gpante_chat.histories h JOIN eligible e ON e.request_id=h.turn_id WHERE e.session_id=hits.session_id) AS message_count,
      count(*) FILTER(WHERE live_status IN ('error','interrupted')) AS errors
    FROM hits GROUP BY session_id ORDER BY max(created_at) DESC,session_id LIMIT 21 OFFSET (page_no-1)*20
  ) SELECT coalesce(jsonb_agg(jsonb_build_object('sessionId',session_id,'firstAt',first_at,'lastAt',last_at,
      'turns',turn_count,'messages',message_count,'errors',errors) ORDER BY last_at DESC,session_id),'[]'::jsonb),count(*)>20 INTO rows,has_more FROM sessions;
  RETURN jsonb_build_object('sessions',rows-20,'hasMore',has_more,'page',page_no);
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow OR invalid_text_representation THEN
  RETURN '{"error":"invalid_input"}'::jsonb;
END $$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA gpante_chat FROM PUBLIC;
COMMIT;
