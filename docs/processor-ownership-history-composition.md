# Processor ownership and canonical history composition

Status: source candidate only. No PHP, lint, style, compiler, application bootstrap, database, server, browser or CI job was executed for this candidate. The modernization goal remains open.

## Composition and historical boundaries

The candidate starts from b7b83dba0938bea85ae5ba84e91b441bd5d562ea, the clean composition of actual ROOT 30a4cce27ac6b493210c2d5867376293506563ab with Exact discriminator migration 14 and Software migrations 15/16. This supersedes the earlier proposed base6897: actual ancestry retains the newer session-clock and tree-parent fixtures/docs without manually copying them. No production source from current ROOT, Exact or Software is replaced with an older candidate.

Processor mapping/application provenance is 4488eb2884bcfedb5045b82a078ab1e286914c7d and 134a97a242972c2b72128bbb7b3535a55f1c3634. The supplied incoming projection-reference context change in eee456d76fb626ae2b28037aa37801f4fe173a3b is retained in the new frozen processor producer. Original candidates and proposal/evidence remain immutable.

ProcessorSubjects20261012 appends receipt17 after Exact14 and Software15/16 in the existing History/Ledger. Its JSON is the byte-exact original unpublished processor snapshot under a new chronological filename. Dedicated ProcessorTypedItemMigration20261012 and ProcessorStagedTypedItemMigration20261012 freeze the accepted Software producer behavior plus explicit processor changes. Earlier producers and snapshots remain byte-exact; History.php is the additive dispatcher change. Frozen duplication here records historical replay, rather than a second runtime mapping catalogue.

Both canonicalPlan (including the source-domain rollback validation trial) and direct upgrade preflight audit processor data before older nontransactional DDL. Applying17 follows15/16. Stock blank/null legacy identities zero/null normalize to null kind, null owning Computer and generated items_id0. Assigned kinds and identities are exact, selected null/zero/missing targets fail with source-row diagnostics, and partial canonical disagreement refuses before copying. The supplied IncomingProjectionReferences context refuses destructive projection replacement when a custom incoming FK uses items_id at any ordinal. Existing journal phases, PostgreSQL transactions, MySQL DDL retries, native generated-column inspection, comments, payload and sequence convergence remain in the same history.

## Ownership and actual callers

ItemDeviceProcessor owns a nullable Computer association. Its items_id property declares DiscriminatorKey(emptyValue:0, exactDiscriminator:true); itemtype and a read-only generated compatibility projection follow that declaration. Computer ownership, device definition ownership, binding entity, inventory payload and access scope have distinct roles. Duplicate assignments are legitimate. Stock has no asset owner; there is no invented same-entity invariant.

ComponentRepository retains the EntityManager supplied by the caller. It resolves owning subject joins and optional stock from authoritative entity metadata. Attached reads scope the actual Computer entity; an explicit empty entity list returns no attached rows. Device-screen stock reads preserve binding IDs, specificity labels and legacy deleted-stock candidates. Transfer assignment selection excludes device IDs, and canMoveDevice inspects every binding: stock or an assignment outside the selected graph requires copying the device definition. Owning rebind writes canonical references via the existing RecordWriter. Other core component families still use their existing scalar identities until separately modeled.

Actual call paths composed from the original processor work are:

- Item_Devices::getTableGroupRows and AJAX selectUnaffectedOrNewItem_Device use ComponentRepository for mapped models. Unmapped plugin handling remains explicit.
- Item_Devices::getItemAffinities and API discovery derive the Processor subject from entity properties, removing its detached configuration declaration and the old API three-family assumption.
- Ordinary add/update retain public CommonDBTM authorization, ConnexityInput, ownership normalization, lifecycle hooks, audit and notifications. CommonDBConnexity::finalizeLifecycleUpdate uses array_key_exists to distinguish a supplied null discriminator from an absent key.
- Computer purge with keep_devices returns bindings to stock, preserving their financial/Contract/Project links. Purging the bindings uses their public delete lifecycle and removes owned relationships while retaining surviving owners.
- Transfer::transferDevices uses the supplied write manager for mapped assignment reads/rebinds. Remove-device invokes public deleteForTransfer inside the existing TransferCancelled transaction coordinator, preserving veto/cleanup semantics. Existing equivalence/device copy policy and untyped/plugin boundaries remain.
- Clonable::post_clone resolves the concrete relation entity returned by the abstract Item_Devices family, retargeting owning columns instead of copying the source Computer. Deprecated Item_Devices::cloneItem uses the same owning input declaration.

