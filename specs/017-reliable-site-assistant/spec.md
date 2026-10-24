# Feature Specification: Reliable Site-wide Assistant

**Feature Branch**: `017-reliable-site-assistant`
**Created**: 2026-10-24
**Status**: Accepted for implementation
**Input**: User approved the audited correctness, extraction, history/retry, verification, shared-search and diagnostic plan using Ponytail and Spec Kit. Rules must apply site-wide without promoting individual pages. English reports precede their full Persian translations.

## User Scenarios & Testing

### User Story 1 - Trustworthy recommendations (Priority: P1)
A visitor receives relevant answers supported by the right current source, including mixed educational and product results.
**Why this priority**: Unsupported answers and rejection of correct answers defeat the core product.
**Independent Test**: Reproduce the audited uncertainty bypass, newest-article routing and free-page/paid-product price error, then verify corrected behavior.
**Acceptance Scenarios**:
1. **Given** no matching support evidence, **When** an answer mixes an unrelated uncertainty phrase with a positive purchase claim, **Then** the unsupported answer is rejected; an honest limitation remains allowed.
2. **Given** an explicit article request with an ordering word, **When** sources are selected, **Then** ordering does not turn the request into a product request.
3. **Given** free educational content beside a paid product, **When** the product has its correct price and source, **Then** validation associates each claim with its own recommendation.

### User Story 2 - Current, complete and resilient discovery (Priority: P1)
Visitors discover all eligible public pages, posts and products automatically through shared site and assistant search.
**Why this priority**: Source coverage, freshness and resilience must hold for the whole site.
**Independent Test**: Publish/edit/switch editor/hide/delete fixture content, corrupt one unrelated document, and compare indexed discovery with eligible source counts.
**Acceptance Scenarios**:
1. **Given** a page switched away from its builder, **When** current text is edited, **Then** search uses the active editor instead of obsolete saved builder text.
2. **Given** one invalid unrelated document, **When** healthy content matches, **Then** healthy results remain available with an explicit incomplete-coverage notice; reading the invalid document remains an error.
3. **Given** new or changed public content, **When** indexing completes, **Then** both standard site search and assistant discovery reflect it without a manual page list; hidden or private content is excluded immediately.
4. **Given** spelling variants or uncertain manufacturing suitability, **When** searching, **Then** safe normalization/suggestions preserve explicit version, price/free, format, material and thickness constraints; unsupported suitability remains unknown.

### User Story 3 - Recoverable conversation (Priority: P1)
Visitors keep access to unexpired history and can recover an uncertain send without duplicate processing or charges.
**Why this priority**: Network uncertainty must not cost twice or hide recent answers.
**Independent Test**: More than six exchanges followed by refresh and history pagination; simulated cookie age; concurrent duplicate sends and lost-response replay.
**Acceptance Scenarios**:
1. **Given** older unexpired messages, **When** the visitor refreshes or requests older history, **Then** those messages remain accessible independently of the limited model context.
2. **Given** an active session approaching its original cookie expiry, **When** it is renewed, **Then** identity is preserved while per-message expiry remains enforced.
3. **Given** the same send identifier and text, **When** a request is retried or duplicated, **Then** it returns existing status/result without a new paid turn; another session cannot replay it and changed text cannot reuse it.

### User Story 4 - Useful discovery during AI unavailability (Priority: P2)
Visitors can use native verified search when the model service, capacity or budget is unavailable.
**Independent Test**: Simulate service/capacity/budget refusal and use public search without a model call.
**Acceptance Scenarios**:
1. **Given** an AI refusal, **When** native search is requested, **Then** verified public result cards and coverage/correction notices remain available without automatic paid retry.

### User Story 5 - Explainable operation and feedback (Priority: P2)
Administrators can inspect source health, bounded search decisions and feedback without enabling raw execution logs; test traffic is distinguishable.
**Independent Test**: Verify administrator-only access, sanitized bounded diagnostic events, owned feedback, test labels and retention cleanup.
**Acceptance Scenarios**:
1. **Given** a completed turn, **When** its diagnostic events are inspected, **Then** tool/source, safe normalized query/filter metadata, result IDs/ranks, rejection codes and timing can be correlated without raw source bodies, secrets or copied conversation text.
2. **Given** a visitor rating, **When** feedback is saved, **Then** only that visitor's accessible turn can be rated, with no model call.
3. **Given** known synthetic verification, **When** an administrator marks it, **Then** it is distinguishable from unclassified visitor traffic without treating an untrusted browser label as authority.

### User Story 6 - Verified compatible release (Priority: P1)
Operators can release and roll back scoped changes with current financial and concurrency verification.
**Independent Test**: Run current-contract isolated database tests, source/graph checks, WordPress/browser regressions and release readback.

