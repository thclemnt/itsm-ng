# Canonical upgrades and owning dropdown choices

Application source: `448699cfbb7d4847a73edc36eabf0f3da1042fb3`.
Cloud providers: PostgreSQL 15.19 and MariaDB 10.11.18, PHP 8.2.33,
Doctrine ORM 3 and DBAL 4.5. This is local validation, not remote CI or a
production release-support matrix.

## Resulting behavior

The existing History coordinator and migration ledger now own CLI and web
upgrades as well as fresh installation. Release aliases cannot bypass pending
history. Read-only preview retains its supplied connection; apply requires the
writer. Supported populated adoption validates the frozen historical shape,
preserves the original encryption key and publishes release metadata through
the existing Config lifecycle under the history lock. Unauthorized requests,
invalid CSRF, missing keys, unsupported schemas and unfinished installations
receive concrete recovery diagnostics before ordinary application persistence.

Mapped dropdown choices use owning repositories and typed criteria. Project,
knowledge-base, profile, contact and user choices retain their domain visibility
and grant policies. Numeric search uses the mapped property type. Initial labels
and later search requests share the same authorization policy. Signed contexts
bind caller restrictions while current permissions and entity scope are checked
again on every request. Every actual core dropdown request producer was traced;
rendered POST callers, relationship asset selectors and expandSelect now send
their authorized context. Project team labels use plain DOM text and asynchronous
responses cannot overwrite a newer type selection. Unmapped plugin choices retain
an explicit compatibility boundary; this is not proof of plugin PostgreSQL support.

The integrated component selector also retains its explicit session entity
restriction when the ordinary account selector permits all entities. Certificate
fixtures now create real referenced users; Consumable replacement fixtures create
a surviving group. Original assertions and enforced constraints remain enabled.

## Validation record

- Fresh CLI installations completed on separate disposable provider databases,
  replaying all eight canonical versions including frozen baseline and seed rows.
- Both providers completed **147/147 discovered portability contracts**. These
  include populated frozen-history adoption through actual db:update, invalid-data
  diagnostics and interrupted-DDL recovery under the unchanged 300-second limit.
- Expanded application runs passed **19 classes, 158/158 methods, 6,935 assertions**
  on PostgreSQL and **21 classes, 204/204 methods, 10,996 assertions** on MariaDB.
  Both report zero void and skipped methods. These include Certificate,
  Certificate_Item, Domain, Consumable, Dropdown, Update and GLPIKey in addition
  to the prior lifecycle selection; MariaDB also includes DB and DBmysqlIterator.
- Final read-only portability and application database inspections pass on both
  providers. Expression, trigger and CHECK equivalence remain
  outside generic schema comparison and require the native relationship contracts.
  The original certificate compatibility column again matches expected nullable
  signed BIGINT, NULL default, historical comment and native nine-subject projection;
  there are no changed columns or missing/modified indexes after either full suite.
- Actual HTTP dropdown validation passed 38 requests on each provider. The first
  concurrent full browser attempt timed out in actor cases and is preserved as a
  failed attempt. Subsequent sequential full browser runs pass **12/12 tests on
  each provider, zero skips**, in 3.8 minutes on PostgreSQL and 3.6 minutes on
  MariaDB. No timeout or assertion changed. Rebuilt assets and identical application
  source were used. Passing sequential results do not establish the cause of the
  earlier concurrent timeouts.
  Both browser database schemas also pass final read-only inspection. Separate
  controlled DOM probes pass three cases per provider for rendered Project and
  direct/recursive ProjectTask team choices: literal labels, stale/cleared
  selections and request capabilities. These use actual rendered forms and ORM
  rows with controlled AJAX completion, and are distinct from live HTTP/browser
  application-flow evidence.
- All 52 changed PHP files passed syntax and repository formatting checks; all
  58 Twig templates passed syntax checks. The runner still discovers contracts
  dynamically and retains its original per-contract time limit.

Exact commands and logs are outside the repository in `/workspace/itsm-env/evidence`:
`upgrade-dropdown-integrated-install-*.log`,
`upgrade-dropdown-integrated-suite-*.log`,
`upgrade-dropdown-application-commands.txt`,
`upgrade-dropdown-legacy-expanded-*.log`,
`upgrade-dropdown-final-*-schema-*.log`,
`upgrade-dropdown-certificate-column-*.json`,
`upgrade-dropdown-integrated-formatting.log` and
`upgrade-dropdown-integrated-twig-lint.log`. Successful browser logs are
`upgrade-dropdown-integrated-browser-{pg,mysql}.log`; failed attempts are
`upgrade-dropdown-integrated-browser-concurrent-attempt-{pg,mysql}.log`.
Browser failed-attempt and rerun logs
must remain separate. Runner success summaries were inspected; atoum process
exit status alone is insufficient evidence.

## Remaining objective

The static inventory at this source is 357 mapped tables, 1,049 enforced
references, 26 discriminated identities, 38 polymorphic candidates and one
pending identity. There are still **2,861 legacy adapter query sites** and
24 native PostgreSQL transport sites. Inventory counts are checkpoints, not
completion evidence.

Next isolated batches address atomic mapped purges, operating-system subject
ownership and validated Domains plugin import/adoption. The reproduced stale
Group replacement can still lose memberships and audit rows on this source;
the valid fixture repair does not fix that independent production defect.
Release-engine CI, live replicas and additional historical release matrices
remain unverified. The durable modernization goal remains open.
