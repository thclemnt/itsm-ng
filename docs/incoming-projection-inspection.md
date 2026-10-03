# Incoming compatibility projection inspection

`TypedItemMigration` must refuse to replace an ordinary `items_id` compatibility
column when a foreign key references it. A generated projection that does not
need rebuilding retains its existing behavior. Canonical target definitions,
invalid-row checks and retry phases remain unchanged by that inspection change.

`LegacyToOrm::plan()` now supplies one lazy `IncomingProjectionReferences` to its
typed migration stages. That object captures the native incoming foreign-key
catalogue only if a projection needs replacing, and only once within that
read-only planning call. It retains the referenced schema and table, without
restricting the referencing schema. Each standalone stage plan and each
apply/retry replan receives a new capture; nothing is cached on the connection,
migration instance, or in static state across DDL.

PostgreSQL resolves referenced relation and column identities using
`pg_constraint.confrelid` and `confkey`. Constraint names are not identities:
a CHECK and foreign key may share a name in the same PostgreSQL schema. MySQL
uses `information_schema.key_column_usage` and the native referenced column.
Both providers inspect `items_id` references from external schemas/databases.
No current entity metadata or handwritten relationship catalogue supplies this
DDL guard.

The disconnected contract checks lazy capture, multiple target schemas and
tables, duplicate native rows, one catalogue read, and fresh subsequent captures
on the same connection. PHP syntax, source style, and that pure contract were
checked during preparation. This is source validation; native ordered-plan
parity, fresh/populated migration histories, retry/idempotency, full suites, and
any timing benefit still require execution on both providers.

The native `incoming-projection-references.php` contract creates only uniquely
named disposable tables. It checks exact ordered SQL plan equality, cross-schema
incoming references on multiple tables, same-object planning after adding and
removing a foreign key, unrelated referenced columns, an already generated
projection, and the existing wrong-canonical-target diagnostic. PostgreSQL also
checks colliding CHECK/FK names. It executes planned projection DDL only for its
owned fixture tables, and never runs the application-wide adoption migration
or ledger writes.

A separate controlled MariaDB probe at source
`9134e6ef2e78f1d23bb29211c592438269ed4179` exposed a pre-existing projection
installation defect. Ordinary `BIGINT NULL DEFAULT NULL` and generated
`BIGINT NULL DEFAULT NULL` columns compare equal because DBAL excludes
`columnDefinition` from comparison. The native column remained ordinary after
executing its empty projection plan. An explicit owned-table `MODIFY` installed
the expected expression while leaving its DBAL type, nullability and default
unchanged. Baseline-like `BIGINT NOT NULL DEFAULT 0` emitted projection SQL, and
an already generated column correctly emitted none. The probe removed all its
owned tables; its evidence is retained outside the repository as
`typed-projection-native-before-9134-mysql.json`.

The planner now emits the existing frozen `MODIFY` declaration when native
inspection requires installing or rebuilding an existing projection but MySQL's
comparator emits no SQL. Nonempty comparator operations, the expanded-generation
case, and the missing-column ADD path retain their existing behavior. It uses
the same generation expression and comment declaration; no historical snapshot
changes. This fix still requires native execution and full migration validation.

The contract retains the challenging nullable BIGINT case and also checks the
baseline-like NOT NULL/default-zero shape with populated valid Computer links.
It verifies actual native generation, preserved escaped comments and every
native index definition, computed values after changing the canonical subject,
refusal of direct projection writes and duplicates, and same-object/fresh-object
retry. A separate no-argument fixture migration exercises actual base
`apply()` from canonical NULL and a valid legacy nullable BIGINT identity. Its
separately named transitional CHECK admits the input state; the real parent
installs its own final canonical CHECK and FK after copying and projection DDL.
The contract checks NULL refusal under that final CHECK, populated-link
preservation, same/fresh apply retries, and unchanged canonical receipts. These
new controls are prepared source; they have not yet run.


For MySQL, provision a separate disposable database with permissions to create
and reference fixture tables. The CI workflow provisions
`itsm_port_projection_references`; a local environment can select its own
`itsm_port_` database using `PORT_PROJECTION_REFERENCE_DB`. The contract never
creates or drops that database. PostgreSQL creates and removes one random
fixture schema within its existing disposable database. Both providers remove
only tables created by this invocation.
