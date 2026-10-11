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

`20261001-seed-inputs.json` is a separate frozen input addendum for the original
`20261001-seeds.php` records. It supplies the two omitted required TEXT comments
as explicit empty strings, matching the frozen PostgreSQL defaults and legacy
MySQL's prior values. It does not amend the original seed or baseline files,
change DDL, infer defaults for other columns, or replay seeds during adoption.
