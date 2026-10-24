-- Apply once as gpante_chat_app after 004, with Main drained. No transcript copies.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('gpante_chat_admission_v2'));
ALTER TABLE gpante_chat.turns ADD COLUMN traffic_kind TEXT NOT NULL DEFAULT 'unclassified'
  CHECK(traffic_kind IN ('unclassified','synthetic'));
CREATE TABLE gpante_chat.search_diagnostics (
  request_id TEXT PRIMARY KEY REFERENCES gpante_chat.turns(request_id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  events JSONB NOT NULL CHECK(jsonb_typeof(events)='array' AND jsonb_array_length(events)<=12 AND octet_length(events::text)<=8192)
);
CREATE INDEX search_diagnostics_created_idx ON gpante_chat.search_diagnostics(created_at);

CREATE FUNCTION gpante_chat.diagnostic_text(value TEXT, max_length INTEGER) RETURNS TEXT
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
  SELECT left(regexp_replace(regexp_replace(regexp_replace(regexp_replace(
    translate(lower(coalesce(value,'')),'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩يك','01234567890123456789یک'),
    'https?://[^[:space:]]+|[^[:space:]]+@[^[:space:]]+','[redacted]','g'),
    '(sk|pk|api|token|bearer)_[a-z0-9_-]{8,}|[a-z0-9_-]{24,}','[redacted]','g'), '[+]?[0-9][0-9 ()-]{6,}[0-9]','[redacted]','g'),
    '[[:cntrl:]]',' ','g'),greatest(0,least(max_length,120)))
$$;

CREATE FUNCTION gpante_chat.finish_v4(rid TEXT,assistant_text TEXT,outcome TEXT,code TEXT,diagnostics JSONB)
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

ALTER FUNCTION gpante_chat.cleanup() RENAME TO cleanup_archive_v3;
CREATE FUNCTION gpante_chat.cleanup() RETURNS JSONB LANGUAGE plpgsql SET search_path=pg_catalog,gpante_chat AS $$
DECLARE result JSONB; removed INTEGER;
BEGIN
  result:=gpante_chat.cleanup_archive_v3();
  DELETE FROM gpante_chat.search_diagnostics WHERE created_at<=clock_timestamp()-INTERVAL '7 days'
    OR request_id IN (SELECT request_id FROM gpante_chat.search_diagnostics ORDER BY created_at DESC,request_id DESC OFFSET 2000);
  GET DIAGNOSTICS removed=ROW_COUNT;
  RETURN result||jsonb_build_object('diagnosticsRemoved',removed);
END $$;

ALTER FUNCTION gpante_chat.admin_api(JSONB) RENAME TO admin_api_archive_v3;
CREATE FUNCTION gpante_chat.admin_api(input JSONB) RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,gpante_chat SET statement_timeout='8s' AS $$
DECLARE op TEXT:=input->>'operation'; ts TIMESTAMPTZ:=clock_timestamp(); rows JSONB; summary JSONB; budget JSONB;
BEGIN
  IF op='markSynthetic' THEN
    IF coalesce(input->>'requestId','') !~ '^[a-f0-9]{32}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
    UPDATE gpante_chat.turns SET traffic_kind='synthetic' WHERE request_id=input->>'requestId' AND created_at>ts-INTERVAL '30 days';
    IF NOT FOUND THEN RETURN '{"error":"turn_unavailable"}'::jsonb; END IF;
    RETURN '{"ok":true}'::jsonb;
  END IF;
  IF op IS DISTINCT FROM 'getDiagnostics' THEN RETURN gpante_chat.admin_api_archive_v3(input); END IF;
  SELECT jsonb_build_object('total',count(*),'synthetic',count(*) FILTER(WHERE traffic_kind='synthetic'),
    'unclassified',count(*) FILTER(WHERE traffic_kind='unclassified'),'errors',count(*) FILTER(WHERE status IN ('error','interrupted')),
    'helpful',count(*) FILTER(WHERE user_feedback='helpful'),'unhelpful',count(*) FILTER(WHERE user_feedback='unhelpful'))
    INTO summary FROM gpante_chat.turns WHERE created_at>ts-INTERVAL '7 days';
  SELECT jsonb_build_object('enabled',enabled,'monthlyBudgetNano',monthly_budget_nano,'pricingValidUntil',pricing_valid_until,
    'usedNano',(SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls WHERE month=date_trunc('month',ts AT TIME ZONE 'UTC')::date),
    'activeLeases',(SELECT count(*) FROM gpante_chat.requests WHERE NOT finished AND expires_at>ts)) INTO budget FROM gpante_chat.runtime WHERE singleton;
  SELECT coalesce(jsonb_agg(payload ORDER BY created_at DESC,request_id DESC),'[]'::jsonb) INTO rows FROM
    (SELECT t.created_at,t.request_id,jsonb_build_object('requestId',t.request_id,'createdAt',t.created_at,'status',t.status,
      'errorCode',t.error_code,'trafficKind',t.traffic_kind,'feedback',t.user_feedback,'events',coalesce(d.events,'[]'::jsonb)) AS payload
      FROM gpante_chat.turns t LEFT JOIN gpante_chat.search_diagnostics d ON d.request_id=t.request_id AND d.created_at>ts-INTERVAL '7 days'
      WHERE t.created_at>ts-INTERVAL '7 days' ORDER BY t.created_at DESC,t.request_id DESC LIMIT 20) q;
  RETURN jsonb_build_object('summary',summary,'budget',budget,'turns',rows,'windowDays',7);
END $$;
REVOKE ALL ON FUNCTION gpante_chat.diagnostic_text(TEXT,INTEGER),gpante_chat.finish_v4(TEXT,TEXT,TEXT,TEXT,JSONB),
  gpante_chat.cleanup(),gpante_chat.cleanup_archive_v3(),gpante_chat.admin_api(JSONB),gpante_chat.admin_api_archive_v3(JSONB) FROM PUBLIC;
REVOKE ALL ON FUNCTION gpante_chat.admin_api_archive_v3(JSONB) FROM gpante_chat_admin;
GRANT USAGE ON SCHEMA gpante_chat TO gpante_chat_admin;
GRANT EXECUTE ON FUNCTION gpante_chat.admin_api(JSONB) TO gpante_chat_admin;
COMMIT;
