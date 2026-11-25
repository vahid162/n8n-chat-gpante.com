-- Apply after 009 as gpante_chat_app, with Main drained.
-- Keep the former budget value and all ledger rows for history and rollback.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_money_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying cost accounting migration';
  END IF;
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.answer_availability(ts TIMESTAMPTZ)
RETURNS TEXT LANGUAGE sql STABLE SET search_path=pg_catalog,gpante_chat AS $$
 SELECT coalesce((
   SELECT CASE WHEN NOT enabled THEN 'disabled'
     WHEN pricing_valid_until<=ts THEN 'pricing_review'
     ELSE 'ready' END
   FROM gpante_chat.runtime WHERE singleton
 ),'disabled')
$$;

CREATE OR REPLACE FUNCTION gpante_chat.reserve_call(rid TEXT,ordinal INTEGER)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE ts TIMESTAMPTZ; config gpante_chat.runtime;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtext('gpante_chat_money_v2'));
  ts:=clock_timestamp();
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
  INSERT INTO gpante_chat.model_calls(request_id,call_index,month,created_at)
    VALUES(rid,ordinal,date_trunc('month',ts AT TIME ZONE 'UTC')::date,ts);
  RETURN jsonb_build_object('allowed',true);
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.admin_api(input JSONB) RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,gpante_chat SET statement_timeout='8s' AS $$
DECLARE result JSONB; cfg gpante_chat.runtime; ts TIMESTAMPTZ:=clock_timestamp(); active BIGINT;
  reason TEXT; counts JSONB; total BIGINT; used_nano NUMERIC; settled_nano NUMERIC;
BEGIN
  result:=gpante_chat.admin_api_before_observability(input);
  IF input->>'operation' IS DISTINCT FROM 'getDiagnostics' OR result ? 'error' THEN RETURN result; END IF;
  SELECT * INTO cfg FROM gpante_chat.runtime WHERE singleton;
  active:=(result->'budget'->>'activeLeases')::bigint;
  reason:=CASE WHEN cfg.singleton IS NULL THEN 'unavailable' ELSE gpante_chat.answer_availability(ts) END;
  IF reason='ready' AND active>=2 THEN reason:='capacity'; END IF;
  IF cfg.singleton IS NOT NULL THEN
    -- Use one UTC month for both totals, including when the report runs at a month boundary.
    SELECT coalesce(sum(cost_nano),0),coalesce(sum(cost_nano) FILTER(WHERE state='settled'),0)
      INTO used_nano,settled_nano FROM gpante_chat.model_calls
      WHERE month=date_trunc('month',ts AT TIME ZONE 'UTC')::date;
    result:=result||jsonb_build_object('budget',result->'budget'||jsonb_build_object('budgetLimitEnabled',false,
      'monthlyBudgetNano',NULL::bigint,'usedNano',used_nano,'settledNano',settled_nano));
  END IF;
  SELECT coalesce(jsonb_agg(jsonb_build_object('date',day,'reason',r.reason,'count',refused) ORDER BY day DESC,r.reason),'[]'::jsonb),
    coalesce(sum(refused),0) INTO counts,total FROM gpante_chat.admission_refusals r WHERE day BETWEEN (ts AT TIME ZONE 'UTC')::date-6 AND (ts AT TIME ZONE 'UTC')::date;
  RETURN result||jsonb_build_object('readiness',jsonb_build_object('canAcceptNewTurn',reason='ready','reason',reason,
    'remainingNano',NULL::bigint,'checkedAt',ts,'pricingReviewSoon',cfg.pricing_valid_until>ts AND cfg.pricing_valid_until<=ts+INTERVAL '7 days'),
    'refusals',jsonb_build_object('windowDays',7,'calendarUtc',true,'total',total,'days',counts));
END $$;
COMMIT;
