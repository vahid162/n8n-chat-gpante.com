-- Apply once as gpante_chat_app after 006. Only the private administration wrapper changes.
BEGIN;
-- CREATE OR REPLACE retains the existing owner and limited administrator EXECUTE grant.
CREATE OR REPLACE FUNCTION gpante_chat.admin_api(input JSONB) RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,gpante_chat SET statement_timeout='8s' AS $$
DECLARE op TEXT:=input->>'operation'; ts TIMESTAMPTZ:=clock_timestamp(); rows JSONB; summary JSONB; budget JSONB;
  page_no INTEGER; has_more BOOLEAN; key TEXT; query_text TEXT; wanted_status TEXT; wanted_traffic TEXT; wanted_source TEXT;
  start_at TIMESTAMPTZ; end_at TIMESTAMPTZ;
BEGIN
  IF op='markSynthetic' THEN
    IF coalesce(input->>'requestId','') !~ '^[a-f0-9]{32}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
    UPDATE gpante_chat.turns SET traffic_kind='synthetic' WHERE request_id=input->>'requestId' AND created_at>ts-INTERVAL '30 days';
    IF NOT FOUND THEN RETURN '{"error":"turn_unavailable"}'::jsonb; END IF;
    RETURN '{"ok":true}'::jsonb;
  END IF;
  IF op IS DISTINCT FROM 'getDiagnostics' THEN RETURN gpante_chat.admin_api_archive_v3(input); END IF;
  IF input ? 'page' AND (jsonb_typeof(input->'page') IS DISTINCT FROM 'number' OR input->>'page' !~ '^[0-9]{1,4}$') THEN
    RETURN '{"error":"invalid_input"}'::jsonb;
  END IF;
  page_no:=coalesce((input->>'page')::integer,1);
  IF page_no NOT BETWEEN 1 AND 1000 THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  FOREACH key IN ARRAY ARRAY['search','from','to','status','traffic','source'] LOOP
    IF input ? key AND jsonb_typeof(input->key) IS DISTINCT FROM 'string' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  END LOOP;
  query_text:=coalesce(input->>'search',''); wanted_status:=coalesce(input->>'status','');
  wanted_traffic:=coalesce(input->>'traffic',''); wanted_source:=coalesce(input->>'source','');
  IF length(query_text)>100 OR wanted_status NOT IN ('','pending','success','error','interrupted','legacy')
    OR wanted_traffic NOT IN ('','synthetic','unclassified')
    OR wanted_source NOT IN ('','products','articles','site','faq','guides','categories') THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  IF coalesce(input->>'from','')<>'' AND input->>'from' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  IF coalesce(input->>'to','')<>'' AND input->>'to' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN RETURN '{"error":"invalid_input"}'::jsonb; END IF;
  start_at:=coalesce(nullif(input->>'from','')::date::timestamp AT TIME ZONE 'UTC',ts-INTERVAL '7 days');
  end_at:=coalesce((nullif(input->>'to','')::date+1)::timestamp AT TIME ZONE 'UTC',ts);
  IF coalesce(input->>'from','')<>'' AND coalesce(input->>'to','')<>'' AND input->>'from'>input->>'to' THEN
    RETURN '{"error":"invalid_input"}'::jsonb;
  END IF;
  -- The overview remains the complete seven-day window, independent of row filters.
  SELECT jsonb_build_object('total',count(*),'synthetic',count(*) FILTER(WHERE traffic_kind='synthetic'),
    'unclassified',count(*) FILTER(WHERE traffic_kind='unclassified'),'errors',count(*) FILTER(WHERE status IN ('error','interrupted')),
    'helpful',count(*) FILTER(WHERE user_feedback='helpful'),'unhelpful',count(*) FILTER(WHERE user_feedback='unhelpful'))
    INTO summary FROM gpante_chat.turns WHERE created_at>ts-INTERVAL '7 days';
  SELECT jsonb_build_object('enabled',enabled,'monthlyBudgetNano',monthly_budget_nano,'pricingValidUntil',pricing_valid_until,
    'usedNano',(SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls WHERE month=date_trunc('month',ts AT TIME ZONE 'UTC')::date),
    'activeLeases',(SELECT count(*) FROM gpante_chat.requests WHERE NOT finished AND expires_at>ts)) INTO budget FROM gpante_chat.runtime WHERE singleton;
  SELECT coalesce(jsonb_agg(payload ORDER BY created_at DESC,request_id DESC),'[]'::jsonb),count(*)>20 INTO rows,has_more FROM
    (SELECT t.created_at,t.request_id,jsonb_build_object('requestId',t.request_id,'sessionId',t.session_id,'createdAt',t.created_at,'status',t.status,
      'errorCode',t.error_code,'trafficKind',t.traffic_kind,'feedback',t.user_feedback,'events',coalesce(d.events,'[]'::jsonb)) AS payload
      FROM gpante_chat.turns t LEFT JOIN gpante_chat.search_diagnostics d ON d.request_id=t.request_id AND d.created_at>ts-INTERVAL '7 days'
      WHERE t.created_at>ts-INTERVAL '7 days' AND t.created_at>=start_at AND t.created_at<end_at
        AND (wanted_status='' OR t.status=wanted_status) AND (wanted_traffic='' OR t.traffic_kind=wanted_traffic)
        AND (wanted_source='' OR EXISTS(SELECT 1 FROM jsonb_array_elements(coalesce(d.events,'[]'::jsonb)) e
          WHERE e->>'event'='source' AND e->>'source'=wanted_source))
        AND (query_text='' OR strpos(lower(t.request_id),lower(query_text))>0 OR strpos(lower(coalesce(t.error_code,'')),lower(query_text))>0
          OR EXISTS(SELECT 1 FROM jsonb_array_elements(coalesce(d.events,'[]'::jsonb)) e WHERE e->>'event'='source'
            AND (strpos(lower(coalesce(e->>'query','')),lower(query_text))>0 OR strpos(lower(coalesce(e->>'tool','')),lower(query_text))>0
              OR strpos(lower(coalesce(e->>'source','')),lower(query_text))>0 OR strpos(lower(coalesce(e->>'status','')),lower(query_text))>0)))
      ORDER BY t.created_at DESC,t.request_id DESC LIMIT 21 OFFSET (page_no-1)*20) q;
  RETURN jsonb_build_object('summary',summary,'budget',budget,'turns',rows-20,'windowDays',7,'page',page_no,'hasMore',has_more);
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow OR invalid_text_representation THEN
  RETURN '{"error":"invalid_input"}'::jsonb;
END $$;
COMMIT;
