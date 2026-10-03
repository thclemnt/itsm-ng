# Session and HTTP source integration

This batch composes the complete Session draft through `f3777170fd` and the five
HTTP scenarios at `0fe5cafedc` onto exactly
`9134e6ef2e78f1d23bb29211c592438269ed4179`. Its production and test source is
prepared for validation; no application, database, HTTP or browser execution has
validated this composition. The older source-preparation notes remain historical
checkpoints, not evidence that the composed branch passes.

The isolated branch is `th/exp/postgres-session-integrated`. The original Session
and HTTP worktrees remain clean at their original commits. All eight commits were
cherry-picked in order without conflicts. Git retained the already integrated
`GroupMembershipRepository::sessionGroupIds()` and `Session::loadGroups()`;
there is no second group-query implementation. The existing Boolean preparation
and post-success `User::refreshSubmittedBooleanPreferences()` remain unchanged.
The net legacy-query retirement is the two profile-grant adapter requests; the
group request was already retired in the base. This is not a refreshed whole-app
query inventory or a live replica-routing result.

## Source boundaries

The profile repository uses owning ProfileUser associations, preserves nullable
labels and NULL-first ordering, breaks ties by real identifiers, and folds
duplicate recursion grants per profile/entity. Root Entity ID0 remains an actual
grant. Fresh managers retain the supplied adapter's DBAL connection. Missing
legacy grant tables clear the previous snapshot without creating tables. The
separate empty-database contract preserves explicit PostgreSQL port/schema/SSL-mode
and both providers' configured TLS verification, key, certificate, CA, CA-path and
cipher settings on its own adapter instead of reverting to connection defaults.
The fixture uses an empty, separately provisioned database and makes no schema or
data writes. Forwarding settings is source evidence; local plaintext execution
will not prove verified TLS or mutual-TLS negotiation.

Direct entity grants allow their own entity; a descendant or recursive selection
requires a recursive grant. The already integrated explicit-empty entity scope
remains authoritative over a stale show-all flag. Existing profile fallback and
`init_session`, `change_entity` and `change_profile` hook ordering remain in the
actual Session lifecycle. A provisioning plugin can still add a missing grant
before initialization loads grants.

Personal-token authentication checks account/activity/strict-date admission,
then actual accepted initialization and authorized requested scope. The planning
controller retains its owner/group/right checks and no longer overwrites accepted
requested scope with a second default-profile initialization. Refused eligible
attempts restore prior session values, identifier, active/closed state, translation
presence/object and locale. Normal plugin effects outside this reversible context
remain outside that restoration boundary. `inc/api/api.class.php` is byte-identical
to the base; this composition does not change REST external-user-token policy.

Cookie expiry is inspected only when reusing an existing unforced token. A NULL
date makes that existing token outdated; forced or absent-token creation skips
expiry parsing. Public User persistence must succeed and the repository's stored
credential bytes must match before a new credential is returned. This preserves
the existing cookie hash/personal-token formats, native timestamps and public
User hooks; it does not promise rollback of unrelated accepted hook writes.

## Source checks and remaining execution

At source checkpoint `509d33a85e`, PHP8.2.33 lint passed all 12 changed PHP files,
the configured formatter required zero changes, and whitespace checks passed.
TypeScript no-emit compilation of the new spec and its imported helpers reported
zero diagnostics using read-only installed declarations; no dependencies were
copied. Twelve actual repository queries compiled across PostgreSQL and MySQL
platforms through a connection whose physical `connect()` is forbidden. Every
query reported `connected=false`. These are syntax/compilation checks, not
database, folding, authorization, controller or HTTP behavior results.

Lexical method hashes confirm that only the intended Session methods, cookie
rotation, profile projection/order helper and scalar credential read changed.
All 114 protected baseline/history/connection/Group source files are unchanged.
Frozen historical definitions, migrations, seeds, Boolean enforcement and schema
inspection retain their base bytes. The portability runner discovers 182
contracts, including the four new Session/token contracts, with its original
300-second per-contract limit. The new HTTP spec declares five scenarios and
preserves the existing 60-second browser limit and retry configuration.

The runtime owner must run `session-authorization.php`,
`session-legacy-grants.php`, `personal-token-authorization.php` and
`cookie-tokens.php` on both providers, plus the relevant original
Session/Auth/User/Profile/Group applications and actual controller paths. Provision
an empty owned `itsm_port_*_session_legacy` sibling with grants; it must differ
from the installed database. Then run all five HTTP scenarios on each provider
with zero skips, matching CLI/server configuration and private variable directory,
authorized REST client and the guarded fixture router. Inspect warning/error logs
and prove actual API graph cleanup plus CLI settings/plugin/manifest restoration.
The fixture manifest and browser traces contain ephemeral credentials and must
remain private. Exact setup is in `session-token-http-validation.md`.

Fresh/populated/idempotent installation and complete portability/application
suites remain required on the eventual final source, along with final native
schema inspection and remote CI. No additional stage or schema change is introduced
here. The broader rejuvenation goal remains open.
