-- Additive migration for the existing project_db gpante_chat schema. Apply once.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DROP FUNCTION gpante_chat.reserve_request(TEXT);
CREATE TABLE gpante_chat.runtime (
  singleton BOOLEAN PRIMARY KEY DEFAULT true CHECK(singleton),
  monthly_budget_nano BIGINT NOT NULL DEFAULT 2000000000 CHECK(monthly_budget_nano >= 0),
  pricing_valid_until TIMESTAMPTZ NOT NULL DEFAULT '2026-11-18T00:00:00Z',
  enabled BOOLEAN NOT NULL DEFAULT true
);
INSERT INTO gpante_chat.runtime DEFAULT VALUES;
CREATE TABLE gpante_chat.requests (
  request_id TEXT PRIMARY KEY,
  session_id TEXT NOT NULL,
  client_id TEXT NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  finished BOOLEAN NOT NULL DEFAULT false
);
CREATE INDEX requests_active_idx ON gpante_chat.requests(expires_at) WHERE NOT finished;
CREATE TABLE gpante_chat.model_calls (
  request_id TEXT NOT NULL,
  call_index INTEGER NOT NULL CHECK(call_index BETWEEN 1 AND 4),
  month DATE NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  cost_nano BIGINT NOT NULL DEFAULT 320000000 CHECK(cost_nano >= 0),
  state TEXT NOT NULL DEFAULT 'reserved' CHECK(state IN ('reserved','settled','burned')),
  prompt_tokens INTEGER,
  completion_tokens INTEGER,
  PRIMARY KEY(request_id,call_index)
);
CREATE INDEX model_calls_month_idx ON gpante_chat.model_calls(month);

CREATE FUNCTION gpante_chat.admit(sid TEXT,cid TEXT,requested_action TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); rid TEXT; history JSONB; n INTEGER;
BEGIN
  IF sid IS NULL OR cid IS NULL OR sid !~ '^[a-f0-9]{64}$' OR cid !~ '^[a-f0-9]{64}$'
    OR requested_action IS NULL OR requested_action NOT IN ('sendMessage','loadPreviousSession','resetSession') THEN
    RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
  END IF;
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
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
    DELETE FROM gpante_chat.histories WHERE session_id=sid;
    RETURN jsonb_build_object('allowed',true,'ok',true);
  END IF;
  SELECT coalesce(jsonb_agg(jsonb_build_object('role',CASE message->>'type' WHEN 'human' THEN 'user' ELSE 'assistant' END,
    'content',message->'data'->>'content') ORDER BY id),'[]'::jsonb) INTO history
    FROM (SELECT * FROM gpante_chat.histories WHERE session_id=sid AND created_at>=ts-INTERVAL '30 days'
      AND message->>'type' IN ('human','ai') ORDER BY id DESC LIMIT 12) h;
  IF requested_action='loadPreviousSession' THEN
    RETURN jsonb_build_object('allowed',true,'messages',history);
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
  RETURN jsonb_build_object('allowed',true,'requestId',rid,'messages',history);
END $$;

CREATE FUNCTION gpante_chat.reserve_call(rid TEXT,ordinal INTEGER)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); m DATE:=date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date;
  used BIGINT; config gpante_chat.runtime;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_money_v2'));
  SELECT * INTO config FROM gpante_chat.runtime WHERE singleton;
  IF NOT FOUND OR NOT config.enabled OR config.pricing_valid_until<=ts THEN RETURN jsonb_build_object('allowed',false,'reason','pricing_review'); END IF;
  IF ordinal IS NULL OR ordinal NOT BETWEEN 1 AND 4 OR NOT EXISTS(SELECT 1 FROM gpante_chat.requests
    WHERE request_id=rid AND NOT finished AND expires_at>ts) THEN
    RETURN jsonb_build_object('allowed',false,'reason','expired_request');
  END IF;
  IF EXISTS(SELECT 1 FROM gpante_chat.model_calls WHERE request_id=rid AND call_index=ordinal)
    OR (ordinal>1 AND NOT EXISTS(SELECT 1 FROM gpante_chat.model_calls WHERE request_id=rid AND call_index=ordinal-1 AND state='settled')) THEN
    RETURN jsonb_build_object('allowed',false,'reason','duplicate_or_unsettled');
  END IF;
  SELECT coalesce(sum(cost_nano),0) INTO used FROM gpante_chat.model_calls WHERE month=m;
  IF used+320000000>config.monthly_budget_nano THEN RETURN jsonb_build_object('allowed',false,'reason','monthly_budget'); END IF;
  INSERT INTO gpante_chat.model_calls(request_id,call_index,month) VALUES(rid,ordinal,m);
  RETURN jsonb_build_object('allowed',true);
