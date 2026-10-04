# Exact subject fixture: financial ownership

The first unchanged Exact14 native contract at `be258c8bd204dcfa2dfc5763e76e460ab80bb54f`
failed on PostgreSQL and MariaDB while creating the replacement Infocom target.
Two distinct wide financial-record IDs had the same default subject pair,
`itemtype = ''` and `items_id = 0`, violating the existing financial-record
uniqueness constraint. This was fixture setup failure, not subject-enforcement
evidence. Both failed runs and their original traces remain retained externally.

The contract now gives each owned Infocom a distinct, real wide-ID Computer.
Its original and replacement targets both use this local financial graph, and
the contract verifies the persisted financial subject. The general fixture
builder, application entities, indexes, migration history, native refusal
classification and all existing 293 branch controls are unchanged.

A source audit covered the 69 target tables and their declared and frozen
baseline unique indexes. Infocom is the only repeated target with an entirely
non-null constant default unique key. The other target keys include nullable
names, UUIDs or external identifiers, freshly created mandatory subjects/users,
or the fixture builder's existing unique CronTask name. The populated Exact14
upgrade contract selects the first branch in each table; none selects Infocom,
and it does not create a replacement financial target. No speculative change
to that contract is needed.

Validation here is source-only: original assertion/control preservation,
target-index/default inspection, and unchanged historical-file hashes.
PHP syntax and actual complete Exact14 contracts on both engines are pending
ROOT execution, followed by populated upgrade/retry, focused and full suites
and final native schema checks. A fixture correction does not complete the
modernization goal; whole-clone child failure and the other recorded gaps remain
open.
