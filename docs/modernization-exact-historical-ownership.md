# Historical subject fixtures and native restoration

Source checkpoint: the isolated repair starts at `515232177fee500aa9382df32f3cc391881ce270`. The current suite discovers 215 contracts. Its PostgreSQL full run passed; the MariaDB full run reported the original native refusal assertion in `exact-subject-discriminators.php` for `glpi_alerts`. The failed variant and native cause are still awaiting the bounded original-contract observer. This source repair does not claim a corrected native result.

The source audit identifies 18 fixtures which replay earlier subject migrations on the supplied main connection, covering 24 of the 25 frozen Exact tables. These tests legitimately reconstruct historical inputs, but their cleanup reinstalls the earlier collation-sensitive CHECK/projection while the completed Exact receipt remains present. This is a concrete source mutation path; it does not by itself identify the runtime failure. The Exact upgrade fixture faithfully preserves its captured input and cannot repair an earlier fixture's policy drift. The Document scope already has captured native restoration.

The order below is derived from the runner's discovery rules and the checkpoint tree, rather than a maintained test catalogue. Exact upgrade is contract 66 and the original Exact rejection contract is 67.

| Contract | Fixture | Captured table scope | Reconstruction |
| --- | --- | --- | --- |
| 6 | alert-subjects | alerts | ALTER |
| 7 | appliance-assets-schema | appliances_items, appliances_items_relations | CREATE |
| 9 | appliance-check-retry | appliances_items, appliances_items_relations | CHECK |
| 25 | change-problem-assets | changes_items, items_problems | ALTER |
| 31 | consumable-recipients | consumables | ALTER |
| 36 | contract-assets | contracts_items | ALTER |
| 82 | infrastructure-assets | certificates_items, domains_items, items_clusters | ALTER |
| 91 | itil-project-subjects | itils_projects | ALTER |
| 92 | itil-subjects | itilfollowups, itilsolutions | ALTER |
| 124 | object-lock-subjects | objectlocks | ALTER |
| 127 | operating-system-subjects-schema | items_operatingsystems | CREATE |
| 139 | physical-placements | items_racks, items_enclosures | ALTER |
| 144 | planning-recall-subjects | planningrecalls | ALTER |
| 154 | project-assets-schema | items_projects | CREATE |
| 158 | project-team-members | projectteams, projecttaskteams | ALTER |
| 165 | reservation-assets | reservationitems | ALTER |
| 195 | ticket-assets | items_tickets | ALTER |
| 214 | vobject-subjects | vobjects | ALTER |

Every table name above has the `glpi_` prefix. Each scope is checked against the existing frozen Exact declaration. This diagnostic inventory is not a runtime relationship registry. Plugin imports create owned source tables and use the current core importer; full migration-history and plugin adoption use separate configured auxiliary owners. Those paths are not replacements for the affected main-table policy. The existing default whole-scope Exact upgrade fixture remains unchanged in its ownership behavior.

Each affected fixture captures its original native policy and schema facts before any older producer is invoked. Its outer ownership boundary removes only the captured Exact receipt; original historical SQL, malformed-data tests, callbacks, retries and application assertions remain intact. The Project fixture delays removal until its historical rebuild starts, preserving its earlier test of an existing installation with only the Project migration pending.

ALTER-only fixtures explicitly skip cleanup-table serialization. Their captured native columns, indexes, references, other CHECKs and incoming-owner guards remain authoritative. This avoids treating ObjectLock's automatic TIMESTAMP as a table reconstruction problem. The three existing CREATE cleanup calls use a declaration derived from the actual captured table and actual native indexes; the historical schemas used by their test lanes remain unchanged. All actually stored generated columns retain their native expressions, storage, comments and nullability, including OS normalization keys. Unsupported generation storage refuses before alteration. The existing OS Boolean fixture retains ownership of its three Boolean CHECKs; no competing CHECK restoration path is added.

Native restoration removes any newly completed Exact receipt before fallible DDL, restores the captured CHECK/projection and verifies the original full facts without additional exclusions. It then compares every other raw ledger row with the capture before restoring the original raw Exact row. Drift in other receipts or native facts leaves Exact completion absent. The outer boundary retains the fixture exception it received, with an independent native-cleanup diagnostic if restoration also fails. Existing inner cleanup bodies and their behavior remain unchanged. Terminal success output moves after native verification where it was the script's final statement; existing inner output still relies on the process result for success.

The source proof reconstructs every original affected fixture and the original helper exactly after removing the explicit ownership anchors and reversing three cleanup-declaration arguments. All historical producer/snapshot definitions, versions, runtime application code, original native matchers, assertions and the 300-second contract limit remain unchanged. The implementation has not been compiled or exercised by its source author.

The next required evidence is the bounded failing-lane observation, then the corrected fixtures in actual order on genuinely fresh canonical PostgreSQL and MariaDB databases. Run all 18 affected originals, the original Exact upgrade/rejection controls and the full discovered suite, inspect final native policies and raw ledger/schema, and retain both provider results. Current fresh/focused passing receipts from the parent checkpoint do not validate this new source. The broader modernization objective, held component20/1080 batch, actor and whole-parent clone boundaries, application/API/browser gates and live routing/CI work remain open.

## ROOT observed cause after source freeze

The immutable source-review checkpoint above correctly records the then-pending Alert cause. ROOT subsequently reproduced it: the original Alert fixture and Exact-upgrade fixture pass, but their sequence changes captured binary Alert policy to an enforced non-binary CHECK/projection and admits lowercase `cartridgeitem`. The independent post-full Enclosure admission remains a separate observed failure. See `docs/modernization-handoff.md` and the private `final17-5152-full-and-historical-policy-cause-checkpoint.json` for exact controls/results. Corrected native execution remains pending; the earlier failed full suite is retained.
