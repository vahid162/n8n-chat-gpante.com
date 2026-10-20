-- Apply as the existing project_db administrator after creating the dedicated
-- gpante_chat_app LOGIN role with a generated password outside public Git.
-- This migration does not read or change woo2 or n8n internal tables.
BEGIN;
CREATE SCHEMA gpante_chat AUTHORIZATION gpante_chat_app;
SET LOCAL ROLE gpante_chat_app;

CREATE TABLE gpante_chat.histories (
  id SERIAL PRIMARY KEY,
  session_id VARCHAR(255) NOT NULL,
  message JSONB NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX histories_session_id_idx ON gpante_chat.histories(session_id, id);
CREATE INDEX histories_created_at_idx ON gpante_chat.histories(created_at);
CREATE TABLE gpante_chat.request_counters (
  counter_key TEXT PRIMARY KEY,
  window_start TIMESTAMPTZ NOT NULL,
  requests INTEGER NOT NULL CHECK (requests >= 0)
);
CREATE INDEX request_counters_expiry_idx ON gpante_chat.request_counters(window_start)
  WHERE counter_key <> 'global';

CREATE FUNCTION gpante_chat.reserve_request(session_key TEXT)
RETURNS TABLE(allowed BOOLEAN, reason TEXT, retry_after INTEGER)
LANGUAGE plpgsql SET search_path = pg_catalog, gpante_chat AS $$
DECLARE
  ts TIMESTAMPTZ := clock_timestamp();
  global_count INTEGER;
  global_start TIMESTAMPTZ;
  session_count INTEGER;
  session_start TIMESTAMPTZ;
BEGIN
  IF session_key IS NULL OR session_key !~ '^[A-Za-z0-9_-]{16,128}$' THEN
    RETURN QUERY SELECT false, 'invalid_session'::text, 0; RETURN;
  END IF;
  -- Engineering defaults for the inactive v1, not an agreed production SLA:
  -- 10 accepted requests/session/60s, 2000 globally/24h, history kept 7 days.
  -- ponytail: serialize this short reservation; use ordered row locks if
  -- measured reservation contention warrants it.
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_reservation_v1'));
  DELETE FROM gpante_chat.histories WHERE created_at < ts - INTERVAL '7 days';
  DELETE FROM gpante_chat.request_counters
    WHERE counter_key <> 'global' AND window_start < ts - INTERVAL '7 days';
  INSERT INTO gpante_chat.request_counters VALUES ('global', ts, 0)
    ON CONFLICT DO NOTHING;
  UPDATE gpante_chat.request_counters SET window_start=ts, requests=0
    WHERE counter_key='global' AND window_start <= ts - INTERVAL '24 hours';
  SELECT requests,window_start INTO global_count,global_start
    FROM gpante_chat.request_counters WHERE counter_key='global';
  IF global_count >= 2000 THEN
    RETURN QUERY SELECT false,'global_limit'::text,
      greatest(1,ceil(extract(epoch FROM global_start+INTERVAL '24 hours'-ts))::integer); RETURN;
  END IF;
  INSERT INTO gpante_chat.request_counters VALUES ('session:' || session_key, ts, 0)
    ON CONFLICT DO NOTHING;
  UPDATE gpante_chat.request_counters SET window_start=ts, requests=0
    WHERE counter_key='session:' || session_key AND window_start <= ts - INTERVAL '60 seconds';
  SELECT requests,window_start INTO session_count,session_start
    FROM gpante_chat.request_counters WHERE counter_key='session:' || session_key;
  IF session_count >= 10 THEN
    RETURN QUERY SELECT false,'session_limit'::text,
      greatest(1,ceil(extract(epoch FROM session_start+INTERVAL '60 seconds'-ts))::integer); RETURN;
  END IF;
  UPDATE gpante_chat.request_counters SET requests=requests+1
    WHERE counter_key IN ('global','session:' || session_key);
  RETURN QUERY SELECT true,'reserved'::text,0;
END;
$$;
REVOKE ALL ON FUNCTION gpante_chat.reserve_request(TEXT) FROM PUBLIC;
COMMIT;
