# Interface contracts

## Public chat additions (RESTv1, policyVersion2 preserved)
- Existingactions plus feedback. sendMessage: optional sendId,32lowercasehex, generatedwithbrowsercrypto andretainedacrossuncertainretry; absent retainslegacyclientbehavior.
- loadPreviousSession: optional historyCursor positive decimalstring; pagecontains6completeeligibleturns, nextCursor decimalstring|null, hasMore boolean. Messagesadd optional requestId32hex andfeedback helpful/unhelpful|null. historyResetAt timestamp|null invalidatescachedolderpages afteranother tabclear. Loadedolderpages survivebackgroundrefresh, knownexpiry/clearstillapplies.
- feedback: feedbackRequestId32hex +feedbackValue helpful/unhelpful. ScopedtrustedSID, ratebounded, no model call.
- Successsendreplyadd requestId; replay produces theexistingoutput/expiresAt/requestId withno modelcall. OldbackendmissingIDs remainvalidduringrollingupgrade; feedbackdisabledforthoseanswers.
- admit_v4(sid,cid,action,text,contract,requested_send_id,history_cursor,feedback_request,feedback_value) wraps unchangedadmit_v3 under sameadmissionlock. NewMainbindsall9parameters. ReusedIDdifferenttext invalid; pendingbusy; completedfailurefixedcode; invisible/expiredcannotreplay.

## Public source
- Existingknowledge source=site typedarray andpagination preserved. /search publicGET accepts boundedsearch/page/intent andreturnssamesafe shape. StandardWordPressmainsearch uses the same selector; unrelatedthemeAJAXqueriesarepreserved.
- Coverage headers: X-GPante-Coverage complete|incomplete; X-GPante-Invalid-Documents integer; X-GPante-Index-Initializing 0|1. Adapterreturns coverage:{complete,invalidDocuments,initializing}; incompleteempty is incomplete_source, nevercomplete no_matches. Querysuggestionsareexplicit andnever silentlyreplaceconstraints.
- Indexfunctions: gpante_support_search_index_register(), _upgrade(), _health(), _batch(). Rootwires registration/upgrade/packageallowlist. Lifecyclehooksupsert/removeonepublicdocument; backgroundbatches reconcileallIDs withleaseandreadiness.
- AIunavailablefallbackbutton uses trustedhome_url root?s=question anddoesnotcallmodel. Native search must show coverage notice wheninitializing/invalid.

## Diagnostics and admin
- finish_v4 adds bounded sanitizedevents to existingfinish_v3; rootbuildsnewFinish RequestSQL and safeJSdiagnosticmetadata. Rawexecutionstorage remainsoff.
- Admin getDiagnostics returns bounded latestevents/counts/currentbudgetstate, no transcripts/secrets. Admin markSynthetic accepts existingrequestId only withadministratorauthorization; visitorscannotclassifytheirtraffictrusted.
- WordPressmanage_options+nonce protects diagnostics/testlabels; feedback usestrustedSID ownership. Localindexhealthshows readiness/counts/invalidIDs/revisions.
