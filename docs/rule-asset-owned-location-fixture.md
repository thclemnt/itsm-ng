# RuleAsset uses real fixture relationship targets

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

## Later original assertion-runner evidence

At 0c the combined Auth/Rule/Ticket scope passes five classes, eight methods and
842 assertions on each provider with zero void/skipped methods. The first b05
foreign-key exception is retained in the archived auth-rule log; the current log
name now contains the passing run. At a736 the complete broader PostgreSQL
selection also passes 45 classes/283 methods/11,338 assertions with zero
void/skips. MariaDB's broader a736 selection also passes 47 classes, 329 methods
and 15,399 assertions in 301.7060797s, zero void/skips. Both full 179 suites
have completed with failures: PostgreSQL 178/179 and MariaDB 151/179. Final native
PostgreSQL converges; MariaDB has two missing Boolean CHECKs. Current candidate
repairs are PENDING native validation. These results retain every configured
RuleAsset kind and actual
public target checks; they do not claim browser/HTTP execution of the rule.


Historical preparation and diagnosis follow; statements of unexecuted gates
below describe those earlier snapshots.

Root's original Auth/Rule application scope at
`b05dfcf808fa6d49d27016ad21ff399e11bae1a4` reached seven methods with zero void
methods or skips, then raised one foreign-key exception in
`RuleAsset::testTriggerUpdate()` at the Computer insertion. Its Location action
hard-coded identifier 1 even though named fixture locations receive actual
allocated identifiers. A later SoftwareLicense branch independently assumed
Software identifier 1. Existing foreign keys correctly refuse missing targets;
the failed run is retained as `boolean-integrated-application-auth-rule-pg.log`
in the cloud evidence directory. The first six tests passed before that exception,
but the failed run does not establish complete application success or their
separate assertion totals.

The test now creates its own Location through the public model inside the
existing DbTestCase transaction, in the actual `_test_root_entity` scope, and
asserts successful positive allocation. The Location action receives that actual
identifier; repository-wide caller inspection finds one `_createRuleLocation()`
call and its one private definition. Every asset must reload with exactly the
selected Location, strengthening the prior any-positive-location assertion.
SoftwareLicense resolves the existing `_test_soft` dataset Software identity and
asserts it is positive before supplying its owning parent.

The original rules, all configured asset kinds, trigger condition, `_auto`,
`is_dynamic`, names and per-kind comment expectations remain intact. There are
no filters, skipped kinds, FK suppression or production changes. DbTestCase's
existing rollback owns new Location, assets and rules; no committed fixture
or implicit identifier 1 is introduced.

This source-only correction has not run against either provider. Syntax,
formatting and whitespace checks are preparation evidence. Rerun the original
Auth/Rule scope on both providers and retain its first failure before the final
current-source portability/application checkpoint.
