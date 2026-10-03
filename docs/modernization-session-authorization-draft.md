# Session authorization ORM draft — validation pending

This isolated batch starts from `e29e33c9cd209dcc84597005375374a2f1416baf`.
It is a source draft. No PostgreSQL/MariaDB contract, application bootstrap,
HTTP request, browser flow, populated upgrade or full suite has been run for it.
It must remain isolated until both-provider validation is available.

Session profile initialization previously queried profile names then all entity
columns once per profile. `ProfileUserRepository::sessionProfiles()` now follows
its declared ProfileUser→Profile/Entity ownership in one scalar ORM projection.
It preserves the keyed session shape, folds duplicate grants with recursive
access winning within the same profile/entity, retains nullable labels, and
orders profiles and entities with MySQL-compatible NULL-first ordering. Equal
names now have explicit ID tie-breakers; the previous tie order was unspecified.
Login's existing preferred-profile/first-profile selection remains in Session.

`GroupMembershipRepository::sessionGroupIds()` follows membership→Group ownership
and selects IDs in membership order. The existing entity service supplies the
Group scope; RecordCriteria compiles it against joined Group metadata. The
explicit active entity array remains authoritative even when show-all is true.
Empty scope denies all groups; a recursive Group belonging to an ancestor can
be eligible. Administrative member-list queries use user profile grants and
are a different policy: those methods are unchanged.

Both repositories keep the connection supplied through `Orm::create($DB)`.
They do not select a replica. The missing-old-table early return is read-only
DBAL schema inspection. No canonical migration or metadata declaration changes.
All three Session application query-adapter sites are removed in this source;
the current integrated inventory remains its own checkpoint until integration
and recomputation.

Session retains lifecycle ownership: authentication/account checks,
password-expiry early return, init_session before grants, default profile/entity
selection, profile cleanup/rights, entity scope/cache updates, group publication
before change_entity, change_profile and menu cleanup. Grant/group mutations
retain their existing authorization, hooks, planning subscriptions and history.
This batch does not add immediate cross-session grant revocation: current
snapshots still refresh at login/explicit initialization, and profile switches
consume the available session profiles. Minimal sessions keep group membership
eligibility without a new profile-grant requirement.

Prepared `tests/database-portability/session-authorization.php` covers actual
public Session reads, password login, personal-token initialization,
password-expiry/no-grant/deleted-account exclusion, minimal sessions,
duplicate/dynamic grants, nullable
ordering/literal labels, root/empty/explicit scopes, recursive groups, hook order
and supplied-connection savepoint visibility/rollback. Its APIRest subclass
only exposes protected endpoints and captures responses: private app-token,
endpoint and session-token checks still execute. It tests profile/entity payloads,
authorized switches, ungranted/foreign rejection and a forged session token.
This is method-level API coverage, not live HTTP evidence.

Source checks completed: four PHP files lint clean; scoped PHP CS Fixer dry-run
and git diff whitespace checks pass. An external
nonconnecting probe compiled both repository methods for PostgreSQL and MySQL,
including recursive and contradictory-empty Group predicates: six statements
were captured before execution, physical connect was forbidden, and all handles
remained disconnected. This proves DQL/type/association compilation only;
result hydration, grant folding and actual application behavior remain pending.

Next validation must run this contract on isolated disposable providers, then
unchanged memberships.php, profile-rights.php, authentication.php,
user-accounts.php and the functional Session class. Exercise existing REST and
XML-RPC profile/entity endpoints (tests/APIBaseClass.php); exercise personal-token
iCal/OIDC where infrastructure supports them. Distinguish method-level contracts,
actual HTTP, browser and live read-replica evidence. A coherent dynamically
discovered full portability suite and final schema inspection on both providers
are required before reporting this application batch complete. No previous
milestone's passing count establishes these results.
