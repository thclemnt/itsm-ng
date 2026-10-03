# RuleAsset uses real fixture relationship targets

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
