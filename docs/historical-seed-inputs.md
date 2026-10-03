# Explicit historical seed inputs under strict native writes

Current checkpoint: `741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`.
The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
[The handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
separates these results from the retained a736 full failures and the earlier complete
green b66 checkpoint. Later passages marked pending describe their earlier
preparation checkpoint unless superseded by the precise checkpoint results above.

## Later complete historical replay

The 9dcd MariaDB contract reaches all 19 explicit empty comments, completed-seed
no-op/later-edit preservation and the populated updater, then exceeds the
unchanged 300-second budget during fresh replay. That is still a failed run. The
complete 913 history subsequently passes PostgreSQL 125.692s/MariaDB 292.689s,
retaining these assertions and the original retry/idempotency controls. Original
frozen baseline/seed bytes remain unchanged; the addendum supplies only the two
identified required TEXT comment omissions, not general implicit defaults. The
3.422987s strict seed failure remains recorded. Full a736 runs finish with
PostgreSQL 178/179 and MariaDB 151/179; native PostgreSQL has 13 complete versions
and no differences, while MariaDB retains two missing Boolean CHECKs. Current
required-field and historical-CHECK fixture source repairs are PENDING provider
execution, separately from the earlier seed/history passes.


Historical preparation and diagnosis follow; statements of unexecuted gates
below describe those earlier snapshots.

Root's MariaDB `migration-history.php` run at `5f04bce9a651af3e653c4cb0a126a28e9b53349d` failed after 3.422987 seconds, before adoption, at the frozen seed insert into `glpi_rulerightparameters`: required `comment` had no default. The intentional STRICT_ALL_TABLES connection policy exposed an omission previously masked by permissive writes. The retained failure is `/workspace/itsm-env/evidence/boolean-integrated-mysql-migration-history.log`.

A connection-free audit of all 1,741 frozen records against both frozen DBAL baselines found exactly two omissions: thirteen `glpi_rulerightparameters.comment` and six `glpi_ssovariables.comment` inputs. Both are required TEXT without a MySQL default. Their frozen PostgreSQL definitions already default to the empty string, with no missing required inputs on that provider. A separate `20261001-seed-inputs.json` addendum now explicitly supplies those two historical values; the original baseline, seed rows and older history files remain byte-identical. No current entity defaults or general implicit-default rule is introduced.

The existing Seeds service merges only absent keys from this addendum. Supplied values remain intact and supplied required NULL refuses. The whole prepared seed plan validates field existence, original Boolean rules and every missing required/no-default field before writing its incomplete receipt or seed data. Unknown future omissions diagnose instead of acquiring guessed values. Seed writes and completion still share the existing transaction/ledger, interrupted DML still rolls back, and a completed receipt remains an early no-op that preserves later edits. Populated adoption still records inherited seeds without replaying them.

The raw populated fixture audit parsed PHP syntax without evaluating application code: all twenty-two literal-table insert sites before actual adoption had no missing required/no-default input, including the three record unions. Two dynamic sites were traced separately. The ledger-restoration site inserts complete rows captured by SELECT * before its table was removed. The two appliance tables in the dynamic invalid-subject loop provide their required kind/owner inputs through the explicit tuple and literal invalid-row arrays. No raw fixture values need inferred defaults; those invalid target/kind/Boolean cases and every existing assertion remain intact. External source evidence is `historical-seed-inputs-omissions.json` and `historical-populated-fixture-input-audit.json` in `/workspace/itsm-env/evidence`.

Source checks pass: the connection-free contract has 3,534 assertions covering the entire input inventory, exact additions, original explicit values/order, NULL versus absence, refusal of a newly required undeclared input, and unchanged frozen file hashes on both platform builds. Three PHP files pass syntax and configured formatting (zero fixes), and whitespace checks pass. No application bootstrap, database jobs, dependency copies or asset builds ran for this batch.

The existing full history contract adds actual native checks for all nineteen empty comments, completed-seed receipt no-op with a retained later edit, and that edit surviving the actual populated updater. Its original interrupted seed rollback, complete canonical replay, account/audit/relationship/sequence preservation, fresh retry/idempotency and 300-second budget are retained. These new native assertions are prepared but unexecuted. Fresh and populated replay on PostgreSQL and MariaDB/MySQL, native strict/no-warning seed writes, retry/idempotency, complete portability/application suites and final native schema inspection remain required before claiming validation of this repair.
