# Application validation successor from 53cf

This isolated branch composes six independently source-reviewed application
fixes on `53cf6297473bc31400a0777ff905bd79f1e65698`. Each original Conventional
Commit and cherry-pick provenance is retained. The combined source has not
been executed or integrated into the frozen ROOT validation boundary.

| Public path | Previous behavior | Resulting behavior |
| --- | --- | --- |
| User creation and duplicate lookup | Canonical-only authentication input acquired legacy zero and was checked against the wrong account identity | User entity derives the logical identity from its existing declarations, preserves supplied canonical input and historical absent/null legacy defaults, and rejects contradictory owners |
| ITIL lifecycle modification clock | An absent session actor could write legacy zero into a nullable User FK | Clock still advances; a real interactive actor wins, a positive trusted internal fallback remains usable, and no actor preserves the prior updater; invalid positive references still fail the FK |
| LDAP duplicate-DN fixture | Copied canonical owner disagreed with a changed legacy owner and the alternate ID was guessed | Public fixture creates a real alternate directory, verifies contradiction refusal and coherent ownership, then retains the original actual LDAP login assertions |
| Mail subject ticket reference | A missing subject target became an orphan ticket link during post-add | Missing target becomes ordinary new-ticket input; existing closed/ineligible links and eligible followups retain their public lifecycle paths |
| E2E installation wrapper | Current migration success output failed an obsolete literal matcher; CLI status was hidden by tee | Actual update and logging status must succeed, then read-only configured-writer checks require completed history, core schema and current release publication |
| Original API warning observation | Test polled a different log directory from the HTTP server | Test reads the authoritative configured GLPI_LOG_DIR, retaining its original expected warning assertion, polling and cleanup |

The ITIL actor selection and User input preparation are bounded fixes in the
existing application. The ITIL compatibility date update remains an adapter
write; this batch does not claim a full ORM migration. No shadow user, actor
grant, fake relation target, assertion suppression or external transport
workaround is introduced. Existing schema/history/FK declarations, native
drivers, caches, replica selection, Status behavior and dependencies are
unchanged. The accepted originals remain available in their own worktrees.

Remote observations remain failures: original API 49/83 executed methods
with 34 skipped, one failure and five exceptions; LDAP 51 methods with one
exception; IMAP 10 methods with one failure and one exception; E2E stopped
before browser execution. The five API updater exceptions include local
fixture/cleanup lifecycles and must not be reported as five endpoint writes.
The lost-password warning-log assertion is a separate failure. IMAP's
expected malformed-address log remained uncleared when its ticket exception
aborted collection; its original exact malformed-mail/count assertions remain.

Pending validation must use a separately identified exact combined source:

- PHP syntax/style for changed files and the process-only shell installation
  contract. Those checks have not run during source preparation.
- PostgreSQL and MariaDB focused User/public-default/duplicate, ITIL actor
  (including session precedence and invalid positive FK) and mail-reference
  builder/persistence tests, with proper caller rollback and fixture cleanup.
- Coherent full portability/application suites on both providers, original
  schema checks, and final installation/upgrade convergence as required by
  the modernization goal. The only new top-level portability contract is
  `itil-date-updater.php`; discover the actual runner list at execution time.
- Original complete web API with test process and HTTP server sharing the
  same disposable config and VAR directory; the checked-in router defaults
  to tests/files. Retain original request assertions and actual method totals.
- Actual LDAP and IMAP fixture-service suites, including all unchanged real
  login, malformed-mail, collection/routing and notification/audit assertions.
- Successful actual E2E installation/read-only verification followed by an
  asset rebuild and the discovered browser suite. No browser result is
  implied by source composition or by the stub process contract.
- Remote CI reruns, reported separately from local evidence. Previous source
  bindings and failed logs remain immutable; never relabel them as this branch.

Every native/compiler/application/API/browser/remote result for this combined
source is currently pending. Git clean-tree, exact candidate-body comparison,
whitespace and complete source inverses are source evidence only.
