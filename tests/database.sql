-- Synthetic fixtures only; the entire test is rolled back.
BEGIN;
SET LOCAL ROLE gpante_chat_app;
DELETE FROM gpante_chat.request_counters;
DO $$
DECLARE verdict RECORD; i INTEGER; before_count INTEGER;
BEGIN
  SELECT * INTO verdict FROM gpante_chat.reserve_request('invalid');
  ASSERT NOT verdict.allowed AND verdict.reason='invalid_session';
  FOR i IN 1..10 LOOP
    SELECT * INTO verdict FROM gpante_chat.reserve_request('fixture_session_123456');
    ASSERT verdict.allowed;
  END LOOP;
  SELECT * INTO verdict FROM gpante_chat.reserve_request('fixture_session_123456');
  ASSERT NOT verdict.allowed AND verdict.reason='session_limit' AND verdict.retry_after > 0;
  SELECT * INTO verdict FROM gpante_chat.reserve_request('another_session_123456');
  ASSERT verdict.allowed;
  ASSERT (SELECT requests=11 FROM gpante_chat.request_counters WHERE counter_key='global');
  UPDATE gpante_chat.request_counters SET window_start=clock_timestamp()-INTERVAL '61 seconds'
    WHERE counter_key='session:fixture_session_123456';
  SELECT * INTO verdict FROM gpante_chat.reserve_request('fixture_session_123456');
  ASSERT verdict.allowed;
  ASSERT (SELECT requests=1 FROM gpante_chat.request_counters WHERE counter_key='session:fixture_session_123456');

  INSERT INTO gpante_chat.histories(session_id,message,created_at) VALUES
    ('fixture_a','{"type":"human","data":{"content":"old"}}',clock_timestamp()-INTERVAL '8 days'),
    ('fixture_b','{"type":"human","data":{"content":"recent"}}',clock_timestamp());
  PERFORM gpante_chat.reserve_request('retention_test_123456');
  ASSERT NOT EXISTS(SELECT 1 FROM gpante_chat.histories WHERE session_id='fixture_a');
  ASSERT (SELECT count(*)=1 FROM gpante_chat.histories WHERE session_id='fixture_b');

  UPDATE gpante_chat.request_counters SET requests=2000 WHERE counter_key='global';
  SELECT count(*) INTO before_count FROM gpante_chat.request_counters;
  SELECT * INTO verdict FROM gpante_chat.reserve_request('new_rejected_session_123456');
  ASSERT NOT verdict.allowed AND verdict.reason='global_limit';
  ASSERT (SELECT count(*)=before_count FROM gpante_chat.request_counters);
  UPDATE gpante_chat.request_counters SET window_start=clock_timestamp()-INTERVAL '25 hours'
    WHERE counter_key='global';
  SELECT * INTO verdict FROM gpante_chat.reserve_request('new_rejected_session_123456');
  ASSERT verdict.allowed;
  ASSERT (SELECT requests=1 FROM gpante_chat.request_counters WHERE counter_key='global');
END;
$$;
ROLLBACK;
