-- Verify the same pre-003 data after all additive migrations; remove fixtures only.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DO $$
DECLARE before_histories JSONB; before_ledger JSONB; after_histories JSONB; after_ledger JSONB;
BEGIN
  ASSERT current_database()='gpante_chat_archive_test','isolated database required';
  SELECT histories,ledger INTO before_histories,before_ledger FROM gpante_chat.legacy_migration_fixture;
  SELECT jsonb_agg(to_jsonb(h)-'turn_id' ORDER BY id) INTO after_histories FROM gpante_chat.histories h;
  SELECT jsonb_agg(to_jsonb(m) ORDER BY request_id,call_index) INTO after_ledger FROM gpante_chat.model_calls m;
  ASSERT after_histories=before_histories,'migration preserves original text, IDs, session and timestamp';
  ASSERT after_ledger=before_ledger,'migration preserves every financial ledger field';
  ASSERT (SELECT count(*)=3 FROM gpante_chat.turns),'legacy rows are not assigned invented turn pairs';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.turns t JOIN gpante_chat.histories h ON h.turn_id=t.request_id
    WHERE t.request_id<>'legacy:'||h.id OR t.status<>'legacy' OR t.created_at<>h.created_at
      OR t.user_expires_at<>h.created_at+INTERVAL '30 days' OR t.calls IS NOT NULL
      OR t.duration_ms IS NOT NULL OR t.usage_complete IS NOT NULL),'legacy metadata remains truthful';
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.send_guards),'legacy messages without send IDs get no invented guards';
END $$;
DELETE FROM gpante_chat.turns;
DELETE FROM gpante_chat.model_calls WHERE request_id='legacy-migration-fixture';
DROP TABLE gpante_chat.legacy_migration_fixture;
COMMIT;
