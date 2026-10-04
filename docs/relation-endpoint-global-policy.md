# Relation endpoint global and loaded-item policy

The original `2fe3ca280dfd727d21207e6b4fb9c13b3079db25` software-subject contract reaches its read-only assertion after the native subject, discriminator, foreign-key and recursive-ownership checks. The fixture changes `software` and `monitor` grants, but SoftwareLicense declares `license` as its global right. The administrator licence grant therefore survives that fixture restriction.

Correcting the fixture key alone does not resolve the policy defect. Root's five real public `Item_SoftwareLicense::can(-1, CREATE, ...)` controls on PostgreSQL and MariaDB observed the following results on that original source:

| Monitor grant | Licence grant | Original result |
| --- | --- | --- |
| READ | Administrator 255 | Allowed |
| READ | READ | Allowed, although both endpoint `canUpdate()` methods return false |
| READ | None | Refused |
| READ and UPDATE | READ | Allowed |
| READ | READ and UPDATE | Allowed |

`CommonDBRelation::canRelationItem()` checks loaded endpoints through `canConnexityItem()`. That helper evaluates the global method for a dynamic itemtype, but evaluates only the loaded-item method for a fixed class. A fixed SoftwareLicense inherits a loaded-item update check that establishes entity access; it does not establish its global licence update right. The static relation check sees the unresolved dynamic endpoint as potentially writable, so it cannot close this gap before the loaded endpoint is known.

The repair composes each loaded endpoint result with the existing `canConnexity()` global policy using its declared endpoint role. Both write and view decisions use that same authority. The existing one-writable/other-visible combination, force-both logic, attachment exceptions and entity coherency checks remain in their original locations. There is no new permission registry or UPDATE-mask shortcut. Specialized methods remain authoritative: Ticket `canUpdate()` can admit an assigned actor with OWN, and HAVE_VIEW roles still require visibility rather than UPDATE. The declared DONT_CHECK recipient role is also passed to the global view check, retaining its deliberate exclusion of recipient global grants.

This changes relation permission decisions reached through `can()`/`check()` and their loaded-item policy. It does not add a new permission gate to trusted public `add()`, `update()` or `delete()` lifecycle operations. The Software allocation form continues to require its separate global Software UPDATE preguard before checking the actual relation. A model-level owner-write/licence-read decision therefore does not grant access to that form by itself. REST/model authorization and the front-controller preguard remain distinct boundaries.

The original software contract now sets the actual `license` grant explicitly for its writable-licence, both-read-only denial and writable-owner converse checks. All earlier assertions remain. The new `relation-endpoint-rights.php` contract prepares real persisted endpoints and exercises the five observed cases, loaded write/view decisions, stored read/mutation permissions, force-both, required attachments, Project's declared asset-view role, Ticket OWN without UPDATE, recipient DONT_CHECK without User grants, foreign Ticket scope, anonymous email actors, and the optional notification-template creation form. Positive cases execute public lifecycle writes and inspect their actual owning fields; authorization-only refusals assert unchanged row counts. Fixtures use one disposable rollback frame and restore Session/configuration.

These are source-prepared controls, not executed validation. PHP compilation/style checks, both-provider focused contracts and relevant original relation/actor/application suites remain required. Existing broad portability suites must then run on the integrated source; no full-suite or modernization completion is claimed. Frozen schema definitions, migration history and native driver ownership are unchanged by this repair.
