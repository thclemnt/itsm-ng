# Native incoming ownership during component fixture reconstruction

The processor/component reconstruction helper must inspect every visible referring
schema before detaching canonical incoming constraints. Its MySQL inspection now
selects native REFERENTIAL_CONSTRAINTS by the referenced schema/table, then reads
all KEY_COLUMN_USAGE ordinals by each actual referring schema/table/constraint.
It does not restrict consumers to core tables or the application namespace.

This avoids joining two virtual catalogues across an accumulated disposable server.
Every call reads fresh native state, including after DDL; no global catalogue or
DDL-lived cache is added. The original single-column ownership, match/actions,
consumer-row, restoration and raw receipt assertions remain intact. Case-insensitive
catalogue predicates cannot substitute different native source/target spellings;
ambiguous identities or missing native column ownership refuse before fixture DDL.
PostgreSQL's entire OID-bound inspection branch and production migration APIs,
historical definitions, mappings and the 300-second runner limit remain unchanged.

ROOT's read-only comparison at source 5cb8aec6 returned strictly identical complete
two-row vectors. The original fixture join took 3.283564 seconds and the two-phase
lookup took 1.451192 seconds; global cache settings and raw ledger stayed unchanged.
Evidence: `/workspace/itsm-env/evidence/component-incoming-two-phase-readonly-20261004/result.json`.
This proves that measured read comparison, not a corrected full-contract timeout or
native validation of this new source. Syntax, focused contracts, complete suites,
schema restoration and supported official provider behavior remain unrun at this
SOURCE checkpoint.

The new processor-incoming-core-refusal.php contract uses owned temporary tables,
the configured MySQL projection auxiliary or a new owned PostgreSQL schema, and
the actual helper. It exercises exact canonical detach/restore, external single
and composite refusal, complete owned rows/native declarations/raw ledger continuity,
and case-distinct target predicate overshoot where native table spelling permits it.
It changes no server SQL mode/collation or global visibility. Existing processor and
component contracts retain their original graph, history, retry and full-schema
assertions. Discover the suite count when validating; this new contract adds one
entry and no fixed historical count is authoritative.
