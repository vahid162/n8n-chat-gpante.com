-- Apply once as gpante_chat_app on project_db after 003, with Main drained.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying conversation migration';
  END IF;
END $$;
ALTER TABLE gpante_chat.turns ADD COLUMN client_message_id TEXT
  CHECK(client_message_id IS NULL OR client_message_id ~ '^[a-f0-9]{32}$');
ALTER TABLE gpante_chat.turns ADD COLUMN user_feedback TEXT
  CHECK(user_feedback IN ('helpful','unhelpful'));
CREATE UNIQUE INDEX turns_client_message_idx ON gpante_chat.turns(session_id,client_message_id)
  WHERE client_message_id IS NOT NULL;

-- Keep v3 unchanged for old tabs and the model's six-turn context.
CREATE FUNCTION gpante_chat.admit_v4(sid TEXT,cid TEXT,requested_action TEXT,user_text TEXT,contract INTEGER,
  requested_send_id TEXT,history_cursor TEXT,feedback_request TEXT,feedback_value TEXT)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ:=clock_timestamp(); result JSONB; prior gpante_chat.turns; original TEXT;
  answer TEXT; rows JSONB; more BOOLEAN; cursor_id BIGINT; reset_at TIMESTAMPTZ;
BEGIN
  IF requested_action IS NULL OR requested_action NOT IN ('sendMessage','loadPreviousSession','resetSession','feedback')
    OR (requested_send_id IS NOT NULL AND requested_send_id !~ '^[a-f0-9]{32}$')
    OR (history_cursor IS NOT NULL AND history_cursor !~ '^[1-9][0-9]{0,17}$')
    OR (requested_action='feedback' AND (contract IS DISTINCT FROM 2 OR feedback_request IS NULL
      OR feedback_request !~ '^[a-f0-9]{32}$' OR feedback_value IS NULL OR feedback_value NOT IN ('helpful','unhelpful'))) THEN
    RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
  END IF;
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
  IF requested_action='sendMessage' AND requested_send_id IS NOT NULL THEN
    SELECT * INTO prior FROM gpante_chat.turns WHERE session_id=sid AND client_message_id=requested_send_id;
  END IF;
  -- Every operation, including replay and feedback, passes the established IP gate.
  result:=gpante_chat.admit_v3(sid,cid,
    CASE WHEN requested_action='feedback' OR prior.request_id IS NOT NULL THEN 'loadPreviousSession' ELSE requested_action END,
    user_text,contract);
  IF result->>'allowed' IS DISTINCT FROM 'true' THEN RETURN result; END IF;
  IF prior.request_id IS NOT NULL THEN
    IF contract IS DISTINCT FROM 2 OR user_text IS NULL OR length(user_text) NOT BETWEEN 1 AND 4000 THEN
      RETURN jsonb_build_object('allowed',false,'reason','invalid_input');
    END IF;
    SELECT message->'data'->>'content' INTO original FROM gpante_chat.histories
      WHERE turn_id=prior.request_id AND message->>'type'='human' ORDER BY id LIMIT 1;
    IF original IS DISTINCT FROM user_text THEN RETURN jsonb_build_object('allowed',false,'reason','invalid_input'); END IF;
    IF prior.hidden_at IS NOT NULL OR prior.created_at<=ts-INTERVAL '30 days' OR prior.user_expires_at<=ts THEN
      RETURN jsonb_build_object('allowed',false,'reason','prior_request_expired');
    END IF;
    IF prior.status<>'success' THEN
      RETURN jsonb_build_object('allowed',false,'reason','prior_request_failed');
    END IF;
    SELECT message->'data'->>'content' INTO answer FROM gpante_chat.histories
      WHERE turn_id=prior.request_id AND message->>'type'='ai' ORDER BY id LIMIT 1;
    IF answer IS NULL THEN RETURN jsonb_build_object('allowed',false,'reason','prior_request_failed'); END IF;
    RETURN jsonb_build_object('allowed',true,'replay',true,'requestId',prior.request_id,'output',answer,
      'expiresAt',prior.user_expires_at,'policy',result->'policy','feedback',prior.user_feedback);
  END IF;
  IF requested_action='sendMessage' THEN
    IF requested_send_id IS NOT NULL THEN
      UPDATE gpante_chat.turns SET client_message_id=requested_send_id WHERE request_id=result->>'requestId';
    END IF;
    RETURN result;
  END IF;
  IF requested_action='feedback' THEN
    UPDATE gpante_chat.turns SET user_feedback=feedback_value WHERE request_id=feedback_request AND session_id=sid
      AND status='success' AND hidden_at IS NULL AND created_at>ts-INTERVAL '30 days' AND user_expires_at>ts;
    IF NOT FOUND THEN RETURN jsonb_build_object('allowed',false,'reason','feedback_unavailable'); END IF;
    RETURN jsonb_build_object('allowed',true,'ok',true,'requestId',feedback_request,'feedback',feedback_value,'policy',result->'policy');
  END IF;
  IF requested_action='resetSession' THEN RETURN result; END IF;
  SELECT max(hidden_at) INTO reset_at FROM gpante_chat.turns WHERE session_id=sid;
  WITH eligible AS (
    SELECT t.request_id,t.user_expires_at,t.user_feedback,min(h.id) AS cursor
      FROM gpante_chat.turns t JOIN gpante_chat.histories h ON h.turn_id=t.request_id
      WHERE t.session_id=sid AND t.created_at>ts-INTERVAL '30 days' AND t.user_expires_at>ts AND t.hidden_at IS NULL
        AND t.status IN ('success','legacy') AND h.message->>'type' IN ('human','ai')
      GROUP BY t.request_id HAVING history_cursor IS NULL OR min(h.id)<history_cursor::bigint
      ORDER BY cursor DESC LIMIT 7
  ), selected AS (SELECT * FROM eligible ORDER BY cursor DESC LIMIT 6)
  SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('role',CASE h.message->>'type' WHEN 'human' THEN 'user' ELSE 'assistant' END,
      'content',h.message->'data'->>'content','expiresAt',s.user_expires_at,'requestId',s.request_id,'feedback',s.user_feedback)
      ORDER BY h.id) FROM selected s JOIN gpante_chat.histories h ON h.turn_id=s.request_id
      WHERE h.message->>'type' IN ('human','ai')),'[]'::jsonb),
    (SELECT count(*)>6 FROM eligible),(SELECT min(cursor) FROM selected) INTO rows,more,cursor_id;
  RETURN jsonb_build_object('allowed',true,'messages',rows,'policy',result->'policy','hasMore',more,
    'nextCursor',CASE WHEN more THEN cursor_id::text ELSE NULL END,'historyResetAt',reset_at);
END $$;
REVOKE ALL ON FUNCTION gpante_chat.admit_v4(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT) FROM PUBLIC;
COMMIT;
