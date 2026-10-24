-- Run only on the fresh isolated database after 002 and before 003.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DO $$ BEGIN
  ASSERT current_database()='gpante_chat_archive_test','isolated database required';
  ASSERT to_regclass('gpante_chat.turns') IS NULL,'pre-archive schema required';
END $$;
INSERT INTO gpante_chat.histories(session_id,message,created_at) VALUES
  (repeat('4',64),'{"type":"human","data":{"content":"legacy question"}}',clock_timestamp()-INTERVAL '2 days'),
  (repeat('4',64),'{"type":"ai","data":{"content":"legacy answer"}}',clock_timestamp()-INTERVAL '2 days'),
  (repeat('5',64),'{"type":"human","data":{"content":"already expired"}}',clock_timestamp()-INTERVAL '31 days');
INSERT INTO gpante_chat.model_calls(request_id,call_index,month,cost_nano,state,prompt_tokens,completion_tokens)
  VALUES('legacy-migration-fixture',1,date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date,42000,'settled',100,10);
CREATE TABLE gpante_chat.legacy_migration_fixture AS
  SELECT (SELECT jsonb_agg(to_jsonb(h) ORDER BY id) FROM gpante_chat.histories h) AS histories,
    (SELECT jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index) FROM gpante_chat.model_calls m) AS ledger;
COMMIT;
