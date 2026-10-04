# Allocation ancestry cycle fixture

The ownership contract now creates two distinct persisted Entity rows with parent edges A → B → A. Each edge selects a real positive target different from its source, so the graph satisfies the existing `glpi_entities_parent_root` CHECK and parent foreign key. The contract explicitly reads both native parent cells before exercising the original public allocation refusal and unchanged allocation/history/notification counts.

The former fixture attempted A → A and failed at the database CHECK before the domain ancestry validation. No constraint, mapping, migration, domain operation, original refusal assertion, or cleanup is changed. Both rows remain inside the original disposable transaction and its existing rollback.

Source validation confirms that `EntityHierarchy::chain()` detects repeated ancestors and that `EntityHierarchyRepository::reserve()` validates its discovered graph before acquiring the parent locks. Corrected PostgreSQL and MariaDB execution remains pending ROOT; this source correction does not claim native success.
