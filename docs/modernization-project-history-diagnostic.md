# Project adoption diagnostic follows the canonical preflight

Canonical history contains seventeen stages. `History::upgrade()` now runs the
frozen Exact discriminator preflight before `ProjectAssets20261003::plan()`.
An unsupported plugin kind, zero subject or missing subject in the owned
`glpi_items_projects` fixture is therefore rejected by the earlier Exact audit.
The rejection is correct; the original contract expected only older Project
migration diagnostics and failed before its no-DDL assertions.

The test retains both older diagnostic checks and adds the current exact
`RuntimeException` class, complete message envelope, table, count of one,
ordered sample containing owned row303 and its actual original proposal, and
explicit no-rewrite suffix. It does not accept unrelated exceptions, tables,
counts, samples or additional problems. All three original invalid proposals,
DDL, migration attempts, no-ledger/no-widening/no-owning-column assertions,
reset, retries, later controls and300-second runner limit remain in place.
No migration definition, entity, installation path or runtime code changes.

ROOT's unchanged PostgreSQL contract and diagnostic-only observer reproduced
the original failure. The private capture proves the actual Exact preflight
exception for the unsupported plugin proposal; the observer retained every
original assertion and stopped at that first failure. This evidence is distinct
from corrected native execution. MariaDB's original contract and observer independently reproduced the same
Exact exception class, complete message and owned sample. Both original
contracts still fail their unchanged older diagnostic assertion. No runtime
jobs were executed by this author. Corrected native execution remains pending.

Next: independent source review, ROOT syntax and corrected canonical history
contract on both providers, then the composed full suites and final native
schema/ledger/data proof. The entire modernization goal remains open.
