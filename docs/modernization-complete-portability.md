# Combined portability checkpoint

The latest complete portability checkpoint is
`b66ec2f474973e779de1043a8a7d308e36531552`. Both discovered 168-contract suites pass,
including the actual schema-check execution order and populated migration/retry
contracts. This supersedes the older 147/147 portability checkpoint without
changing its recorded browser evidence or erasing intervening failures.

| Recorded check | PostgreSQL | MariaDB |
| --- | --- | --- |
| Fresh CLI installation | Pass, 38.326s, no PHP warnings | Pass, 97.676s, no PHP warnings |
| Complete discovered portability suite | 168/168, 830.445s, exit 0 | 168/168, 1,780.542s, exit 0 |
| Final native schema | 12 complete versions, no pending/diff, 1,057 FKs | 12 complete versions, no pending/diff, 1,057 FKs |
| Original broader application at b66 | 262/262 methods completed; one iterator assertion failure | 308/308 methods completed; same single failure |
| Corrected Computer class at 336ef4 | 7/7 methods, 350 assertions, zero void/skips | 7/7 methods, 350 assertions, zero void/skips |
| Corrected broader application at 336ef4 | 262/262 methods, 10,422 assertions | 308/308 methods, 14,483 assertions |
| Original notification queue at 336ef4 | 1/1 method, 10 assertions | 1/1 method, 10 assertions |
| Rebuilt complete browser at 3ac825 | 14/14, 267.931s, zero skips/retries | 14/14, 268.749s, zero skips/retries |

Both native schemas contain 357 mapped core tables and 358 actual tables,
including the migration ledger. Expected deprecated clone notices occur in each
full suite; these are recorded rather than represented as warning-free full
suite logs. Platform-specific generated expressions, triggers and CHECK
expressions are not all compared by the generic schema comparator; native
inspection and meaningful migration/application contracts remain separate proof.

The original application failure is `tests\units\Computer::testGetFromIter()`,
line 377: NULL is not a string. Read-only native probes at the original source
reload an ID-only iterator into a full 28-field Computer row and retain SQL NULL
on both providers. Nullable names are valid in the entity and frozen baseline;
production hydration was not changed. The test now owns its named records and
separately checks a real NULL-name record with an independent persisted serial
marker, exact identifiers and cardinality. Its first revised PostgreSQL run
failed with a missing-entity exception; supplying root Entity ID0 explicitly
corrected that fixture before the passing focused runs. The original failures
and this first revised exception are archived, not discarded.

Application source `336ef4fec61341437399471647a6fe6e7992efb7` changes only
`tests/functional/Computer.php` and its documentation relative to b66. A manifest
verifies 1,620 application, install, portability, CI and browser files are
byte-identical. The complete suites ran at b66; this manifest establishes source
preservation and does not claim a second full execution at 336ef4.

The corrected broader application and queue runs have zero void methods or skips.
The first complete browser runs at 336ef4 each passed 13/14: the Transfer test
required exact outcome text from a controller element that also contains its Back
link. Retained traces prove the result was already visible after navigation;
timeouts and retry settings were unchanged. At 3ac825, the controller-owned full
result assertion passes both focused flows and all fourteen browser scenarios,
including unchanged-data refusal, the actual retained transfer list, API edit,
successful retry, financial roles and cleanup. The assets were rebuilt before
browser execution. Only this browser test and its note changed after 336ef4.
Final native inspections still find twelve complete versions, no pending versions
or differences and 1,057 FKs; all 354 scoped PostgreSQL generators use BIGINT.

Remember-me login emitted header warnings during browser execution: NULL cookie
dates were parsed before header delivery. A bounded Session/token repair is
prepared separately and unvalidated; passing browser assertions do not certify
that cookie flow. Official PostgreSQL 18.6/MySQL 8.4.11 execution remains pending.
Remote CI is unverified.
Prepared Boolean/Session/Software/Processor branches are outside this checkpoint
and require their own provider and combined validation. The overall goal remains
open.

Evidence is saved outside the repository under `/workspace/itsm-env/evidence/`:
`rejuvenation-complete-portability-result.json`, full `rejuvenation-complete-suite-*`
logs, fresh-install/native summaries and logs, archived original broader application
logs, `computer-iterator-original-native-{pg,mysql}.json`, the first revised
PostgreSQL class log, passing `computer-iterator-fixed-class-{pg,mysql}.log`, and
`computer-iterator-fixed-source-manifest.json`, corrected broader application/queue
logs, rebuilt asset/typecheck logs, archived failed browser traces, final full
browser/native logs, `rejuvenation-complete-application-result.json` and
`rejuvenation-complete-final-result.json`. These are local cloud results;
remote CI and replica behavior have not been validated by them.
