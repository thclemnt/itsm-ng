# Domains plugin modernization checkpoint

Implementation is isolated on `th/exp/postgres-domain-import`, based on main
`448699cfbb7d4847a73edc36eabf0f3da1042fb3`. This batch replaces the old name-based
command, adds the missing Domain properties and document association, and supplies
a separate frozen data prerequisite for historical adoption. The overall ORM /
PostgreSQL modernization goal remains open. Final combined validation is pending;
the results below distinguish earlier checkpoints from the latest reviewed code.

## Authoritative current model

`Entity/Domain.php` declares the nullable, restrictive Supplier association and
real `is_helpdesk_visible` boolean. The supplier is the domain registrar; an
Infocom financial supplier remains a separate role. The actual Domain form,
search, scoped Supplier tab and count use these properties. Helpdesk choices
respect false visibility while ordinary asset associations and existing links
retain their established behavior.

DomainType has a dedicated `domaintype` permission. The frozen integration stage
copies existing global dropdown grants only where this dedicated grant is absent.
Plugin import never broadens global dropdown permissions or silently unions
divergent Domain / DomainType grants.

`Entity/DocumentItem.php` owns Domain through nullable `domains_id`, independently
of its Document owner. The appended frozen document stage expands the existing
33-subject projection to 34 subjects, retains its original owner, timeline and
uniqueness semantics, and installs a real restrictive FK. Domain document links
participate in existing public queries, explicit link cloning and purge behavior.
Domain scalar cloning retains its prior behavior; no new automatic attachment
clone policy is claimed.

Current field types, nullability, booleans and association ownership come from
entity properties. `BaselineSchema` derives missing ordinary current columns and
explicit indexes from Doctrine metadata; it does not introduce a manually
maintained supplier / helpdesk registry. Historical baseline, seed and 20261001
snapshots remain unchanged.

## Supported historical source

The verified InfotelGLPI/domains 2.1.0 source is pinned at
`e628ee87b84a87867365dbc77d06738e9e74a246`. Its actual identity is singular
`PluginDomainsDomain`. DomainType uses `PluginDomainsDomainType`; the explicitly
recognized historical `PluginDomainsDomaintype` spelling is also supported.
Namespace discovery is case-insensitive, followed by strict spelling validation;
unknown, padded or differently cased identities diagnose on both providers.

Relevant pinned source evidence:

- `sql/empty-2.0.2.sql`: scoped types/domains, seven default asset kinds and expiry
  config; source tables use InnoDB.
- `hook.php:84`: completed upgrades move inline notes into `glpi_notepads` under
  the singular identity, then remove the old column.
- `hook.php:192`: the old plugin profiles table is removed; permissions are in
  `glpi_profilerights`.
- `setup.php:42`: documents, contracts, tickets, technicians, helpdesk visibility,
  notifications and links are registered.
- `setup.php:74`: external Accounts bindings can exist. Unknown external source
  roles require a verified adapter and refuse rather than silently disappear.
- `inc/domain.class.php:43`: default links are Computer, Monitor,
  NetworkEquipment, Peripheral, Phone, Printer and Software.
- `inc/domain.class.php:720` and `hook.php:187`: plugin global expiry delays and
  `(PluginDomainsDomain, DomainsAlert)` scheduler must be reconciled with core
  per-entity policy and the pre-existing `(Domain, DomainsAlert)` scheduler.

The supported completed export contains exactly the declared columns of
`glpi_plugin_domains_domaintypes`, `glpi_plugin_domains_domains`,
`glpi_plugin_domains_domains_items` and `glpi_plugin_domains_configs`. Residual
old profile tables / inline note columns, partial exports and unsupported roles
refuse with concrete diagnostics. This is not general support for every plugin
version or extension.

Source data is read in explicit column order and ascending ID order. The frozen
fingerprint covers format plus those four raw tables; non-null scalars become
strings, booleans become `1` / `0`, and SQL NULL differs from empty or literal
`NULL`. Historical source tables remain intact. Registration version text is
retained as provenance; supported layout and role validation determine the format.

## Operational boundary and policy

Deactivate only the source `domains` plugin through its compatible historical
application before maintenance/export. Keep it inactive and stop all source and
application writers until replay/import finishes. States ACTIVATED (1) and
TOBECONFIGURED (3), ambiguous registrations and nonexact directory spelling refuse
before writes, including current importer retries. The importer preserves source
registration/state and other plugin records. It does not invoke deactivation,
uninstall or cache hooks inside the transaction.

Advisory locks serialize cooperating migrations/importers, not arbitrary writers.
MySQL cannot keep row locks across missing-ledger bootstrap DDL; quiescence remains
required through that gap. Public lifecycle hooks can have external delivery or
file effects that database rollback cannot undo. Database rollback tests disable
external delivery and do not claim distributed transactional behavior.

Preflight requires exact policy equivalence, not an assumption that core defaults
are unconfigured. Reconcile intended root/child expiry flags and delays explicitly;
only `-2` means inherited delay policy. Divergent existing dedicated grants,
cron settings or overlapping active notification scopes refuse with recovery
instructions. Matching core cron settings are preserved, the old source cron is
retired in place, and custom notifications/templates/translations/delivery rows
retain IDs, content and roles. Disjoint active core rules remain intact. Queued
content is not rewritten to pretend embedded old URLs have been repaired.

