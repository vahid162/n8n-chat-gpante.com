# Tasks: Reliable Site-wide Assistant

Input: accepted spec.md, plan.md, research.md and contracts/interfaces.md. All33tasks are in scope; account/order access remains deferred.

## Setup and foundations

- [X] T001 Record accepted stories, requirements and quality checklist in specs/017-reliable-site-assistant/spec.md and checklists/requirements.md.
- [X] T002 Complete research/design/contracts/quickstart in specs/017-reliable-site-assistant/plan.md and contracts/interfaces.md.
- [X] T003 Capture fresh private live/plugin/budget baselines and preserve source at af67722; record sanitized candidate state in specs/017-reliable-site-assistant/verification.md.

## US1 - Trustworthy recommendations

- [X] T004 [US1] Add failing uncertainty, routing and mixed-source claim regressions in tests/answer-contract.test.cjs and tests/grounding*.test.cjs.
- [X] T005 [US1] Fix shared evidence/routing/claim binding in src/chat.cjs and src/system-message.txt; verify honest limitations and detailed-read rules.

## US2 - Current indexed discovery

- [X] T006 [US2] Add red editor-transition/invalid-neighbor regressions in tests/site-knowledge.php and tests/site-knowledge-wordpress.php.
- [X] T007 [US2] Implement public index schema, leased batches and lifecycle invalidation in wordpress-plugin/gpante-support-chat/includes/search-index.php.
- [X] T008 [US2] Integrate indexed shared/native search, active-editor extraction and explicit incomplete coverage in includes/site.php, includes/catalog.php and src/catalog.cjs.
- [X] T009 [US2] Verify conservative Persian normalization/suggestions and existing verified manufacturing constraints in tests/search-index*.php and tests/site-knowledge.test.cjs.

## US3 - Recoverable conversation

- [X] T010 [US3] Add red duplicate-send/history/cookie regressions in tests/conversation*.cjs, tests/widget-controller.test.cjs and tests/widget-bridge.php.
- [X] T011 [US3] Add compatible send identity, paged history, owned feedback and replay admission in sql/004-conversation-reliability.sql.
- [X] T012 [US3] Pass validated additive fields, preserve response bounds and renew session identity in wordpress-plugin/gpante-support-chat/includes/bridge.php.
- [X] T013 [US3] Implement paged visible history, reliable send IDs and owned feedback UI in assets/chat.js, assets/chat.css and templates/widget.php.
- [X] T014 [US3] Remove repeated autoload checks while preserving upgrades/settings in includes/settings.php and includes/appearance.php; update focused tests.

## US4 - Discovery without AI

- [X] T015 [US4] Add trusted native-search fallback navigation and tests in templates/widget.php, assets/chat.js, scripts/render-widget.php and tests/widget-controller.test.cjs.

## US5 - Explainable operation

- [X] T016 [US5] Define and test bounded sanitized search events and diagnostics ownership in tests/diagnostics.test.cjs and tests/diagnostics-database.sql.
- [X] T017 [US5] Add diagnostic persistence/admin operations/traffic labels and bounded cleanup in sql/005-search-diagnostics.sql.
- [X] T018 [US5] Integrate sanitized diagnostic metadata, replay/history/feedback DTOs and current SQL calls in src/chat.cjs, src/admin.cjs and scripts/build-workflow.cjs.
- [X] T019 [US5] Add administrator-only source/turn health and synthetic marking in includes/diagnostics.php and includes/admin.php; preserve capability/nonce/escaping.
- [X] T020 [US5] Verify feedback isolation, diagnostics sanitization/retention/test labels and public response safety in tests/diagnostics* and tests/admin.test.cjs.

## US6 - Verified compatible release

- [X] T021 [US6] Migrate current financial and concurrency checks in tests/database.sql and tests/money-concurrency.py; run only isolated database fixtures.
- [X] T022 [US6] Run financial and diagnostic migrations/tests in isolated CI via .github/workflows/wordpress-release.yml.
- [X] T023 [US6] Wire index/diagnostics, bump component versions and runtime allowlist in gpante-support-chat.php, scripts/build-plugin.py, readme.txt, CHANGELOG.md and releases/wordpress/0.10.0.md.
- [X] T024 [US6] Run combined Node/Python/PHP/native WP/database/browser/WPCS and deterministic builds; record evidence in specs/017-reliable-site-assistant/verification.md.
- [X] T025 [US6] Validate private Main/Admin candidates against installed n8n activation/SDK strict; preserve credentials/settings and unrelated workflow fingerprints.
- [X] T026 [US6] Review combined implementation and address verified findings; update specs/017-reliable-site-assistant/verification.md.
- [X] T027 [US6] Publish reviewed changes through a scoped PR, pass CI and merge against expected head; attach PR to task.
- [X] T028 [US6] Read back immutable official plugin Release/tag/commit/ZIP/checksum and compare allowlisted files.
- [X] T029 [US6] Apply protected additive application migrations, install official plugin, publish exact Main/Admin candidates and reconcile index; preserve valid settings/policy.
- [X] T030 [US6] Verify bounded production browser scenarios, usage/cost, owned feedback/history/replay and privileged synthetic labels; record actual limits.
- [X] T031 [US6] Compare installed/public/native search coverage and freshness, active graph/version, scheduled cleanup and unrelated components.
- [X] T032 [US6] Update actual state and evidence in README.md, docs/, canonical skill/references and specs/017-reliable-site-assistant/verification.md; publish final documentation.
- [X] T033 [US6] Rebuild local canonical skill package, remove only owned staging/fixtures and restore isolated services; confirm clean Git and completion receipt.

## Dependencies and parallel execution

After foundations, US1(src/chat), US2(index/catalog), and US3/US4(bridge/widget/004) run in parallel with exclusive file ownership. Root integrates US5 and builder after source owners release shared files. Native WP fixtures use one assigned isolated database slot; SQL money fixtures use a separate isolated database. US6 follows combined checks and review. Each story has independent acceptance tests in spec.md; shipping continues through all approved stories, not only the US1 MVP.
