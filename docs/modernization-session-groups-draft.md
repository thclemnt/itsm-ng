# Session group publication draft — provider validation pending

This isolated group-only extraction starts from
`1edcd19ecc83b18ee890acfcbf0f025d4afba5d5`. It changes only the session group
read and its existing owning repository, with a dedicated public contract.
No database/bootstrap, dependency/asset copy/build or browser job has run.
Three PHP syntax checks, scoped formatter and whitespace checks pass. The
prepared provider contract and original functional Session test remain unrun.

The preserved broader PostgreSQL log at source
`5f04bce9a651af3e653c4cb0a126a28e9b53349d` reports expected groups
24,25,27,28,30,31 but actual 30,31,24,25,27,28 in Session::testLoadGroups. All six
identities remain; the original adapter query has no ORDER BY. Its chosen live
query plan was not inspected, and Boolean schema work is not proven to cause the
ordering difference. The original unchanged test expects the groups in append
order. Actual planning consumers also serialize session group arrays into event
identity keys, so stable publication serves an application purpose.

Session::loadGroups now asks GroupMembershipRepository for a scalar projection of
the current user's owning Group memberships, explicitly ordered by membership ID.
The existing GroupMembership.users/groups associations and Group entity/reference
metadata remain authoritative. RecordCriteria compiles the existing explicit
Group ownership restriction through joined metadata. No profile-grant join,
global relationship registry, schema/migration change, SQL wrapper or driver
rewrite is added. This order is an explicit stable publication rule, not a claim
that unordered historical SQL guaranteed it. Integer IDs match existing ORM
membership projections.

The supplied glpiactiveentities array stays authoritative even with a stale
show-all flag: [] denies all, [0] selects the real Root entity, and recursive
ancestor groups qualify only through their existing is_recursive semantics.
Do not substitute getActiveEntityScope's all-entity null mode or the administrative
member query's ProfileUser grant scope. Minimal users can have direct memberships
without profile grants; another user's authorization never supplies membership
for the current user. The method clears stale groups before publishing once.

Orm::create($DB) retains the supplied physical writer or read-route connection.
No replica/writer selection is introduced. Entity-change/profile hooks, ordinary
login/token admission and current-user update lifecycle remain where they were.
The broader Session authorization/token family is deliberately not included or
validated by this extraction.

Prepared session-groups.php invokes the real public method with Group creation
order differing from membership identity order. It covers repeated stable lists,
recursive/flat/empty/Root0 scopes, ancestor nonrecursive exclusion, current-user
isolation, eligibility independent of ProfileUser grants, writer identity and
uncommitted add/delete/savepoint rollback. A second configured physical handle,
marked as a read route, cannot see the primary's uncommitted fixture graph; the
public method must retain that supplied handle. This is actual query-routing
coverage against the same endpoint, not a live replicated service claim. The
contract restores the complete session, cache/debug counters, global adapter and
owned transaction levels; it creates no schema DDL or committed application data.
It tests this controlled-session read boundary, not login/token/HTTP admission.

Next validation: run the dedicated executable contract on both providers, then
unchanged Session functional tests and adjacent memberships, authorization,
planning/task, schema and routing checks. Broader original application failures
remain pending until their actual rerun; no green result is asserted here.

When the validated group-only commit is integrated, rebase the future Session
stack (currently f3777170fd1e9f44f6fffba48d3280c64ea1b01d) onto that base. Its two
extracted production fragments are identical. Retain the integrated group method
and loadGroups hunk while dropping only their now-duplicate changes; preserve all
remaining profile/token/persistence/API controls and their separate validation.
Do not cherry-pick that entire pending family to repair this ordering failure.