END $$;

CREATE FUNCTION gpante_chat.settle_call(rid TEXT,ordinal INTEGER,prompt_count INTEGER,completion_count INTEGER,total_count INTEGER)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE cost BIGINT; valid BOOLEAN;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_money_v2'));
  valid:=prompt_count IS NOT NULL AND completion_count IS NOT NULL AND total_count IS NOT NULL
    AND prompt_count BETWEEN 0 AND 1048576 AND completion_count BETWEEN 0 AND 1536
    AND total_count=prompt_count+completion_count;
  cost:=CASE WHEN valid THEN prompt_count::bigint*300+completion_count::bigint*1200 ELSE 320000000 END;
  UPDATE gpante_chat.model_calls SET cost_nano=cost,state=CASE WHEN valid THEN 'settled' ELSE 'burned' END,
    prompt_tokens=CASE WHEN valid THEN prompt_count END,completion_tokens=CASE WHEN valid THEN completion_count END
    WHERE request_id=rid AND call_index=ordinal AND state='reserved';
  IF NOT FOUND THEN RETURN jsonb_build_object('confirmed',false); END IF;
  RETURN jsonb_build_object('confirmed',valid);
END $$;

CREATE FUNCTION gpante_chat.finish_request(rid TEXT,user_text TEXT,assistant_text TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE sid TEXT;
BEGIN
  -- The same session lock as admission serializes reset with final persistence.
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  UPDATE gpante_chat.requests SET finished=true WHERE request_id=rid AND NOT finished AND expires_at>clock_timestamp()
    RETURNING session_id INTO sid;
  IF sid IS NULL THEN RETURN jsonb_build_object('saved',false); END IF;
  IF user_text IS NOT NULL AND assistant_text IS NOT NULL AND length(user_text) BETWEEN 1 AND 4000
    AND length(assistant_text) BETWEEN 1 AND 8000 THEN
    INSERT INTO gpante_chat.histories(session_id,message) VALUES
      (sid,jsonb_build_object('type','human','data',jsonb_build_object('content',user_text))),
      (sid,jsonb_build_object('type','ai','data',jsonb_build_object('content',assistant_text)));
  END IF;
  RETURN jsonb_build_object('saved',true);
END $$;

CREATE FUNCTION gpante_chat.cleanup() RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE removed INTEGER;
BEGIN
  DELETE FROM gpante_chat.histories WHERE created_at<clock_timestamp()-INTERVAL '30 days';
  GET DIAGNOSTICS removed=ROW_COUNT;
  DELETE FROM gpante_chat.request_counters WHERE window_start<clock_timestamp()-INTERVAL '1 day';
  DELETE FROM gpante_chat.requests WHERE expires_at<clock_timestamp()-INTERVAL '1 day';
  -- Monetary reservations never expire or refund: unknown provider billing stays charged.
  UPDATE gpante_chat.model_calls SET state='burned' WHERE state='reserved' AND created_at<clock_timestamp()-INTERVAL '5 minutes';
  RETURN jsonb_build_object('removed',removed);
END $$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA gpante_chat FROM PUBLIC;
COMMIT;
