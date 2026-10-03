# Incoming compatibility projection inspection

`TypedItemMigration` must refuse to replace an ordinary `items_id` compatibility
column when a foreign key references it. A generated projection that does not
need rebuilding retains its existing behavior. Canonical target definitions,
invalid-row checks, migration SQL, and retry phases remain unchanged.

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
checks colliding CHECK/FK names. It executes fixture DDL, never migration DDL.

For MySQL, provision a separate disposable database with permissions to create
and reference fixture tables. The CI workflow provisions
`itsm_port_projection_references`; a local environment can select its own
`itsm_port_` database using `PORT_PROJECTION_REFERENCE_DB`. The contract never
creates or drops that database. PostgreSQL creates and removes one random
fixture schema within its existing disposable database. Both providers remove
only tables created by this invocation.
