# VLAN current-read admission after lifecycle callbacks

This additive SOURCE candidate retains the entire accepted five-stage VLAN
lineage ending at `1b8450399215bfe69fe0f3e35576c4de61f25596`. Its original
worktree, commits, source freeze and tests remain intact. Compose this followup
with the existing current-read policy `470005062f564bb64deba911a5e1e417df6ad8ff`
already present in application/provider successor `eb0aeb269f`; this isolated
older VLAN source is not an independently executable validation milestone.

The owning command admits an initial physical transaction before ordinary
public/plugin callbacks. A callback can subsequently enable its actual SESSION
`innodb_snapshot_isolation`. Capturing writer and frame identity does not prove
that later pessimistic queries still have traditional current-read semantics.
The existing four current-read repositories already perform per-read admission.
The new VLAN repository now follows that same policy before locking a membership,
its natural pair, a port, a VLAN or each Entity ancestry edge, after any caller
continuation. It uses the supplied EntityManager connection. Ordinary nonlocking
reads retain their existing connection/routing; PostgreSQL retains its configured
driver and native refusal classification. No new transport, global server flag,
version catalogue, retry, hidden rollback or SESSION repair is introduced.

The new `vlan-current-read-admission.php` contract exercises real public
assignment/update/removal. Registered lifecycle callbacks change the actual owned
SESSION only when its capability is observed, after the nested command frame has
been admitted. Every command must refuse before incompatible pessimistic SQL,
retain the outer physical transaction, all selected full row bags/raw receipts,
loaded model and Session state, and leave the caller's capability selected.
Only the test owner resets that exact SESSION state and retries the same public
command. All five direct pessimistic repository entry points also refuse; ordinary
ORM reads still use the writer. On providers without that mutable capability,
public/ORM and selected native FK controls still run; SESSION cases are explicitly
reported as inapplicable, not simulated through a version guess.

The native invalid-target control proves the absent port and selects its actual
owning FK, checks the configured driver's ForeignKeyConstraintViolationException
and diagnostic constraint, then rolls back only its own frame. A final outer
rollback restores captured memberships/endpoints/history/queue/raw receipts and
read-only schema checks. Legitimate consumed identity gaps are not asserted to
roll back. Failed first causes are preserved when cleanup also fails. No DDL,
migration receipt, historical producer, dependency or original assertion is changed.

Syntax/style, pure contracts, DQL compilation, actual session-change behavior,
native refusal/rollback/retry, original VLAN/current-read/lifecycle contracts,
fresh/populated/full BOTH suites, official MariaDB 11.8 and application/HTTP/live
replica acceptance remain **UNRUN** for this followup. Static ownership traces do
not establish actual native 1020 avoidance or live replica behavior. The public
VLAN persistence continuation still preserves its existing lifecycle/adapter
writes; this is not a completed all-write ORM migration. The modernization goal
remains OPEN.
