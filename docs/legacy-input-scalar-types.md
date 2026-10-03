# Preserve types while escaping legacy input

The full PostgreSQL portability run at
`8c00b7669b02ba1d74c7d93f37eb7393959b3d91` exposed the same lifecycle failure
in both historical plugin importers. Appliance import failed creating
`glpi_appliances.5000000100` before its injected interruption; Domains import
failed creating `glpi_domaintypes.4294972001` before its injected phase
interruption. These failures remain recorded in the cloud suite evidence. They
are not passing rollback contracts.

Both importers validate their source and normalize mapped boolean values to
native `true`/`false` before invoking the real public lifecycle. They then call
`Toolbox::addslashes_deep()` on the complete input. The old utility passed every
non-null scalar through `str_replace()` and the adapter's text escaper. That
converted native `false` to an empty string. The strict boolean lifecycle boundary
correctly refused that empty string and returned false before persistence.
A disconnected call of the actual utility confirmed this exact coercion and
`BooleanValue` diagnostic without opening a database connection.

Escaping and its inverse now apply only to string leaves. Arrays retain their keys and recurse;
booleans, integers, floats, NULL, objects and resources retain their types and
identity. The same HTML quote-entity substitution and adapter text escaping
remain in place for strings, including empty strings and numeric strings.
`BooleanValue` still rejects empty strings; no importer-specific flag casts or
new type registry compensate for the old utility.

Caller inspection covered both importers and the mixed arrays used by cloning,
Transfer, authentication synchronization, notification queues, component/task
copying and API criteria. Dedicated string callers include HTML and statistical
labels, LDAP DNs, revision content, serialized profile item types and CLI
configuration arguments. Existing Toolbox tests exercise escaped text, without
requiring booleans or numbers to be converted into strings. The adjacent XSS
utility already preserves non-string types. The paired `stripslashes_deep()`
had the same scalar coercion. Its callers include Auth's explicit
decode-then-escape synchronization, user/Software/Ticket rule processing,
IDOR validation, tab URLs, LDAP filters/parameters, notification recipients,
JSON recovery and validator labels. It now strips escapes only from strings,
with the same PHP `stripslashes()` behavior for those strings.

Type-sensitive consumer inspection found that assigned-ID creation validates
through `FILTER_VALIDATE_INT` before strict identity comparison in
`CommonDBTM::add()`. Rule criteria perform explicit text normalization in
`RuleCriteria::match()`; Ticket post-rule actor cleanup uses loose comparison.
`Session::validateIDOR()` already compares supplied and decoded scalar values
loosely; this change leaves that authorization algorithm unchanged. Native
integers and numeric strings remain distinct through the utilities. Consumers
that previously relied on the utilities implicitly casting integers to strings
must own any required conversion. Original rule, auth, Session, clone and Toolbox
suites are required to check those concrete callers.

Preparation validation: four PHP syntax/style checks, a clean diff check, and
63 disconnected assertions covering nested array keys, both boolean states,
wide integers, floats, NULL, object/resource identity, nested primitive
round-trips, inverse string semantics, exact text delegation,
quote entities, UTF-8/control characters, and continued empty-string boolean
refusal. The native Appliance and Domains contracts now inspect true/false and
wide-ID input at their actual pre-add hooks, then the zero/one representation at
the actual add hooks. Their existing rollback, receipt, scope, audit, financial,
profile, cloning and purge assertions remain intact.

Native importer execution, original application/Toolbox suites and complete
portability suites on both providers are still required for this change. The
running suite uses the earlier source and cannot validate this repair.

The first root-owned PostgreSQL importer reruns after integration reached the
later ordinary allocation controls, then failed newly added pre-add assertions:
ordinary Appliance and DomainType inputs do not carry an assigned import ID.
Those failures expose an overly broad fixture-hook lifetime, rather than prove
either complete importer contract passes. The updated contracts reset their
typed hook observations immediately before successful CLI import and require
exact identity coverage at both pre-add and add hooks. Appliance removes both
import hook types before its ordinary insertion. Domains removes its typed
pre-add assertions and retains only the generic creation recorder for ordinary
inserts and subsequent no-op retry checks. The identity-rewrite refusal hook
and restoration, interruption controls and all existing lifecycle checks remain.
This follow-up is source-only pending complete native reruns on both providers.
