# Frozen reference upgrade inputs

`20260930-reference-upgrades.json` captures the inputs to the existing reference
upgrades before their detached runtime catalogues were removed. Its groups are
internal dependency groups of the single 2.2.0 transition, not application releases
or runtime schema declarations.

Do not regenerate or modify this snapshot when an entity changes. Add a separate
versioned migration for new changes. Runtime relationships, types and legacy
reference policies belong on Doctrine entity properties. `ReferenceHistory`
reads this file for the 2.2.0 transition, its fresh-install replay and migration
fixtures. Runtime CRUD and relationship discovery never
read this snapshot.

`20261001-seeds.php` contains the complete initial seed records, including explicit
empty strings for the two required comment fields omitted by the old MySQL
installer. Seeds are imported only for a fresh installation, never on adoption.

The first ORM release supports only the released 2.1.3 data format and a fresh
installation. The 2.2.0 checkpoint names journal recoverable work inside that
single transition. They are not separately published application versions or
compatibility contracts for development snapshots. Parent definitions contain
the final owner sets: no Computer-only expansion state is installed first.
