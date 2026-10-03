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

Escaping now applies only to string leaves. Arrays retain their keys and recurse;
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
utility already preserves non-string types. `stripslashes_deep()` retains a
similar older coercion and needs its own caller/behavior audit; this change does
not modify it.

Preparation validation: four PHP syntax/style checks, a clean diff check, and
40 disconnected assertions covering nested array keys, both boolean states,
wide integers, floats, NULL, object/resource identity, exact text delegation,
quote entities, UTF-8/control characters, and continued empty-string boolean
refusal. The native Appliance and Domains contracts now inspect true/false and
wide-ID input at their actual pre-add hooks, then the zero/one representation at
the actual add hooks. Their existing rollback, receipt, scope, audit, financial,
profile, cloning and purge assertions remain intact.

Native importer execution, original application/Toolbox suites and complete
portability suites on both providers are still required for this change. The
running suite uses the earlier source and cannot validate this repair.