DomainType and direct Supplier must be in the domain's entity or a recursive
ancestor. Asset and guarded relation coherence follows the actual public
CommonDBRelation rule: same entity, recursive Domain ancestor of the other
endpoint, or recursive other endpoint ancestor of the Domain. Document, Contract,
Certificate, Project, Problem and Change owners participate. Knowledge-base
audience, writable-ticket exceptions, financial children, notes and audit/class
roles retain their distinct policies. Current owners derive from association
metadata; scalar Impact endpoints use actual public entity capabilities through
ORM reads. Frozen role/capability declarations are historical input, not a runtime
relationship registry.

Plugin calendar DATE values still enter deliberately retained native MySQL
TIMESTAMP / PostgreSQL timestamptz fields. Native range overflow diagnoses before
writes. This batch does not resolve the broader calendar-versus-instant schema
policy. Source dates use the native destination session timezone. Deferred
Document TIMESTAMP rows are captured in UTC with explicit `timestamp_timezone`
context, then restored under UTC with the caller timezone restored in `finally`.

## Canonical ORM import

`src/Domain/DomainPluginSource` reads unmapped source tables through DBAL.
`DomainPluginSnapshot`, `DomainImportValidation`, `DomainIdentityAdoption`,
`DomainImportPolicy` and `DomainPluginImport` plan and persist the current domain
graph using entity ownership, repositories and ordinary public lifecycle methods.
`itsmng:migration:domains_plugin_to_core --dry-run` previews without writes;
execution rejects `--skip-errors` and requires complete canonical History,
canonical SchemaCheck, the configured write connection, transactional core/ledger
storage and a fully valid graph.

Types, domains and individual links keep their source IDs through
`addWithAssignedIdentifier()`. Name/content equality never establishes ownership.
Occupied destination IDs refuse without this operation's own completion receipt.
Individual DomainItem links remain distinct; category relations are not asset
relations. Direct registrar and actual financial children stay separate.
Automatic financial creation is suppressed only for source-owned children and
its temporary configuration is restored. Ordinary prepare/rule/post-add/audit
hooks remain in use.

Source primary audit rows retag only for imported subject IDs. Retired unmatched
subjects retain their original kind, even when unrelated core IDs match.
Linked audit labels retag as class/display roles without rewriting historical
values or row timestamps. Owning discriminators and subject associations change
together, not through a kind-only update.

The existing migration Ledger stores the elective
`20261006_domains_plugin_import_v1` receipt with fingerprint, counts, source
registration, intentionally retained roles and exact source grant tuples. Exact
retries preserve later edits, purges and canonical permission changes without
replaying hooks. Changed exports/grants or new scalar / encoded legacy profile
bindings refuse for explicit reconciliation. Sequences advance monotonically.

## Frozen pre-adoption prerequisite and replay

A post-canonical importer cannot unblock legacy plugin identities rejected by
old typed stages. `DomainsPluginAdoption20261006`, its frozen snapshot/policy and
`history/20261006-domains-plugin.json` provide a separately scheduled DBAL data
operation under the existing History lock. Current entities and mutable current
importer code do not define historical targets or replay behavior.

Any known pinned source table or a plugin identity binding triggers a read-only
source plan; missing source is a true no-op with no elective completion marker.
Existing legitimate core Domain document links also use the general prerequisite
without any plugin. Preview exposes stable-ID insert/remap counts, format,
fingerprint and deferred documents, and explicitly says canonical audits are
still deferred. User text beginning with SQL keywords is never displayed as
migration SQL. A complete canonical installation uses the ORM importer instead.

The adopter first locks, remaps and validates every pending canonical preflight
in a rolled-back DML transaction. Invalid unrelated core data leaves an initially
ledgerless MySQL database without even an empty ledger. Only then may bootstrap
DDL create a validated pending marker. Actual remap, full deferred data receipt
and canonical audit share one transaction; failures roll back together. On retry,
a complete trial receipt is exposed only within the rollback transaction so
pending schema stages can validate deferred values rather than confuse bootstrap
state with data.

`20261006_domains_plugin_adoption_v1` freezes supplier/visibility values for the
later `20261006_domain_supplier_helpdesk_rights` stage. Document links keep exact
full original rows in that same receipt, or in
`20261006_domain_documents_deferred_v1` for core-only links. Deferral refuses
actual incoming FK owners, including custom/plugin and cross-schema edges.
Partial ownership layouts must match known frozen columns; ambiguous layouts
refuse. Narrow destinations refuse wide exports before DDL; already-wide
storage keeps wide IDs without replacement allocation.

The mandatory `20261006_domain_document_subjects` stage expands the frozen old
33-subject generated CASE and restores Domain links after projection, CHECK and
FK phases. MySQL requires explicit native MODIFY for expression-only expansion
because DBAL's comparator does not compare generated expression text. Old stages
keep their original targets/replay. Every interruption phase is journaled; data
restoration, `documents_restored` and completion receipt commit atomically.
Completed history never reapplies row snapshots over later edits or purges.
The supplier/helpdesk stage similarly guards InnoDB before DDL and applies frozen
deferred values once. No second ledger or reinstall seed replay is introduced.

