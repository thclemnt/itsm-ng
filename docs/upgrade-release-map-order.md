# Deterministic upgrade release metadata

The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
See [the handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
for exact timings, retained failures and remaining work.

Root's full PostgreSQL suite at `a736d72e03a41deee9723d39d50d372940ed1335`
finished 178/179 in 780.8324978 seconds. Only `upgrade-entrypoints.php` failed:
its lost-key assertion detected a change to the combined state snapshot. The
schema-check contract passed in the actual full-suite order. This is failed
full-suite evidence; b66 was then the latest complete green checkpoint, now superseded by 741.

Source inspection identifies an unordered producer: `Upgrade::release()` selects
four named configuration entries without ORDER BY, then publishes their native
row order as the associative map insertion order. The unchanged contract deletes
and reinserts itsmversion while proving that a missing alias cannot bypass the
original-key policy, then compares the complete snapshot strictly. Reinsertion
can alter native traversal without changing any release name or value. This is
the candidate cause subsequently reproduced by a controlled native PostgreSQL BEFORE probe.

The release owner now orders its DBAL projection by the quoted name before
constructing the public map. Supported/historical schema guards, selected names,
values and types, supplied connection, lost-key refusal, hooks and publication
are unchanged. No test snapshot is sorted and every original assertion remains.
Frozen schema, seed and migration definitions have no changes. Source-only syntax,
formatting and whitespace checks precede root-owned native causal validation.

Root's controlled PostgreSQL BEFORE probe now reproduces release-map physical
key-order drift after alias deletion/reinsertion: all 219 configuration rows and
the other six snapshot components are strictly unchanged; release names, values
and types match, and sorting the diagnostic map proves equality. Missing-key and
deleted-alias semantic negative controls remain meaningful. The production owner
orders its own projection; the original test snapshot is not sorted.

Controlled AFTER probes on the exact 8f repair pass both providers with the
original strict snapshot and negative controls. Instrumentation emits only
types/counts/field identities and equality booleans, never password/hash/key
contents. The unchanged complete upgrade contract, both 741 coherent 180/180
suites, fresh installations and post-full native schemas now pass. Keep the
original a736 full failure and both earlier isolated/fresh nonreproductions;
they are not erased by the later causal proof. This is bounded local validation,
not a claim about unrun official engines, TLS, replicas or prepared next batches.
