# Session authorization ORM draft — validation pending

Originally drafted from `e29e33c9cd209dcc84597005375374a2f1416baf`, this
isolated batch is now rebased onto the exact combined lifecycle checkpoint
`f3db6577c7324e04305c2a8cbbfbb4960ddf7c38`, after its earlier rebase onto
`4dbc9f7c2edeafb5e54c65d9c9a5144f9132246c`. The conflict-free rebase preserves
both feature patches unchanged (`git range-diff` reports equality):
`dd840a7ba3` and `8689fdf1b2`. The original head `c00d7219f8` remains on
`th/exp/postgres-session-authorization-before-f3-rebase`. Its inherited Transfer,
native transport and shared lifecycle changes remain intact, together with f3's
managed Kanban metadata and Document_Item preparation/refusal diagnostic controls.
The separate cache bootstrap repair has not been composed into this branch.
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
authorized switches (including real root-entity group membership),
ungranted/foreign rejection, a forged session token and a forged app token.
This is method-level API coverage, not live HTTP evidence.

Source checks completed: four PHP files lint clean; scoped PHP CS Fixer dry-run
and git diff whitespace checks pass after the f3 rebase (PHP 8.2.33 / fixer
3.95.27). The inherited Kanban/Document/Transfer/native source controls are
byte-identical to f3. An external
nonconnecting probe compiled both repository methods for PostgreSQL and MySQL,
including recursive and contradictory-empty Group predicates: six statements
were captured before execution, physical connect was forbidden, and all handles
remained disconnected. This proves DQL/type/association compilation only;
result hydration, grant folding and actual application behavior remain pending.
The six-statement nonconnecting compilation was rerun after this rebase and
recorded in `/workspace/itsm-env/evidence/session-authorization-f3-offline-sql.json`.
The refreshed source/backup refs and file fingerprints are in
`session-authorization-f3-source.json` in the same evidence directory. No
database, bootstrap, dependency copy, asset build or HTTP/browser job was run.

Next validation must run this contract on isolated disposable providers, then
unchanged memberships.php, profile-rights.php, authentication.php,
user-accounts.php and the functional Session class. Exercise existing REST and
XML-RPC profile/entity endpoints (tests/APIBaseClass.php); exercise personal-token
iCal/OIDC where infrastructure supports them. Distinguish method-level contracts,
actual HTTP, browser and live read-replica evidence. A coherent dynamically
discovered full portability suite and final schema inspection on both providers
are required before reporting this application batch complete. No previous
milestone's passing count establishes these results.

Integration order is deliberate: validate and integrate the BooleanDomains
enforcement batch before these Session persistence changes. Aliased ORM scalar
results do not themselves perform PHP boolean conversion: PostgreSQL's DBAL
driver supplies native booleans, MySQL supplies stored zero/one flags, and the
repository explicitly casts and folds `ProfileUser::is_recursive`. Historical
MySQL-family integer storage could otherwise admit malformed values such as 2.
The authoritative native boolean constraints and canonical readiness/adoption
preflight own that rejection. No separate grant-flag registry or Session-specific
coercion workaround is added. This Session contract preserves its valid 0/1 grant
folding controls; it has no malformed historical-2 fixture. Historical invalid
flag coverage belongs to the preceding boolean-domain contract.

Independent source review identified an inherited authorization defect in
`Session::changeActiveEntities()`: selecting a descendant with a nonrecursive
request incorrectly admitted any granted ancestor, even a nonrecursive grant.
The owning Session boundary now admits a direct nonrecursive selection or a
selection covered by a recursive direct/ancestor grant. Root ID zero remains a
real direct grant. Refusal occurs before scope/group publication and change hooks.
This correction predates the new repository behavior; it is a separate source
fix, not a regression attributed to ORM conversion.

The same boundary serves `front/central.php` and `front/helpdesk.public.php`,
Session profile/default-entity selection, REST and XML-RPC's inherited API
entity-switch endpoint, and Toolbox deep-link selection. Prepared public Session
and actual API-method controls now refuse child/tree access under nonrecursive
grants while preserving the complete prior session and hook trace; they retain
direct parent/root/child and recursive ancestor/subtree success. These new
controls remain unexecuted. Method-level app/session-token checks still do not
establish full HTTP/IP admission, rejected personal-token account behavior, or
live reader-connection routing.

The earlier repository review's positive root-group,
root API switch and invalid app-token suggestions are also prepared controls;
these remain unexecuted. App-token admission uses the existing method fixture
client map and unchanged private checkAppToken; initApi IP/client-discovery and
HTTP dispatch require subsequent live HTTP validation. No token gate is bypassed
by the endpoint probe.

Additional prepared controls populate real ProfileRight rows with distinct
Computer permission masks. Actual password login and API-method profile switches
then exercise `Session::haveRight`, `haveRightsAnd` and `haveRightsOr`: any-bit
intersection, zero/unknown denial, read/create/update isolation and restoration
when switching back. Ungranted profile, foreign entity and forged app/session-token
refusals preserve actual permission decisions, the complete prior session, PHP
session identity, real generated CSRF/IDOR tokens and the existing hook trace.
These controls are source-only and unexecuted; they do not establish HTTP/IP
admission or live replica behavior.

The [personal-token admission follow-up](personal-token-admission.md) prepares a
separate inherited account/scope correction plus real provisioning/refusal/context
contracts and an empty sibling legacy-grant-table control. It preserves actual
initialization hooks and explicitly bounds reversible session/language restoration
versus plugin side effects. This follow-up remains unexecuted on both providers.
