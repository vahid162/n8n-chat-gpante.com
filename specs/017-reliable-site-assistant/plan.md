# Implementation Plan: Reliable Site-wide Assistant

**Branch**: `017-reliable-site-assistant` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary
Deliver the six approved work areas with scoped native changes. Plugin target0.10.0, Main1.7.0, Admin1.3.0, canonical skill0.13.0; Retention graph stays1.2.0 and calls the additive updated cleanup function.

## Technical Context
- Language/Version: PHP8.1+ (production8.3), import-free CommonJS/Node24, browserJS, PostgreSQLPLpgSQL; Python tooling.
- Primary Dependencies: existing WordPress/WooCommerce/Elementor, n8n HTTP+PostgreSQL native nodes; no added external service/library.
- Storage: WP-native public-static index; project_db.gpante_chat additive004/005 migrations for sendidentity/feedback/diagnostic metadata.
- Testing: Node, focused PHP, native isolated WP/Woo, Python, current-schema isolated SQL/concurrency, browser; generatedgraph/nativeSDKvalidation; releaseassetreadback.
- Target Platform/Type: existing Persian public WordPress plugin and n8n support service.
- Performance Goals: avoid full document extraction per warm search; measure cold/warm source time and concurrent boundaries without inventing throughput promises.
- Constraints: unchangedmodel/USD2/monthUTC/4calls/3tools/2inflight; userpolicy and30dayadminarchive; fixeddestinations; no rawcustomer/sourcepayloads inpublicGit.
- Scale/Scope: full eligible public catalog; current45pages/19posts/1082publishedproducts are observations, not ceilings. No account/order/download authorization feature in this delivery.

## Constitution Check
PASS before and after design: productionauthority limitedtothischat; publicsourceeligibility before projection; trustedserveridentity; conservativeledger; native infrastructure; compatible settings; evidence-driven tests and rollback. No conflicts or new service assumptions. Account features remain deferred.

## Project Structure
- src/chat.cjs: evidence/routing/claimbinding and additive DTO/replay handling.
- src/catalog.cjs: coverage-aware site normalization; scripts/build-workflow.cjs generates currentMain/Admin/Retention.
- wordpress-plugin/gpante-support-chat/includes/search-index.php: public index schema/lifecycle/query/nativefallback integration.
- includes/site.php, catalog.php: current-editor extraction, indexed selector and coverage; bridge.php/assets/template: reliablehistory/send/cookie/feedback/fallback.
- includes/diagnostics.php and src/admin.cjs: safe administrator diagnostics and test labels; no broad raw logging.
- sql/004-conversation-reliability.sql and005-search-diagnostics.sql: additive current-contract functions and bounded data.
- tests/: audited regressions, index lifecycle/coverage, widgetpagination/uncertainretry, ownedfeedback, isolatedmoney andprivacy.
- specs/017-reliable-site-assistant/: acceptedrequirements, contracts, tasks and actualverification.

## Implementation and rollout
Follow tasks.md by story with exclusive file ownership. Run regressions red/green, then combinedchecks once. Preserve privatefreshbaseline/rollback. Publish reviewedsource viaPR and immutable officialpluginrelease. Applyadditiveapplicationmigrations withMain drained whenneeded; installpluginandpublish exactvalidatedMain/Admin preservingbindings/settings. Reconcileindex and verifycoverage/readback, boundedbrowserquestions and ledger. Updatecontinuity/evidence and cleanup ownedtemporaryresources. Never write n8n internal DB for mutations.

## Complexity Tracking
One public index table and one bounded diagnostic table are justified by repeated-source work and missing correlation. No external engine, vector service, generic framework or authenticated order layer is added.
