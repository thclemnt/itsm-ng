# Tree-parent cache fixture boundaries

`tests/database-portability/tree-parents.php` retains its original rollback-owned
public lifecycle checks for every frozen `optional.TREE_PARENTS` family. Reads in
that caller transaction must repeat the authoritative descendant graph without
publishing a shared cache entry. An isolated Memory backend does not make the
supplied database connection idle.

The original `Warm descendant cache` assertion runs in a separate committed
probe on an explicitly idle connection, using the same isolated cache backend.
All nine historical tree families receive public assigned-ID creation, scalar
descendant and ancestor warming and repeat reads, public rename propagation,
public subtree move, and invalidation of the warmed owning scalar keys.

The committed graphs have NULL roots and no native tree ancestors. Assigned IDs
exceed existing tree and matching item-history IDs; each creation checks both
are absent. Cleanup verifies exact owned name markers and purges leaves before
their parents through the public lifecycle. Models that disable item history
can still produce move-hook history; any remaining owned item logs are removed
by exact log ID, item type and item ID. Complete native rows for every historical
tree table and the entire history table must match the captured baseline after
cleanup, including derived cache columns. The original cache and session values
are restored on success or refusal.

The historical DDL reconstruction, orphan and cycle preflights, interrupted
uniqueness replacement, retry, and canonical schema restoration remain byte for
byte unchanged from `e5c81032bb9f81122670f1898a0ed4a338476306`. This change is
fixture-only; no cache-backend exception or production admission rule changes.
Preparation is source-only. Native execution on PostgreSQL and MariaDB remains
with the parent worker.
