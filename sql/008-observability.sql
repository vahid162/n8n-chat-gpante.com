-- Apply as gpante_chat_app after 007, with Main drained. Safe to rerun this migration only.
-- Text-free daily counts and optional diagnostic fields; settings, history and money are preserved.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
DO $$ BEGIN
  IF EXISTS(SELECT 1 FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Drain Main before applying observability migration';
  END IF;
END $$;
CREATE TABLE IF NOT EXISTS gpante_chat.admission_refusals (
  day DATE NOT NULL,
  reason TEXT NOT NULL CHECK(reason IN ('rate_limit','session_busy','capacity','client_upgrade','prior_request_expired','prior_request_failed','invalid_input')),
  refused BIGINT NOT NULL DEFAULT 0 CHECK(refused>=0),
  PRIMARY KEY(day,reason)
);
DO $$ BEGIN
  IF to_regprocedure('gpante_chat.admit_v4_before_observability(text,text,text,text,integer,text,text,text,text)') IS NULL THEN
    ALTER FUNCTION gpante_chat.admit_v4(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT) RENAME TO admit_v4_before_observability;
  END IF;
  IF to_regprocedure('gpante_chat.admin_api_before_observability(jsonb)') IS NULL THEN
    ALTER FUNCTION gpante_chat.admin_api(JSONB) RENAME TO admin_api_before_observability;
  END IF;
  IF to_regprocedure('gpante_chat.cleanup_before_observability()') IS NULL THEN
    ALTER FUNCTION gpante_chat.cleanup() RENAME TO cleanup_before_observability;
  END IF;
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
        ('rate_limit','session_busy','capacity','client_upgrade','prior_request_expired','prior_request_failed','invalid_input') THEN
      INSERT INTO gpante_chat.admission_refusals(day,reason,refused) VALUES(today,result->>'reason',1)
        ON CONFLICT(day,reason) DO UPDATE SET refused=gpante_chat.admission_refusals.refused+1;
      DELETE FROM gpante_chat.admission_refusals WHERE day<today-6;
    END IF;
  EXCEPTION WHEN OTHERS THEN NULL;
  END;
  RETURN result;
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.finish_v4(rid TEXT,assistant_text TEXT,outcome TEXT,code TEXT,diagnostics JSONB)
RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE result JSONB; events JSONB:='[]'; item JSONB; event JSONB; hits JSONB; hit JSONB; filters JSONB; key TEXT;
BEGIN
  result:=gpante_chat.finish_v3(rid,assistant_text,outcome,code);
  IF result->>'saved' IS DISTINCT FROM 'true' THEN RETURN result; END IF;
  -- Diagnostics are optional: invalid metadata must not roll back a saved answer or accounting.
  BEGIN
    IF jsonb_typeof(diagnostics)='array' AND octet_length(diagnostics::text)<=16384 THEN
      FOR item IN SELECT value FROM jsonb_array_elements(diagnostics) LIMIT 12 LOOP
        IF item->>'event'='rejection' AND item->>'code' ~ '^[a-z_]{1,64}$' THEN
          event:=jsonb_build_object('event','rejection','code',item->>'code');
        ELSIF item->>'event'='execution' AND item->>'workflowVersion' ~ '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$'
          AND item->>'sourceRevision' ~ '^[a-f0-9]{64}$'
          AND jsonb_typeof(item->'modelRounds')='number' AND item->>'modelRounds' ~ '^[0-4]$'
          AND jsonb_typeof(item->'toolCalls')='number' AND item->>'toolCalls' ~ '^[0-3]$' THEN
          event:=jsonb_build_object('event','execution','workflowVersion',item->>'workflowVersion',
            'sourceRevision',item->>'sourceRevision','modelRounds',(item->>'modelRounds')::integer,'toolCalls',(item->>'toolCalls')::integer);
        ELSIF item->>'event'='source' AND item->>'tool' IN ('Product_Search','Product_Details','Knowledge_Search','Knowledge_Read','Category_Search') THEN
          filters:='{}'; hits:='[]';
          FOREACH key IN ARRAY ARRAY['format','material','thickness','order','category'] LOOP
            IF jsonb_typeof(item->'filters'->key)='string' THEN
              filters:=filters||jsonb_build_object(key,gpante_chat.diagnostic_text(item->'filters'->>key,40));
            END IF;
          END LOOP;
          IF jsonb_typeof(item->'results')='array' THEN
            FOR hit IN SELECT value FROM jsonb_array_elements(item->'results') LIMIT 5 LOOP
              IF hit->>'id' ~ '^[a-z0-9_-]{1,80}$' THEN
                hits:=hits||jsonb_build_array(jsonb_build_object('id',hit->>'id','rank',jsonb_array_length(hits)+1,
                  'kind',CASE WHEN hit->>'kind' IN ('product','page','post','article','faq') THEN hit->>'kind' ELSE 'unknown' END));
              END IF;
            END LOOP;
          END IF;
          event:=jsonb_build_object('event','source','tool',item->>'tool',
            'source',CASE WHEN item->>'source' IN ('products','articles','site','faq','guides','categories') THEN item->>'source' ELSE 'unknown' END,
            'status',CASE WHEN item->>'status' ~ '^[a-z_]{1,40}$' THEN item->>'status' ELSE 'unknown' END,
            'query',gpante_chat.diagnostic_text(CASE WHEN jsonb_typeof(item->'query')='string' THEN item->>'query' ELSE '' END,120),'filters',filters,'results',hits,
            'coverage',CASE WHEN item->>'coverage' IN ('complete','incomplete') THEN item->>'coverage' ELSE NULL END);
          IF jsonb_typeof(item->'targetId')='number' AND item->>'targetId' ~ '^[1-9][0-9]{0,15}$'
            AND (item->>'targetId')::bigint<=9007199254740991 AND item->>'tool' IN ('Product_Details','Knowledge_Read') THEN
            event:=event||jsonb_build_object('targetId',(item->>'targetId')::bigint);
          END IF;
        ELSE CONTINUE;
        END IF;
        IF octet_length((events||jsonb_build_array(event))::text)<=8192 THEN events:=events||jsonb_build_array(event); END IF;
      END LOOP;
    END IF;
    INSERT INTO gpante_chat.search_diagnostics(request_id,events) VALUES(rid,events);
    DELETE FROM gpante_chat.search_diagnostics WHERE created_at<=clock_timestamp()-INTERVAL '7 days'
      OR request_id IN (SELECT request_id FROM gpante_chat.search_diagnostics ORDER BY created_at DESC,request_id DESC OFFSET 2000);
    RETURN result||jsonb_build_object('diagnosticsSaved',true);
  EXCEPTION WHEN OTHERS THEN
    RETURN result||jsonb_build_object('diagnosticsSaved',false);
  END;
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.admin_api(input JSONB) RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,gpante_chat SET statement_timeout='8s' AS $$
DECLARE result JSONB; cfg gpante_chat.runtime; ts TIMESTAMPTZ:=clock_timestamp(); remaining BIGINT; active BIGINT;
  reason TEXT; counts JSONB; total BIGINT;
BEGIN
  result:=gpante_chat.admin_api_before_observability(input);
  IF input->>'operation' IS DISTINCT FROM 'getDiagnostics' OR result ? 'error' THEN RETURN result; END IF;
  SELECT * INTO cfg FROM gpante_chat.runtime WHERE singleton;
  remaining:=greatest(0,coalesce(cfg.monthly_budget_nano,0)-coalesce((result->'budget'->>'usedNano')::bigint,0));
  active:=(result->'budget'->>'activeLeases')::bigint;
  -- This mirrors the existing capacity and first-call reservation gates, without reserving anything.
  reason:=CASE WHEN cfg.singleton IS NULL THEN 'unavailable' WHEN NOT cfg.enabled THEN 'disabled'
    WHEN cfg.pricing_valid_until<=ts THEN 'pricing_review' WHEN remaining<320000000 THEN 'monthly_budget'
    WHEN active>=2 THEN 'capacity' ELSE 'ready' END;
  SELECT coalesce(jsonb_agg(jsonb_build_object('date',day,'reason',r.reason,'count',refused) ORDER BY day DESC,r.reason),'[]'::jsonb),
    coalesce(sum(refused),0) INTO counts,total FROM gpante_chat.admission_refusals r WHERE day BETWEEN (ts AT TIME ZONE 'UTC')::date-6 AND (ts AT TIME ZONE 'UTC')::date;
  RETURN result||jsonb_build_object('readiness',jsonb_build_object('canAcceptNewTurn',reason='ready','reason',reason,
    'remainingNano',remaining,'checkedAt',ts,'pricingReviewSoon',cfg.pricing_valid_until>ts AND cfg.pricing_valid_until<=ts+INTERVAL '7 days'),
    'refusals',jsonb_build_object('windowDays',7,'calendarUtc',true,'total',total,'days',counts));
END $$;

CREATE OR REPLACE FUNCTION gpante_chat.cleanup() RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE result JSONB; removed INTEGER;
BEGIN
  result:=gpante_chat.cleanup_before_observability();
  DELETE FROM gpante_chat.admission_refusals WHERE day<(clock_timestamp() AT TIME ZONE 'UTC')::date-6;
  GET DIAGNOSTICS removed=ROW_COUNT;
  RETURN result||jsonb_build_object('admissionDaysRemoved',removed);
END $$;
REVOKE ALL ON TABLE gpante_chat.admission_refusals FROM PUBLIC,gpante_chat_admin;
REVOKE ALL ON FUNCTION gpante_chat.admit_v4(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT),
  gpante_chat.admit_v4_before_observability(TEXT,TEXT,TEXT,TEXT,INTEGER,TEXT,TEXT,TEXT,TEXT),
  gpante_chat.finish_v4(TEXT,TEXT,TEXT,TEXT,JSONB),gpante_chat.cleanup(),gpante_chat.cleanup_before_observability(),
  gpante_chat.admin_api(JSONB),gpante_chat.admin_api_before_observability(JSONB) FROM PUBLIC;
REVOKE ALL ON FUNCTION gpante_chat.admin_api_before_observability(JSONB) FROM gpante_chat_admin;
GRANT EXECUTE ON FUNCTION gpante_chat.admin_api(JSONB) TO gpante_chat_admin;
COMMIT;
