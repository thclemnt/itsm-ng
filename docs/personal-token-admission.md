# Personal-token admission and requested scope: source preparation

This is an isolated Session follow-up. No provider, application, HTTP, browser or
iCal client execution has validated it. It must follow Boolean-domain enforcement
and retain the older grant, hook, root-zero and nonrecursive descendant controls.

Source review found two inherited defects, independent of the ORM grant rewrite.
`Session::authWithToken()` returned a matched User even when actual `Session::init()`
refused the account or its final grants. It also used trusted `loadEntity()` for
requested scope, bypassing the grant boundary. Its actual caller,
`front/planning.php`'s personal-token iCal branch, trusted that User and then
reinitialized the default profile, overwriting requested scope. Own-user export
could therefore continue after a refused authentication.

The token boundary now checks the same account/activity/strict-date predicate
used by Session initialization, before resetting an existing session. Empty or
unmatched tokens and inactive/deleted/outside-date accounts return false without
publishing new scope/groups or invoking initialization hooks. Eligible tokens run
actual Session initialization in its existing order: `init_session` still precedes
grant loading, so plugins can provision legitimate grants there. The final
authentication and current interface must be accepted. Requested entity/recursion
then goes through `changeActiveEntities()`, the existing grant authority. Planning
does not repeat default-profile initialization afterward. The existing export
owner/group/right checks remain in the planning caller.

Final no-grant or rejected-scope results, and thrown initialization hooks, restore
the prior complete session, PHP session identity and active/closed state, original
translation object/presence and Intl default locale. The token boundary owns that
reversible publication; it does not introduce another grant catalogue or pre-read
grants before provisioning hooks. Eligible attempts may run normal initialization
and default-scope hooks before final refusal. A rejected requested scope invokes
no additional change hook. Earlier plugin database/filesystem/network effects and
opaque plugin globals cannot be rolled back by restoring session context.
Translation cache warming remains valid shared cache content and is not erased.
PHP cookie/header behavior still requires actual HTTP validation.

Prepared `personal-token-authorization.php` calls actual public token
authentication on real persisted users, grants, groups and ProfileRight masks.
It rejects empty/unknown tokens, inactive/deleted/future/expired accounts,
nonrecursive descendants, foreign entities and ungranted recursive scopes.
It compares real generated CSRF/IDOR state, session identity/status, group/scope
and translation/locale restoration. It retains actual no-grant hook execution,
reopens a previously closed session to prove its stored grants/tokens were
restored, and preserves an originally absent translation global. Deterministic
controls invoke the shared account predicate on a real loaded user at exact date
equality and one-second valid interiors; public calls cover past/future/NULL dates.
It retains real hook-provisioned grant admission, a throwing hook and a persisted plugin
marker that deliberately remains after refusal. Positive controls preserve NULL
date boundaries, explicit valid dates, direct and recursive descendant/subtree
grants, real root Entity ID0, eligible groups and actual planning rights.
This is public-method evidence when executed, not live iCal/HTTP-client evidence.

The separate `session-legacy-grants.php` uses an actual empty sibling database,
never a renamed/dropped installed grant table. Provision
`PORT_SESSION_LEGACY_DB` (default `itsm_port_session_legacy`) with the test role's
access before running it. The name must have the `itsm_port_` prefix and
`_session_legacy` suffix, differ from the installed database, and contain no tables.
The contract proves an absent-table snapshot clears real prior grants without
warnings or writes, remains idempotent and refreshes again through the same
installed adapter. CI preparation creates that empty sibling. These assertions
do not claim that other current tables can safely be queried in a legacy schema.

All new controls are source-only and unexecuted. Next run isolated both-provider
contracts, original Session/Auth/User and related profile/group tests, then actual
REST/XML-RPC endpoints and personal-token iCal owner/group/scope/header flows.
REST app/session tokens, API-token login, HTTP client/IP admission and live replica
routing are distinct paths and remain separate validation gates. The broader
modernization goal remains open.
