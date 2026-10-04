# Functional tree cache ownership

The current76cd MariaDB 11.8 functional run fails four DbUtils tree-cache tests
and `Entity::testChangeEntityParentCached`. Their ordered tree results precede
assertions that require publishing shared or persistent derived cache entries.
`DbTestCase` already owns an actual outer transaction at that point. The current
application deliberately reads authoritative edges and excludes publication of
that uncommitted tree; these cache oracles describe the older ownership policy.

The functional tests retain all original ordered ancestor/sons results and all
original public Entity add, reparent and leaf-purge operations. They now require
the real caller frame, zero durable cache entries after explicitly cleared
private reads, and absent shared entries after those reads. Cache-enabled cases
seed controlled wrong scalar and aggregate payloads in the normal configured
backend. Correct ordered results must come from current edges, and private reads
must invalidate those payloads without publishing replacements. No fake backend,
production policy change or cast of actual results is introduced.

The public Entity case also seeds the predicted unused new Entity key before
add, proves the returned identity matches that existing model's MAX+1 allocation,
and permits the old payload to remain or be invalidated while forbidding a new
tentative path. It then checks the original reparent and both affected parents using the actual
readers. A new owned nested frame reparents the leaf back, verifies ancestry and
both parents' sons, rolls back that exact capability, reloads the real Entity,
and verifies the outer parent and ordered results. A rollback error retains the
original failure through the existing `MutationRollbackFailure` ownership type.
The original framework owns the outer rollback and cache teardown.

Committed cache behavior is covered by the existing unchanged
`tests/database-portability/tree-transaction-cache.php`, which is mandatory
validation for this batch. Its real idle scalar cache admission, durable cache
generation, outer and nested rollback, committed public reparent, recursive
authorization, aggregate-cache invalidation, normal backend and native row/cache
restoration controls remain intact. This batch neither duplicates that graph nor
commits the framework's generic outer transaction to obtain an idle read.

Validation is SOURCE ONLY. ROOT must run the five reported functional cases,
both ordinary and cached Entity reparent variants, the unchanged portability
contract on both providers, and the relevant broader functional/portability
suites. All production code, frozen historical definitions and existing
portability contracts remain byte-exact; no candidate execution is claimed.

Each deliberately stale shared payload is read back through the real configured backend before the public operation or private tree read. These admission checks prove that bypass and invalidation are exercised; the leaf sons payload names a different existing entity.