Bulk detach and low-level transfer rebind intentionally retain the former absence of per-link update hooks. This is the traced caller policy, not evidence that every persistence operation should omit lifecycle behavior.

## Explicit whole-clone gap

CommonDBTM::clone calls post_clone after add even if add returned false, and Clonable::post_clone ignores individual child clone results. Concrete child retargeting fixes wrong endpoints and retains nullable payload. It does not establish whole-Computer rollback if a child refuses, nor rollback of externally visible hooks or notifications. A separate parent clone unit with failure propagation and explicit database/external-effect boundaries is still required. Successful clone/template scenarios below cannot be cited as proof of that unresolved failure behavior.

## Prepared validation and required gates

The suite runner discovers contracts from its actual directory; four new Processor contracts are discoverable automatically. Existing components, entity-metadata and migration-history contracts retain their earlier assertions with additive processor cases.

Prepared, unrun source contracts cover:

- Entity-local required/optional/root input policy, null versus absent input, wide IDs, invalid kinds/targets, owning retargeting and native lifecycle validation.
- Property metadata, generated projection and FK declaration parity on PostgreSQL and MySQL-family platforms.
- Populated adoption, duplicate/stock records, payload/comment preservation, source preflight diagnostics, partial disagreement, actual phase interruption/retry, missing projection recovery and canonical-only recovery.
- A real incoming composite FK with items_id in its second target ordinal, supplied inventory context, read-only refusal and preserved rows/receipts.
- Lowercase and trailing-space kinds, whitespace masquerading as stock, selected CHECK/FK native causes, and generated-column INSERT/UPDATE refusal. ProcessorNativeAdmission requires the actual provider/driver SQLSTATE, native code and selected constraint/generation cause; unrelated HY000 or integrity errors do not count as enforcement.
- Actual public stock creation/assignment, literal/null payload, entity scope, denied authorization, duplicate binding IDs, AJAX labels, API affinity, modern/deprecated/template clone endpoints, Computer purge policy, component financial/Contract/Project cleanup, transfer move/copy/removal and hook-veto rollback.
- Full canonical populated-history replay preserves Processor duplicates, deleted boolean semantics and both legacy stock spellings, with one ordered17-receipt ledger.

The source metadata assertion advances the accepted1069 checkpoint by one declared Processor association to1070. That is a candidate expectation, not a runtime recomputation.

ROOT must run syntax/style checks, regenerate/check current entity metadata, inspect the discovered current contract list, and validate isolated fresh installs plus populated upgrade/retry/idempotency and final schema on both providers. Run focused Processor and affected generic component/clone/purge/Transfer/API contracts, then complete coherent dynamically discovered suites on both PostgreSQL and MariaDB/MySQL. Actual browser/API/replica/remote CI evidence remains separately required; this source candidate supplies none.

Remaining work includes the whole-clone failure contract, other scalar component ownership families, plugin polymorphism, remaining transfer/device-equivalence legacy SQL, and the wider application ORM/domain migration. Passing this batch would not complete the overall objective.

The reconstruction fixture captures the actual native processor CHECK catalogue before any DDL, refuses unknown/missing checks, and replays captured BooleanDomains constraints during each legacy rebuild. Final restoration reproduces every captured CHECK and verifies schema before restoring the completed receipt. Fixed-ID owners are admitted only when absent, created inside the protected boundary, and independently cleaned; partial incoming fixture setup is guarded. Primary failure diagnostics precede additional cleanup failures. The processor metadata contract retains an exact1070 composed-association equality assertion.