## Validation checkpoint and remaining work

Executed against isolated PostgreSQL 15.19 / MariaDB 10.11.18 databases, not the user's
historical local services. At this branch before OS integration, metadata and idle
native catalogs count 357 mapped tables, 1,051 FKs and 402 real boolean fields.

- Fresh actual application installs: PG 37.579s / Maria 103.138s.
- Application-owned 11 focused contracts passed on both providers, including
  supplier/helpdesk/permission/document/clone/purge flows, the expanded 34-subject
  contract, neighbors, metadata and final read-only schema comparison.
- Document stage: PG 66 / Maria 77 assertions passed; native wide projection,
  CHECK/FK/uniqueness, all DDL phase retries, UTC instant/caller timezone, invalid
  receipts/data and real second-row restoration-trigger rollback were exercised.
- Final supplier/helpdesk stage: PG 1.346s / Maria 3.150s passed, including
  MyISAM Domain/ProfileRights refusal with unchanged rows and no journal.
- Canonical current importer after scope/case/profile review: PG 63.561s /
  Maria 256.195s passed. Prior PG 45.258s / Maria 182.588s passes
  predate those review fixes and are not final-code evidence.
- Final frozen populated adoption passed PG 80.676s / Maria 272.883s within
  the unchanged 300s contract budget, including the final
  scope/case/unbound-export/CLI/wide-ID additions. Its supported final populated
  retry replay took PG 43.291s / Maria 76.336s. Earlier final-code runs before
  column-inspection batching passed PG 89.435s / Maria 270.543s. A Maria
  268.377s attempt completed replay/schema checks but failed exact Unicode
  content: a minimal probe proved the fixture source itself had inherited
  latin1 and lost those bytes before adoption. The fixture now explicitly
  matches the pinned UTF-8/collation and asserts exact source content before
  import; both final content assertions remain unchanged.
- Frozen populated adoption earlier passed PG 93.012s / Maria 250.260s, proving
  ledgerless invalid graph rollback, atomic receipts, canonical DDL retry, UTC
  core/plugin documents and final convergence. Those runs predate the final
  scope/case/unbound-export/CLI/wide-ID additions; they are earlier checkpoints.
- The strengthened complete `migration-history.php` contract passed PG
  127.212s / Maria 276.356s within the unchanged 300s budget, including a
  core-only Domain document through actual populated `db:update` with no plugin
  tables. Before batching, PG passed 130.259s and Maria completed every assertion with exit 0
  in 309.810s, exceeding the unchanged 300s runner budget; this is a validation
  failure, not a passing full-history milestone. Production prerequisite/plan
  costs were profiled: the required-reference audit repeatedly built full DBAL
  column definitions merely to check existence. A per-call native name snapshot
  retains the frozen policies, query order and every orphan count, with no cache
  across DDL. Read-only comparisons proved all 261 table column sets and all 563
  unhandled required-reference eligibility checks equivalent on both providers,
  including generated, reserved and mixed-case columns. The measured auditor
  cost changed from Maria 6.09749s to 0.08007s and PG 1.45091s to 0.12537s.
  A visible-schema probe with an additional PostgreSQL search-path schema and
  shadow table containing mixed-case/generated columns also retained all 261
  sets and 563 eligible references. Native discovery uses all explicitly visible
  schemas, matching DBAL. Final Maria history phases were 17.573s for baseline,
  seeds and invalid-data audits; 151.263s for actual populated `db:update`;
  7.187s for completed adoption/sequence retries; and 99.948s for actual fresh
  installation interruption/retry. PostgreSQL's final run precedes diagnostic
  timing output only; all assertions/control flow are identical. Existing fresh
  replay, invalid references/flags, partial upgrade, DDL/seed interruption,
  sequence and idempotency assertions remain intact.

Current source inspections, focused database contracts, browser tests, full
portability suites and remote CI are separate evidence. The earlier main147
portability/browser checkpoint and later main152 OS validation do not validate
this unintegrated batch. No live replica, remote CI, external notification delivery
or complete modernization claim is made.

The Domain application contracts invoke actual public PHP flows and inspect
buffered form/tab HTML: dedicated DomainType grants, separate registrar Supplier,
helpdesk visibility, Document picker/tab, binding clone and purge. The importer
also executes the actual CLI. These are not HTTP or browser verification of this
batch; those checks remain required on the final combined checkout.
A new Domain browser spec and guarded private CLI companion are prepared; PHP
lint/style, TypeScript and Playwright discovery pass. Its Supplier/financial
separation, visibility edits and Document-tab association have not yet run in
a browser.

Next step: integrate with current main preserving OS/atomic-purge and sequence
work, then
run the discovered coherent full portability suites and application/browser
validation on the resulting checkout. Update these figures from actual results.
Racks and remaining legacy application persistence remain later coherent work.
