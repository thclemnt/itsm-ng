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

## Bounded read-only Ticket preflight (SOURCE implementation)

The source batch based on `5611b65a340e46e2c2d3fd8216846c5376ef06e0`
adds `TicketStatusPreflightRepository`, an injected-EntityManager repository
reading the actual `SpecialStatus` and `Ticket` owners through scalar ORM
projections. It neither changes legacy application callers nor creates or marks
a migration. Scalar hydration deliberately avoids stale managed instances and
never flushes the caller's pending writes. The supplied connection, read routing
and transaction remain owned by the caller; no global adapter is acquired.

`LegacyTicketStatusSnapshot` freezes the legacy positional interpretation:
ascending weight, then ascending real row ID, retaining inactive ordinal gaps.
Its immutable rows retain nullable labels/colors, raw integer active flags and
all identities. The SHA-256 configuration fingerprint covers every exact field
in identity order, independently of input iteration order. Active NULL labels,
noncanonical flags, an empty table and an all-inactive table produce distinct
refusals. Duplicate display labels remain valid identity data.

`TicketWorkflowRoleDecisions` accepts explicit domain/operator-confirmed choices
bound to that fingerprint. It never accepts a label, weight or ordinal as an
identity shortcut. The six historical English-label matches are suggestions:
missing/renamed and duplicate interpretations appear in diagnostics and cannot
confirm a role. Even six unique seeded labels require explicit decisions.
Missing targets, two roles claiming one target and stale decisions refuse.
An explicitly confirmed role may point to a retired row, preserving the meaning
of existing data; this grants no permission to use it for new active tickets.

`TicketStatusPreflight` records each ticket's exact before-code and resolved real
status identity, including deleted tickets and tickets in other entities. Zero,
negative sentinels, unknown positions and real IDs accidentally supplied as codes
remain unknown with owning-ticket diagnostics. Inactive targets remain distinct
from unknown targets. This global inspection is a trusted adoption tool, not a
public entity-scoped ticket collection or a business-transition service. There
is intentionally no HTTP/CLI endpoint granting access to its record-level output.
When exposed later, admission must require global configuration authority and
private output handling; ticket content is not collected here.

The repository compares the complete status snapshot before and after reading
ticket references. A changed fingerprint refuses the result. A matching double
read is only observed consistency: it does not establish a serializable snapshot,
exclude ABA changes, detect concurrent ticket-status changes or authorize writes.
The eventual executing adoption must obtain a maintenance/current-read guard
and verify every ticket before-value under its admitted writer. No locking,
transaction creation, DDL, ledger write or status mutation is performed here.

`ticketOwnerHasNoRefusals()` refers only to these bounded Ticket-owner and role
diagnostics. **It is not adoption readiness.** Profile transition matrices,
RuleTicket criteria/actions, Ticket-template status fields, structured SavedSearch
criteria/metacriteria, sessions/defaults, fixed incoming-role visibility callers,
immutable history and external/plugin input compatibility remain OPEN. Each
owner must supply its own parser and exact before/after interpretation; this
batch adds no global owner registry, generic numeric/JSON replacement or guessed
schema association. A future complete plan must compose those owner results
before any canonical frozen migration or application status cutover.

Two contracts are added for later ROOT execution. The pure contract independently
replays the frozen weight scan, tests non-seed IDs/ties/inactive gaps, explicit
role choices, missing/ambiguous/renamed roles, NULL labels, invalid flags/codes,
fingerprint changes and malformed decisions. The provider contract reads the
real installed owners and ledger, preserves an outer caller frame and an
unflushed ORM insertion, rebinds the global adapter to prove repository capture,
and compares exact status/ticket/ledger storage and history/notification counts.
The provider contract intentionally writes no fixture rows and changes no seed
or migration data. Reordered populated provider fixtures, concurrent admission,
read replicas, browser requests and complete owner adoption still need future
validation.

All PHP execution, compiler/style checks, focused provider contracts and broader
portability/application/browser suites for this batch are **UNRUN** at source
handoff. Source inspection and native Git checks do not count as runtime evidence.
The overall modernization goal and stable-status adoption remain OPEN.
