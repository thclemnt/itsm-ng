# Final component subject admission

The public Item_Devices add lifecycle now asks ComponentRepository whether its final typed owning subject exists before insertion. The query derives the association target and selected column from the relevant entity metadata and uses a scalar ORM query on the supplied writer. It runs after the existing preparation, plugin hooks, value filtering, asset rules and final normalization. Genuine unassigned stock selects no subject and remains admitted. Unconverted legacy component families retain their existing behavior.

Previously Item_Devices preparation loaded the proposed asset for inherited defaults but continued when the lookup returned false. An optional stock policy did not distinguish stock from a positive nonexistent Computer. Typed Processor insertion then raised the sound native foreign-key violation instead of returning the public refusal expected by its ownership contract.

The original missing-target assertion remains unchanged. Additional controls invoke the actual post_prepareadd hook to replace a real selected Computer with a nonexistent canonical target, require false and no item_add completion, and compare all binding, audit and notification rows. Existing positive assignment, duplicates, stock, scope, clone, purge and transfer assertions remain intact.

No native errors are caught or reclassified. A deletion racing after the existence read is still enforced by the unchanged foreign key. This admission does not grant authorization or promise stronger transaction isolation. Public callers retain their existing authorization checks; the target query creates no public-model hydration callbacks. Frozen schema, history and entity declarations remain unchanged.

Validation here is source inspection and Git inverse checks only. Corrected PostgreSQL and MariaDB contracts/full suites remain pending ROOT.
