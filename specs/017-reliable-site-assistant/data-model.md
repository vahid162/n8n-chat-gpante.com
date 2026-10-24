# Data model

- Public index: post_id primarykey; sourcekind; normalizedtitle/body; staticpublictext; publicationdate/revision; valid/invalid state. No price, rawbuilderdata, customerrecord or protecteddownload. Currenteligibility is independently rechecked.
- Existingturn additivefields: client_message_id nullable unique(session_id,client_message_id); user_feedback nullable helpful/unhelpful; traffic label defaults unclassified, adminmarked synthetic only. Existingtimestamps/archive/ledger retained.
- Diagnostic row: request_id linkedtoexistingturn withcascadecleanup; created_at; bounded allowlisted events (tool/source/redactedquery+filters/resultIDs/ranks/status/rejectioncode). Seven-day retention and2000rowmaximum; no copiedquestions/answers/sourcebodies/secrets.
- Sendstate: new->pending->success/error/interrupted. SameID+text returnsvisible successfulresult, pendingbusy or priorfailure withoutnewcharge. Cleared/expired cannotreplay. Editedquestion usesnewID.
- Historycursor: positive numeric historyID, scopedtosignedsession andstillvisible/unexpiredturns. Page6complete turns, ascendingrender; nextCursor+hasMore; contextindependent6turns.
