# Verification and release state

2026-10-24: baseline source af67722, plugin0.9.0/Main1.6.1/Admin1.2.0/Retention1.2.0. Planned targetplugin0.10.0/Main1.7.0/Admin1.3.0/skill0.13.0. Implementation and live release evidence will be recorded separately. Accepted plan uses Ponytail and SpecKit017; no account/order feature or unrelated infrastructure changes.

## Candidate verification

- Six initial answer-contract regressions failed against the baseline before fixes. Further review regressions cover conventional Markdown layouts, explicit article topics, incomplete coverage and unbound free claims. Final combined counts are recorded after the authors release their files.
- Isolated PostgreSQL applied004/005. Combined rollback fixtures passed: archive44 assertions, money29, conversation29, diagnostics37. Two concurrent reservations accepted exactly one at the remaining limit; two settlement attempts refunded once; simultaneous identical sends produced one pending turn. No provider calls were made.
- Administrator diagnostics PHP:53 capability/nonce/POST/escaping/allowlist checks passed. JavaScript and SQL redaction include mixed-alphabet token patterns.
- Candidate Chromium156 and Firefox157: paging, retained older pages, explicit retry/replay, feedback, native fallback, clear, four viewport geometries and zero JavaScript errors/model calls. A separate red-green regression drops cached older pages on policy revision changes, including missed intermediate policy changes.
- Installed n8n activation and strict SDK validation passed for private69-node Main and5-node Admin candidates, with zero warnings. Credentials/settings/graph are preserved; final source changes require candidate regeneration before rollout.
- Production still runs the baseline at this point. No candidate plugin/workflow/database change has been deployed.

Final candidate:147/147 Node tests,19/19 Python tests, eight PHP suites/runtime syntax and WPCS passed. Native WordPress site49 assertions and index36 assertions passed; index fixtures/lease/dirty markers were cleaned. Final review found no unresolved concrete defects. The 16-file package SHA256 is d04c2cccde5c278716eec28ea1fdcd88c536bce55de6058090aa75430622cf90. Conventional Markdown, missed policy revisions, mixed tokens, article topics that contain product names, incomplete-source disclosures and automatic failed-index repair have regressions. Broad load and physical mobile/Safari remain untested.
