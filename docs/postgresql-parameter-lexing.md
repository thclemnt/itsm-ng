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
