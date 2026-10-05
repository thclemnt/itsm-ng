# Complete historical native timestamp property declarations

SOURCE candidate based on `12cf7a79129e02cc0d2afde301468bed393d3814`, preserving
its seven prior tree/clock composition stages. No PHP, compiler, metadata
execution, database, fresh/upgrade, full-suite or browser validation has run
for this candidate. The implementation goal remains OPEN.

The frozen DBAL baseline contains 472 native MySQL TIMESTAMP declarations in
208 mapped tables. All 472 resolve to exactly one current datetimetz entity
property. Seven already own their physical policy. This batch declares the
remaining 465 beside their existing properties; no field-name/type heuristic,
global temporal catalogue, custom hydration type or migration version is added.
The frozen baseline is a historical test oracle, not a runtime declaration.

The complete SOURCE classification found:

- 462 remaining nullable native TIMESTAMP NULL DEFAULT NULL properties with
  no special comment or automatic touch. Only NativeTimestamp is added.
- CronTask.lastrun retains nullable native storage and its frozen `last run date`
  comment. The missing comment becomes an option on this owning property.
- CronTaskLog.date and NotImportedEmail.date retain their existing nonnullable
  CURRENT_TIMESTAMP default options. Their defaults, PHP nullable backing
  values and insert/update eligibility do not change; only the marker is added.
- The seven earlier declarations, including ObjectLock's sole automatic-touch
  policy, writable generated readback and outcome synchronization, are unchanged.

None of the historical native declarations has an explicit fractional precision.
MySQL's unqualified TIMESTAMP therefore retains its native zero-fraction storage;
PostgreSQL retains the existing Doctrine datetimetz declaration. The metadata
contract compares precision/scale/length and generated DDL with the frozen
baseline on all three platforms. The native inspection contract derives expected
fractional precision from the actual canonical DBAL column DDL, including each
provider's unqualified TIMESTAMP meaning, and compares native catalog facts.
This is no claim that MySQL TIMESTAMP and DATETIME have equivalent semantics.

The existing attribute driver already uses marked properties for MySQL/MariaDB
native declarations and preserves PostgreSQL datetimetz. The current expected
schema builder already replaces marked existing columns from SchemaTool output.
All 472 current instant columns now use that path; historical type/default/comment
definitions no longer supply fallback runtime intent for this cohort. The baseline
and canonical migration replay remain byte-identical, including their historical
native clauses, trigger statements, ledger and seeds. No DDL or data repair runs.

Two grouped implementation commits distinguish the ordinary nullable cohort
from cron/import default/comment policies. Field types, nullability, PHP defaults,
generated/read-only flags, timestamp write binding, lifecycle hooks, ORM hydration,
timezones and clock outcome handling are not altered. Attribute declaration
coverage alone does not prove installed-schema or application convergence.

The original seven-property metadata contract keeps every original verify call
and assertion message, including the literal seven-count assertion. Its one
test seam now derives declarations from its existing explicit cohort classes,
instead of treating that checkpoint as the global property set. The new
native-timestamps-coverage-metadata.php contract separately derives the complete
expected set from frozen DBAL native columns and requires exact equality with
all current property declarations, with no omissions or extras. It compares
entity-only SchemaTool, frozen and current column semantics, unchanged write/
hydration flags and the exact historical automatic-touch set on MySQL, MariaDB
and PostgreSQL. The original independent NativeTemporalProbe still proves an
unmarked property is DATETIME on MySQL; none of its fields or assertions changed.

The new native-timestamps-coverage.php contract is read-only. It retains full
SchemaCheck and automatic-touch comparison, inspects every declared core instant
through native catalogs, verifies actual type/precision/null/default/comment and
MySQL ON UPDATE policy, and checks exact MySQL native-core timestamp coverage.
Plugin tables remain outside core ownership; PostgreSQL's ordinary unmarked
datetimetz fields are not incorrectly forced into the historical native set.
Catalog, raw canonical ledger and connection timezone must remain unchanged.
Original native-timestamps.php and its probe, timezone/DST, default/null,
ObjectLock same-manager readback/no-op/update/expiry and drift assertions remain
byte-identical. No assertion or schema comparison has been suppressed.

Required next gates: independent full-source review; owned compiler/style and
both metadata contracts; native inspection and original temporal/schema/lock
contracts on both providers, with actual provider-version precision/range facts;
genuine canonical fresh installs and populated upgrades/retry; the discovered
full suites on both providers and final native schema/data inspection. Queue,
cron, audit, authentication and lifecycle flows still require meaningful runtime
evidence. Original seven-clock native/source milestones are not new validation.