### Edge Cases
- Empty/failed/incomplete sources are distinct; no valid answer may turn an unrelated uncertainty phrase into a permission to invent claims.
- Mixed or link-first recommendation sections; missing attributes; free versus paid sources; updated prices.
- Empty/invalid active builder data, inactive retained data, conditional widgets, deleted/hidden/operational pages.
- Partial index bootstrap, missed-event reconciliation, simultaneous updates, cache freshness and final pagination boundaries.
- Duplicate pending/error/expired/cleared sends, changed text, malformed cursor, failed history loads and policy changes.
- Feedback ownership, expired or cleared history, query PII/secrets, retention, test labels and unavailable diagnostics.

## Requirements

### Functional Requirements
- **FR-001**: Positive claims MUST satisfy current relevant evidence, citation, product-price/attribute and detailed-read requirements even beside uncertainty statements.
- **FR-002**: Content type MUST precede sort intent; validated facts MUST remain associated with their own linked recommendation.
- **FR-003**: Extraction MUST follow the active editor; invalid documents MUST be isolated with explicit incomplete coverage.
- **FR-004**: All eligible public pages/posts/products MUST be automatically indexed and reflected in standard site search and assistant discovery; response bounds MUST NOT cap source coverage.
- **FR-005**: Publication, text/meta/editor/status/visibility/term changes and deletion MUST update eligibility and discovery. Returned mutable product facts MUST be fresh.
- **FR-006**: Persian normalization and explicit suggestions MUST preserve requested constraints. Manufacturing properties MUST be reported only from verified source data.
- **FR-007**: Visitors MUST be able to page through unexpired history independently of model context, preserving loaded messages on refresh.
- **FR-008**: Active cookie renewal MUST preserve identity; expiry/clear MUST not resurrect hidden model context or visitor access.
- **FR-009**: New clients MUST preserve a send identifier across uncertain retries; atomic session-bound deduplication MUST prevent a second paid turn and reject changed-text reuse.
- **FR-010**: Native search MUST remain usable without AI generation; failed sends MUST NOT automatically retry paid calls.
- **FR-011**: Administrator diagnostics MUST expose bounded sanitized search decisions and source health; raw execution/source bodies, credentials and copied transcripts MUST remain excluded.
- **FR-012**: Feedback MUST be bounded, rate-limited and restricted to the same visitor's accessible successful turn. Synthetic labels MUST require trusted administrator authority.
- **FR-013**: Diagnostic events MUST expire within seven days and have a finite row/event cap; existing administrator conversation retention remains thirty days.
- **FR-014**: Financial reservation/settlement and concurrency tests MUST use the current contract in an isolated database; no real ledger refund may be invented for unknown usage.
- **FR-015**: Existing model family, USD2/calendar-month budget, four model calls, three tool executions, two concurrent conversations, current global policy and unrelated site/workflow configuration MUST be preserved.
- **FR-016**: Release MUST preserve valid settings, old clients during upgrade, fixed destinations, server identities, Persian/RTL/accessibility, archive ownership and a protected rollback path.

### Key Entities
- Public source document: identity, content type, editor, public eligibility, current revision, normalized searchable text and validity state.
- Conversation turn: visitor ownership, stable send identity, lifecycle, expiry, answer and bounded feedback.
- Search diagnostic: owned turn, safe query/filter metadata, result identities/ranks, outcome and expiry.

## Success Criteria

### Measurable Outcomes
- **SC-001**: All audited failure examples and unrelated-topic regression examples pass, with zero page-specific promotion exceptions.
- **SC-002**: Fixture discovery matches eligible source totals across pagination and lifecycle changes; incomplete coverage is visible in every affected response.
- **SC-003**: Seven or more exchanges remain accessible after refresh and pagination; two simultaneous equivalent sends create exactly one new paid-turn reservation.
- **SC-004**: Cross-session replay/feedback and expired/cleared access are rejected in every ownership test.
- **SC-005**: Warm discovery avoids re-extracting every source document; bounded source timing and concurrent boundary checks are reported separately from model latency.
- **SC-006**: All required checks, installed artifact/active graph readback and bounded synthetic verification complete without changing approved cost/concurrency policy.

## Assumptions
- This delivery covers the six approved work areas and native search fallback; authenticated order/download access remains a later feature.
- Standard WordPress main search and the explicit fallback share the selector. Theme-specific AJAX search is preserved unless a verified adapter is necessary and safe; no global hijacking of unrelated queries.
- No external/vector service is needed. Existing WordPress, PostgreSQL and n8n infrastructure is reused.
- Diagnostic metadata is short-lived and sanitized; historical traffic cannot be reliably reclassified automatically.
- Manufacturing suitability is never inferred from product images or unrelated text. Absent verified fields remain unknown.
