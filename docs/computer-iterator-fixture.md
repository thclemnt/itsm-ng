# Computer iterator fixture ownership

Prepared separately at base
`b66ec2f474973e779de1043a8a7d308e36531552`; provider execution is pending.
No application persistence behavior changes in this patch.

The original `tests\units\Computer::testGetFromIter()` selects every Computer ID,
then requires every reloaded name to be a string different from the previous
name. This depends on unrelated database contents. Computer names are nullable
in both the authoritative `Computer::$name` Doctrine property and frozen
`Baseline20261001` definition. Duplicate names are also legitimate.

`CommonDBTM::getFromIter()` uses each iterator row's ID to call `getFromDB()` and
yield a full model. For mapped Computers, that load calls `RecordRepository::find()`;
its row conversion preserves a NULL scalar instead of converting it to a string.
Changing production hydration to satisfy the original test would lose legitimate
stored NULL values.

The actual `FixtureRecords::create()` producer initializes an entity, supplies
required association values and leaves omitted nullable scalar names at their
declared NULL default before `RecordWriter::insert()`. For example,
`financial-references.php` creates unnamed Computer targets for Infocom bindings,
and `printer-dictionary.php` creates unnamed Computer targets for printer links.
Those contracts use transactions. This source inspection establishes that their
unnamed records are legitimate; it does not identify which particular fixture
produced a row seen in the parent's post-suite database or claim a live stack.

The corrected iterator test owns two distinct named Computers created through
the public model API. It retains the ID-only iterator and full Computer/name
assertions, verifies iterator cardinality and exact returned IDs, and compares
each reloaded name with its own persisted value. An additional actual mapped
record has a NULL name and a persisted serial marker. Its separate ID-only
iterator must yield one full Computer with the exact marker and a NULL name.
This prevents a partial-ID model or lossy NULL conversion from passing.
All three records belong to DbTestCase's existing per-method transaction, which
its `afterTestMethod()` rolls back.

Source checks: PHP 8.2.33 lint, scoped formatter dry run and whitespace checks
pass. No database, bootstrap, dependency copying, application, assets or browser
jobs were run. Next, execute this exact original Computer class on both provider
databases with the parent's existing post-portability state, then retain it in
the complete broader application matrix. The modernization goal remains open.
