# Frozen reference upgrade inputs

`20260930-reference-upgrades.json` captures the inputs to the existing reference
upgrades before their detached runtime catalogues were removed. Its groups are
historical migration batches, not runtime schema declarations.

Do not regenerate or modify this snapshot when an entity changes. Add a separate
versioned migration for new changes. Runtime relationships, types and legacy
reference policies belong on Doctrine entity properties. `ReferenceHistory`
reads this file for versioned schema upgrades, their fresh-install compatibility
steps, and migration fixtures. Runtime CRUD and relationship discovery never
read this snapshot.
