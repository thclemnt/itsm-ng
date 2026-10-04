# Ownership update frames and isolation feedback

The e658 PostgreSQL focused run passed its first 12 contracts, then failed the
unchanged software current-read contract's strong-isolation feedback assertion.
The public model mutation retained the caller's INFO message through its session
checkpoint, but the subsequent direct dictionary command called the same
isolation admission method outside that checkpoint. Its reset flag cleared all
message types. The retry message now uses reset=false and retains check_once=true.
The original strong-isolation contract, including both commands, remains unchanged.

The same run reported an undefined Supplier::$input property in the ownership
update unit. Its checkpoint now comes from LifecycleModelJournal::state(), which
distinguishes an absent property from a supplied null. Stored fields replace only
the checkpoint's field view; pending updates and old values are cleared as before.

OwnershipUpdateUnit now consumes the existing authoritative OwnedMutationFrame
and managed scope. It commits or rolls back its exact frame, without rolling back
every frame deeper than a remembered integer. Only a completed rollback authorizes
model/session restoration. A callback that ends the original frame and begins a
replacement leaves that replacement untouched and propagates the original failure
with the actual scope cleanup failure. Notification, journal and session cleanup
are guarded independently so a secondary failure cannot replace the first error.
A failed cancellation rollback is not retried.

The new ownership-update-frames contract uses real public Supplier updates and a
real post-update Throwable to test cancellation, accepted savepoints, native audit
rollback, caller-frame continuity, absent/null input, and same-depth managed frame
replacement. Replacement controls perform no application DML; they do not claim
to undo a rogue callback's committed writes. Raw native COMMIT/BEGIN epoch tracking
remains an explicit transport limitation.

Validation at this source checkpoint: Git/source inspection only. The original
portability contracts and all frozen schema/migration definitions are unchanged.
PHP syntax, style, the new contract, the original managed scope and software
current-read contracts, full suites on both engines, and final schema inspection
remain ROOT native gates. The overall modernization goal remains open.
