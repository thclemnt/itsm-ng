# Deterministic upgrade release metadata

Root's full PostgreSQL suite at `a736d72e03a41deee9723d39d50d372940ed1335`
finished 178/179 in 780.8324978 seconds. Only `upgrade-entrypoints.php` failed:
its lost-key assertion detected a change to the combined state snapshot. The
schema-check contract passed in the actual full-suite order. This is failed
full-suite evidence; b66 remains the latest complete green checkpoint.

Source inspection identifies an unordered producer: `Upgrade::release()` selects
four named configuration entries without ORDER BY, then publishes their native
row order as the associative map insertion order. The unchanged contract deletes
and reinserts itsmversion while proving that a missing alias cannot bypass the
original-key policy, then compares the complete snapshot strictly. Reinsertion
can alter native traversal without changing any release name or value. This is
a source-backed candidate cause, not yet a native diagnosis.

The release owner now orders its DBAL projection by the quoted name before
constructing the public map. Supported/historical schema guards, selected names,
values and types, supplied connection, lost-key refusal, hooks and publication
are unchanged. No test snapshot is sorted and every original assertion remains.
Frozen schema, seed and migration definitions have no changes. Source-only syntax,
formatting and whitespace checks precede root-owned native causal validation.

Root must run the instrumented original contract at exact a736 to determine
which snapshot component differs; retain the full failure and verify credentials,
rights, plugin/OIDC/audit/history values and key absence independently. The
instrumentation records only types/counts/field identities and equality booleans,
never password/hash/key content. If release key order alone differs, run the
unchanged complete contract at this correction on both providers, retain actual
missing-key and unrelated value-change controls, then rerun both coherent full
suites and final native inspection. No causal result or new green milestone is
claimed by this source preparation.
