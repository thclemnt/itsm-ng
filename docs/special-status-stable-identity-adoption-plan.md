# Ticket status identity needs an adoption batch

SOURCE audit of `53cf6297473bc31400a0777ff905bd79f1e65698`; no application or
database execution. The remaining `SpecialStatus` direct persistence must not
be converted by replacing SQL with a repository that preserves ordinal identity.

`Ticket::getAllStatusArray()` at `inc/ticket.class.php:3556` reads all status rows,
sorts weights, then exposes keys as sorted positions. Inactive rows still consume
positions. The current scan breaks ties by the earlier `id`-ordered input.
`glpi_tickets.status` stores this position, not `glpi_specialstatuses.id`.
The six frozen initial seed IDs happen to equal their initial positions; this
coincidence is not an invariant for populated installations.

`SpecialStatus::oldStatusOrder()` discovers workflow roles by mutable English
names and puts their positions into session keys. Session initialization calls
it after selecting a profile and supplies numeric fallbacks. Ticket input,
solved/closed permission checks, dates, automatic assignment, re-opening,
statistics and notification-related lifecycle depend on these values.
`CommonITILObject::getStatusKey()` also falls back to names for icons.
`Profile::displayLifeCycleMatrix()` and `CommonITILObject::isAllowedStatus()`
index serialized transition policy by these numeric values. RuleTicket criteria
and actions expose `dropdown_status`, and actions deliberately suppress computed
status. Templates, searches and external inputs need an exhaustive numeric-use
inventory before any adoption is executable. Problem and Change statuses have
their own semantics and must not be assigned ticket status foreign keys.

The current management methods mutate the status table, then
`keepStatusSet()` scans all tickets and matches names before directly updating
their numeric status. Duplicate/renamed labels make that mapping ambiguous.
Inactivation can remove a label from the new list; forced deletion does not
call the remapper at all. Updates are not one transaction, can reclassify tickets
while their statuses are being changed, and bypass ticket hooks, history and
notifications. The management front/AJAX callers rely on header/session setup
without an explicit mutation-right check at each operation. These are source
findings, not verified exploitation or upgrade-data findings.

The coherent replacement should make status identity, display order, activation
and workflow role separate entity-owned concepts. `SpecialStatus` should declare
a real boolean flag and a persisted immutable role where appropriate;
`Ticket` should own a real association. Reordering, recoloring and renaming must
not update tickets or rewrite their lifecycle policy. Referenced statuses should
retire rather than disappear; deletion must explicitly select a destination
status through the authorized Ticket lifecycle if a business transition is
required. A status domain service owns configuration authorization and validates
the complete policy before any writes. Session role projections derive from
the persisted declarations, rather than a second manually maintained catalogue.

The next concrete SOURCE batch is an adoption planner, not executable DDL:

1. Inventory every current reader/writer of ticket status codes and every stored
   policy, rule, template, saved-search and integration payload that contains them.
   Separate present mutable configuration from immutable audit history.
2. Freeze the exact legacy positional algorithm and snapshot the complete
   status rows during preflight, including tie order and inactive gaps. Resolve
   each existing ticket code and every mutable policy reference to one real ID.
   Unknown positions, missing/duplicate workflow roles, invalid boolean flags,
   conflicting labels and concurrent configuration changes produce actionable
   diagnostics without guessing. Distinguish harmless duplicate display labels
   from ambiguous semantic roles; names alone are not an identity rule.
3. Specify frozen additive migration stages on the existing canonical ledger,
   with adoption origins and retry checkpoints. Entity declarations are the
   runtime authority; historical conversion definitions stay frozen. Do not
   regenerate earlier history or mark current metadata as already migrated.
4. Add the owning association and stable role/order projections, convert mutable
   code-bearing configuration in the same reviewed batch, and retain immutable
   historical evidence with its original interpretation. Establish an explicit
   compatibility policy for API/plugin numeric status inputs before cutover.
5. Exercise populated reordered/deactivated/duplicate-label installations,
   malformed references, tied weights, role renames, concurrent configuration,
   authorized/unauthorized transitions, notifications/dates/history, clone/purge,
   caller rollback and nontransactional DDL retry on both engines. Fresh installs
   and original full suites must converge on the same schema and behavior.

This plan is OPEN. No status association, new migration, scalar registry,
simulated foreign key or SQL-wrapper service is introduced by this audit.
