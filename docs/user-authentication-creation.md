# Public creation with canonical authentication owners

`User::prepareInputForAdd` previously supplied legacy `auths_id=0` whenever
that key was omitted. A valid canonical-only `authldaps_id`, `authmails_id`
or `auth_source_code` therefore became contradictory input. The duplicate
query also used the invented zero rather than the actual supplied identity,
so a directory-fallback account could wrongly prevent a different directory
account with the same login. The owning entity's later contradiction refusal
was correct; the earlier application preparation was not.

The User entity now prepares the public input and its logical uniqueness
identity together. The selected canonical column comes from its existing
owning association/discriminator declaration; non-server authentication uses
its existing source-code property. Existing normalization validates the
identity. Legacy absent/null defaults remain zero only when the selected
canonical property was not supplied. Canonical-only input retains the
supplied property, including null, and receives no artificial legacy mirror.
Public preparation uses the resulting identity for the existing repository
duplicate query. Persistence still runs the entity normalization after public
hooks; a conflicting hook result is still rejected.

This changes no public add/update signatures, authorization, profile/grant
calculation, callback ordering, notification, audit, cloning or purge paths.
The original early stop-import and invalid-login refusals remain before
authentication preparation. Existing foreign keys, source consistency checks
and entity lifecycle callbacks remain authoritative. The separate LDAP CI
copied-row correction still must supply coherent owners.

The new public regression creates actual inactive LDAP and mail servers,
then creates fallback, two different LDAP, mail and opaque-code identities
with the same login. It checks canonical-only prepared input, stored owner,
canonical and legacy duplicate refusal without changing the existing account,
and contradictory/wrong-branch no-account outcomes. Existing public-default
preparation assertions remain unchanged.

Validation is source inspection and Git whitespace checks only. PHP syntax,
native both-provider functional execution, broader suites and CI reruns are
pending. No LDAP transport execution is implied by inactive source fixtures.
