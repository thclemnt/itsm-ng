# PostgreSQL parameter parsing boundary

At frozen d8c01a2555cacc8a43e928b508bddec5ed6d7a13, ROOT’s actual PostgreSQL
transport run passed its first nine selected contracts, then the unchanged
postgresql-driver.php contract failed with HY093 (mixed named and positional
parameters). The PostgreSQL lexer correctly recognized the complete nested
comment, but PDO stopped at its first inner closing delimiter. Ignored question
marks and named-parameter text therefore reached PDO’s parameter parser.

The driver preparation boundary now keeps the outer block comment, its body and
all line positions, rendering only its inner nesting delimiters as whitespace.
Comments remain comments; placeholders inside them remain ignored. Literal and
identifier bytes are unchanged. Existing dollar-body representation is performed
once at driver preparation, after the numbered adapter has selected actual bound
positions. This is transport lexical adaptation, not application SQL ownership.
No schema, migration, data, ORM mapping or runtime type registry changes.

The controlled BEFORE probe at
`/workspace/itsm-env/evidence/postgres-pdo-d8-lexical-before-actual-20261004T022350Z-cfb79a021c`
completed with unchanged schema and ledger. Both original trailing-backslash
literal controls returned their strict expected rows, through the numbered
adapter and ordinary DBAL. No ordinary-string rewrite is justified. The original
nested adapter call exposed a missing error-logger context under this intentionally
minimal bootstrap; its actual Error is retained, separately from the original
full-contract HY093. Flattened-delimiter and comment-free counterfactual controls
returned identical expected values. Those controls are diagnostics, not changed
fixture SQL or whole-contract passes.

All 206 original contract files, including the complete original nine-line
standard-string regression, remain byte-identical. The additional real configured
owner contract exercises three-level comments, named and positional parameters,
question-mark operators, actual distinct E/N/B/X/U& literal semantics, quoted
identifiers, newline-sensitive literal concatenation and dollar-body text. It
uses an owned read-only frame, verifies the exact migration ledger, and preserves
the first failure through owned rollback/close. MySQL/MariaDB retain an actual
ordinary binding and read-only owner control; the PostgreSQL lexical cases are
provider-specific. Third-party comment-hint extensions have not been validated.

This candidate is SOURCE ONLY until independent review and ROOT compilation,
followed by the unchanged original driver contract and new lexical contract on
both providers. Broader suites, fresh installs, populated upgrades, TLS, replicas,
API/browser flows and remote CI require their own actual evidence. The overall
modernization goal remains OPEN.

## Owned pre-expansion boundary

ROOT's V5 read-only probe at
`/workspace/itsm-env/evidence/postgres-pdo-d8-lexical-before-v5-actual-20261004T024231Z-da9a690547`
then exposed the earlier DBAL boundary: the real named nested query raises
MissingPositionalParameter at index zero before driver preparation, while its
flattened-comment counterfactual returns the strict expected row. The pinned
DBAL Connection privately expands named and array parameters with a nonnested
SQL parser. No native failure is dismissed as a fixture error.

PostgresConnection now applies the same idempotent lexical preparation before
parent query/statement parameter expansion, retaining the supplied parameters,
DBAL types, result, row count, connection and exception ownership. Cached queries
pass their original SQL and QueryCacheProfile to DBAL unchanged; a real cache miss
virtually re-enters the uncached method, and a hit opens no new connection. Direct
public executeCacheQuery follows that same vendor path. Driver query also uses
the shared preparation because actual unbound PDO queries still parse parameters.
Unbound DDL through exec keeps its original statement. No second parser, parameter
expander or cache registry is introduced.

The genuine read-only contract now also covers typed/empty arrays, statement row
counts, binary NUL/non-UTF8 and NULL values, actual cache entries keyed by the
original SQL, a lazy complete-parameter owner cache hit, direct cache misses and
repeated preparation. These assertions are native gates until ROOT runs them.
The newline/comment concatenation controls remain subject to direct server
validity proof; the unflattened prepared and unbound-PDO BEFORE variants both
failed HY093 before any server syntax result. All original contracts and literals
remain unchanged, and the overall goal remains OPEN.

## New-contract grammar correction

The additional V6 read-only probe at
`/workspace/itsm-env/evidence/postgres-pdo-d8-lexical-before-v6-actual-20261004T025252Z-d10878dbc1`
reached PostgreSQL with both flattened-comment variants. Prepared and unbound
queries both returned native 42601 near the second string literal. A block
comment between adjacent literals is invalid grammar, despite the intervening
newlines. This was an error in the new lexical fixture, not lost compiler
semantics. Only that new contract moves the nested comment after the completed
projection/bound expression, leaving the legitimate newline between adjacent
literals and retaining strict `leftright` and bound-value assertions. No
production structural SQL rewrite, literal rewrite or assertion suppression is
introduced. The earlier source and every controlled failed receipt remain
retained; all 206 original contract files are still byte-identical.
