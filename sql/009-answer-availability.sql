-- Apply after 008 as gpante_chat_app, with Main drained.
-- Replace admission functions only and extend the existing text-free refusal counters.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying answer availability migration';
  END IF;
END $$;
ALTER TABLE gpante_chat.admission_refusals
  DROP CONSTRAINT admission_refusals_reason_check,
  ADD CONSTRAINT admission_refusals_reason_check
    CHECK(reason IN ('rate_limit','session_busy','capacity','client_upgrade','prior_request_expired','prior_request_failed','invalid_input',
      'pricing_review','monthly_budget','disabled'));

-- This is a coarse read-only snapshot, using the same UTC month and reserve threshold as reserve_call.
CREATE OR REPLACE FUNCTION gpante_chat.answer_availability(ts TIMESTAMPTZ)
RETURNS TEXT LANGUAGE sql STABLE SET search_path=pg_catalog,gpante_chat AS $$
 SELECT coalesce((
   SELECT CASE WHEN NOT enabled THEN 'disabled'
     WHEN pricing_valid_until<=ts THEN 'pricing_review'
     WHEN monthly_budget_nano < 320000000 + (
       SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls
       WHERE month=date_trunc('month',ts AT TIME ZONE 'UTC')::date
     ) THEN 'monthly_budget'
     ELSE 'ready' END
   FROM gpante_chat.runtime WHERE singleton
 ),'disabled')
$$;

CREATE OR REPLACE FUNCTION gpante_chat.admit_v3(sid TEXT,cid TEXT,requested_action TEXT,user_text TEXT,contract INTEGER)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); rid TEXT; history JSONB; n INTEGER; cfg gpante_chat.policy; availability TEXT;
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
  -- The per-call reservation remains authoritative if availability changes after admission.
  availability:=gpante_chat.answer_availability(clock_timestamp());
  IF availability<>'ready' THEN
    RETURN jsonb_build_object('allowed',false,'reason',availability);
  END IF;
  rid:=md5(random()::text||clock_timestamp()::text||sid);
  INSERT INTO gpante_chat.requests VALUES(rid,sid,cid,ts+INTERVAL '3 minutes',false);
  INSERT INTO gpante_chat.turns(request_id,session_id,created_at,user_expires_at,status)
    VALUES(rid,sid,ts,ts+make_interval(days=>cfg.user_days),'pending');
  INSERT INTO gpante_chat.histories(session_id,turn_id,message,created_at)
    VALUES(sid,rid,jsonb_build_object('type','human','data',jsonb_build_object('content',user_text)),ts);
  RETURN jsonb_build_object('allowed',true,'requestId',rid,'messages',history,'policy',gpante_chat.public_policy(),
    'expiresAt',ts+make_interval(days=>cfg.user_days));
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.admit_v4(sid TEXT,cid TEXT,requested_action TEXT,user_text TEXT,contract INTEGER,
  requested_send_id TEXT,history_cursor TEXT,feedback_request TEXT,feedback_value TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE result JSONB; today DATE:=(clock_timestamp() AT TIME ZONE 'UTC')::date;
BEGIN
  result:=gpante_chat.admit_v4_before_observability(sid,cid,requested_action,user_text,contract,
    requested_send_id,history_cursor,feedback_request,feedback_value);
  -- Metadata failure must never change admission or retain a message/session identifier.
  BEGIN
    IF requested_action='sendMessage' AND sid ~ '^[a-f0-9]{64}$' AND cid ~ '^[a-f0-9]{64}$'
      AND result->>'allowed'='false' AND result->>'reason' IN
        ('rate_limit','session_busy','capacity','client_upgrade','prior_request_expired','prior_request_failed','invalid_input',
         'pricing_review','monthly_budget','disabled') THEN
      INSERT INTO gpante_chat.admission_refusals(day,reason,refused) VALUES(today,result->>'reason',1)
        ON CONFLICT(day,reason) DO UPDATE SET refused=gpante_chat.admission_refusals.refused+1;
      DELETE FROM gpante_chat.admission_refusals WHERE day<today-6;
    END IF;
  EXCEPTION WHEN OTHERS THEN NULL;
  END;
  IF requested_action='loadPreviousSession' AND result->>'allowed'='true' THEN
    result:=result||jsonb_build_object('answerAvailability',gpante_chat.answer_availability(clock_timestamp()));
  END IF;
  RETURN result;
END $$;

REVOKE ALL ON FUNCTION gpante_chat.answer_availability(TIMESTAMPTZ) FROM PUBLIC,gpante_chat_admin;
COMMIT;
