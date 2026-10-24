-- Apply once as gpante_chat_app after 005, with Main drained.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying send guard migration';
  END IF;
END $$;

-- Text-free identifiers; five minutes cover the bounded backend response before cookie renewal.
CREATE TABLE gpante_chat.send_guards (
  session_id TEXT NOT NULL CHECK(session_id ~ '^[a-f0-9]{64}$'),
  client_message_id TEXT NOT NULL CHECK(client_message_id ~ '^[a-f0-9]{32}$'),
  idle_expires_at TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(session_id,client_message_id)
);
CREATE INDEX send_guards_expiry_idx ON gpante_chat.send_guards(idle_expires_at);
INSERT INTO gpante_chat.send_guards
  SELECT session_id,client_message_id,clock_timestamp()+INTERVAL '30 days 5 minutes'
  FROM gpante_chat.turns WHERE client_message_id IS NOT NULL;

ALTER FUNCTION gpante_chat.admit_v4(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT)
  RENAME TO admit_v4_before_send_guards;
CREATE FUNCTION gpante_chat.admit_v4(sid TEXT,cid TEXT,requested_action TEXT,user_text TEXT,contract INTEGER,
  requested_send_id TEXT,history_cursor TEXT,feedback_request TEXT,feedback_value TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); result JSONB; deleted_send BOOLEAN;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  -- A trusted request renews the same session's guards, including temporary refusals.
  IF sid ~ '^[a-f0-9]{64}$' AND cid ~ '^[a-f0-9]{64}$' THEN
    UPDATE gpante_chat.send_guards SET idle_expires_at=ts+INTERVAL '30 days 5 minutes' WHERE session_id=sid;
  END IF;
  deleted_send:=requested_action='sendMessage' AND requested_send_id IS NOT NULL
    AND EXISTS(SELECT 1 FROM gpante_chat.send_guards WHERE session_id=sid AND client_message_id=requested_send_id)
    AND NOT EXISTS(SELECT 1 FROM gpante_chat.turns WHERE session_id=sid AND client_message_id=requested_send_id);
  result:=gpante_chat.admit_v4_before_send_guards(sid,cid,
    CASE WHEN deleted_send THEN 'loadPreviousSession' ELSE requested_action END,
    user_text,contract,requested_send_id,history_cursor,feedback_request,feedback_value);
  IF result->>'allowed' IS DISTINCT FROM 'true' THEN RETURN result; END IF;
  IF deleted_send THEN
    IF contract IS DISTINCT FROM 2 OR user_text IS NULL OR length(user_text) NOT BETWEEN 1 AND 4000 THEN
      RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
    END IF;
    RETURN jsonb_build_object('allowed',false,'reason','prior_request_expired');
  END IF;
  IF requested_action='sendMessage' AND requested_send_id IS NOT NULL THEN
    INSERT INTO gpante_chat.send_guards VALUES(sid,requested_send_id,ts+INTERVAL '30 days 5 minutes')
      ON CONFLICT(session_id,client_message_id) DO UPDATE SET idle_expires_at=EXCLUDED.idle_expires_at;
  END IF;
  RETURN result;
END $$;
REVOKE ALL ON FUNCTION gpante_chat.admit_v4(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT) FROM PUBLIC;

ALTER FUNCTION gpante_chat.cleanup() RENAME TO cleanup_before_send_guards;
CREATE FUNCTION gpante_chat.cleanup() RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE result JSONB; removed INTEGER;
BEGIN
  result:=gpante_chat.cleanup_before_send_guards();
  DELETE FROM gpante_chat.send_guards WHERE idle_expires_at<=clock_timestamp();
  GET DIAGNOSTICS removed=ROW_COUNT;
  RETURN result||jsonb_build_object('expiredSendGuardsRemoved',removed);
END $$;
REVOKE ALL ON FUNCTION gpante_chat.cleanup() FROM PUBLIC;
COMMIT;
