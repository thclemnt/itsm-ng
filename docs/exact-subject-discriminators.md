# Exact subject discriminator draft

This isolated source draft starts at `741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`.
It has not been compiled, bootstrapped, installed or executed against a database.
It is not an integrated or passing milestone. The overall rejuvenation goal is
still open; no native persistent-goal API has been configured.

## Observed defect

The root worker executed the unchanged managed-item rollback probe, followed by
its independently reviewed diagnostic-only v2, on PostgreSQL and MariaDB. Exact
evidence is in the external files
`managed-item-discriminator-before-v2-741-{pg,mysql}.{json,log}` under
`/workspace/itsm-env/evidence`. Both probes finished successfully with owned rows
rolled back, the same idle physical connection, and unchanged scoped rows,
ledger, schema, projection, constraints and configuration. Sequence and
auto-increment advances were not claimed to roll back.

For the real `ItemTicket` association, PostgreSQL rejected noncanonical raw
INSERT/UPDATE spellings with SQLSTATE `23514` and the exact owned subject CHECK.
MariaDB accepted lowercase, uppercase and one/two trailing spaces. It retained
the supplied bytes, a valid generated Computer identity, real owning foreign
keys, and NULL unselected subject columns. The PHP association resolver and a
real `RecordWriter` update to a second unused Ticket then rejected those stored
identities with `InvalidArgumentException`. Leading spaces, wrong kinds and
unknown kinds were refused by MariaDB's exact named CHECK (`4025`, `23000`).

This is native evidence for one representative family. The inventory of 25
older string-discriminator families is source evidence, not native proof for
every family or either official release engine.

## Declaration and replay ownership

The string key property declares `DiscriminatorKey(exactDiscriminator: true)`.
Shared owning-subject traits carry that declaration where the owning entity
already uses them. Current generated identities and owning CHECK predicates
derive from those properties and their typed associations. MySQL predicates
use a binary discriminator expression; PostgreSQL retains deterministic text
comparison. Numeric authentication and notification-recipient identities keep
their existing declaration and semantics.

Consumable declares its optional zero identity and `date_out` NULL requirement
on the key property. An empty stock subject must have NULL kind, NULL owning
recipients and NULL usage date. Returning assigned stock may clear the usage
date while retaining its valid historical recipient. Document's owning Entity
branch continues to accept the real Root identity zero, while selected NULL is
invalid. Runtime schema inspection no longer calls the frozen historical
Consumable migration to obtain this policy.

`20261010_exact_subject_discriminators` appends a frozen migration and a snapshot
of the existing 25 tables and 293 owning branches. Its replay uses that snapshot,
not today's entities. All preceding 13 historical migrations and snapshots are
retained byte for byte. The snapshot distinguishes inherited baseline/seed
markers from actual preceding replay prerequisites: validated populated
adoption records those inherited markers only after convergence and never
replays installation seeds.

The complete table audit precedes the new migration's ledger write or DDL. The
canonical History preflight invokes it before earlier pending canonical DDL;
the existing Domains source-remapping prerequisite retains its rollback trial
and canonical preflight. A genuinely pending legacy phase may defer missing
owning columns, but each stored exact spelling, legacy target, zero identity,
already present selected owner and unselected owners is still checked. Invalid
data is diagnosed with table, row count and bounded identity samples. Production
code does not trim, rename, discard or repair the source graph.

Each MySQL table uses a single native ALTER to replace the owned CHECK and
change the generated expression while retaining its column, indexes, comments,
native TIMESTAMP storage and incoming/outgoing foreign keys. The same ledger
journals preservation facts and table checkpoints. PostgreSQL installs the
frozen owning CHECK transactionally and preserves its existing exact generated
expression. Nondeterministic PostgreSQL discriminator collation is an explicit
unsupported custom-schema diagnostic, rather than an exactness claim.

The journal also captures the actual native CHECK and generated expression only
after each frozen ALTER succeeds. Its policy key set must exactly match the
processed checkpoint prefix. Every retry compares those native facts before
skipping a processed table, and completion repeats that comparison. A changed
processed CHECK or expression fails closed; only an uncheckpointed interrupted
ALTER is repeated. These journal snapshots derive from executed historical DDL
and do not become a manually maintained runtime declaration or SQL deparser.

The first source-only peer review of the original `11d84` draft found this retry
policy gap before compilation or execution. It also identified a too-broad new
Oracle CHECK refusal gate and exception masking in prerequisite-fixture cleanup.
The original complete source archive, manifest, patch and peer report are retained
externally. This revised draft records native checkpoint policies, prepares real
processed-policy tamper/refusal/restoration controls, preserves primary and
restoration errors independently, and composes the precise reviewed fixture
helper from `69fdbf2f770720ead6f9cf4f1637208a07e7f92c`. The helper path is byte
identical (SHA256 `2f8a3ba5668191a8c8ed11996106e8145f74299967d66713db6fdb97d5b52811`);
its official `3819/HY000` admission requires the actual immediate mysqli
StatementError and complete selected-constraint message. No runtime outcome is
inferred from this source review or composition.

A subsequent source audit found 127 schema-wide catalogue calls in the draft's
uninterrupted 25-table application. The revised inspector shares one full
snapshot only within each read-only planning call. Fresh post-DDL preservation
and the two independently fresh native-policy observations use a bound selected
table CHECK reader. That reader and the full catalogue share the same physical
SQL and enforcement guard; it does not read unrelated column definitions.
The two whole-catalogue planning reads replace the original amplification, while
all data audits and mutation boundaries remain. The fixture similarly shares
one full catalogue per independent read-only facts snapshot. These are static
read-count/design results; no live timing or 300-second-history gain is claimed.

Incoming-FK preservation captures every ordinal of a MySQL composite constraint
that references the compatibility identity, correlated by constraint schema,
child table and name. PostgreSQL captures whole incoming definitions through the
actual referenced relation OID, including all referencing namespaces. The
prepared composite fixture checks both columns. This prevents a same-named
constraint or a filtered-out second ordinal from hiding an ownership change.

## Prepared validation and unresolved gates

`exact-subject-discriminators.php` prepares all 293 canonical branches with wide
targets, native invalid-target/kind/duplicate/projection-write controls, updates
and deletes, Root zero, optional stock, actual stock issue/return/recipient purge,
and actual Ticket asset query/purge calls. It keeps the supplied writer and
restores session/configuration after its owned transaction.

`exact-subject-discriminators-upgrade.php` prepares populated rows in all 25
tables, read-only all-table invalid-data refusal (also while a prerequisite is
pending), a valid custom composite incoming projection FK, native escaped
comment and full storage/index/CHECK facts, a post-DDL/pre-checkpoint fault,
caller-transaction controls, fresh-instance retry and idempotency. Its owned
diagnostic fixture deliberately substitutes CHECK(TRUE) to admit corrupt rows;
this is not proof of a genuine thirteen-version populated adoption. Original
native definitions and the original raw completion receipt are captured and
restored, with no completion receipt restored over failed schema cleanup.
Created dependency rows are tracked and removed individually in reverse order;
there is no FK disabling, cascade truncation or counter reset.

The optional `PORT_EXACT_FAULT_TABLE` selects an actual frozen table checkpoint
for a subsequent root-owned phase matrix. The default contract includes the
ItemTicket incoming-FK phase. The existing runner dynamically discovers the
new contracts and retains its 300-second per-contract limit.

Before integration, the root worker must validate:

- PHP syntax/style and both focused contracts against both existing providers;
- native generated-column MODIFY with the valid custom incoming FK, including
  both MySQL/MariaDB restrictions and unchanged exact comments/indexes/storage;
- every table's interrupted checkpoint and retry, partial-canonical legacy
  controls, and a genuine populated thirteen-version upgrade;
- fresh full-history replay and complete discovered portability/application
  suites on both providers, with final native schema evidence;
- historical Software/Processor composition: Software's two future exact keys
  and its frozen migration stay separate and use the shared declaration API;
- PostgreSQL 18 / Oracle MySQL 8.4, browser, remote CI and live replica evidence
  when the root worker grants the corresponding environments and windows.

No supported incoming foreign key is silently dropped or refused as a
workaround. If native MODIFY cannot retain that valid ownership, the proposed
operation is a blocker requiring a coherent design correction before any
successful-replay claim. Performance and all provider behavior remain unrun.
This is an ownership consistency/migration batch, not completion of the broader
ORM conversion or retirement of remaining legacy application SQL.

The final source review also identified two fail-closed fixture/retry gaps. The
journal now rejects non-array preservation and processed-policy entries before
any conditional comparison or checkpoint skip. Prepared controls on both
providers retain valid cache key sets while supplying a NULL entry, invoke
both public plan and apply paths, and restore the exact raw retry receipt (or
its original absence). PostgreSQL's deliberately malformed incomplete receipt
is diagnostic input, not a synthesized completion record. Native schema and
populated rows must remain unchanged. The all-branch fixture now retains its
primary exception, records rollback failures independently, and always restores
session/configuration before reporting either failure. These revised controls
remain uncompiled and unexecuted under the root worker's resource hold.

The root worker subsequently checked the immutable source-ready manifest
`9f83789e674716f7850d2b873368855733eb664de521a5201280fecd23b1e729`:
all 15 PHP files passed syntax checks. The explicit project formatter found one
multiline-call formatting hunk in the migration; its initial invocation without
the project configuration and its nonzero result are preserved separately.
That exact formatting correction is included in the subsequent source freeze.
Syntax/style results for that newer freeze must be recorded independently;
these static checks do not validate any provider, migration or application flow.
