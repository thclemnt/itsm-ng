# Transfer browser result assertion

Source-only correction based on the failed PostgreSQL complete browser run at
`336ef4fec61341437399471647a6fe6e7992efb7`. That run passed 13 of 14 scenarios;
Transfer stopped at its first refusal-message assertion. Later data-preservation,
list, API edit and successful retry assertions were not reached. This change has
not run in a browser or against either database.

The retained trace establishes that the POST completed with HTTP 200 at
268917.885 ms, the new document fired load at 269344.371 ms, and networkidle fired
at 270191.658 ms. The browser assertion began at 270192.546 ms. The snapshot at
270202.582 ms already contains the controller result. The trace records no
matching element for the exact phrase; the assertion expires after five seconds.
These are trace-relative times, not new application timing measurements.

The controller renders one `div.b.center` containing the outcome, a line break
and the Back link. Its aggregate text is `Transfer failedBack` (or
`Operation successfulBack`). An exact phrase locator cannot select that result;
it can only happen to find a separate notification with the phrase alone.

The test now requires one visible controller result containing the complete
anchored outcome and Back text, and explicitly forbids the opposite controller
outcome. The existing page-wide opposite-message absence assertions remain.
Real forms, POST status, navigation waits, data checks, owned cleanup, the shared
60-second timeout and retries configuration remain as before. No navigation or
timeout change is justified by this trace.

Owned cleanup still attempts both API-session closure and fixture cleanup. A
single failure is rethrown directly so the normal reporter preserves the
original Playwright assertion and source location; multiple failures remain
aggregated. Presence tracking also preserves a thrown falsy value.

Source review and whitespace checks are the current validation. Root must run
the actual Transfer scenario on both providers, inspect all preservation/retry
assertions and cleanup, then the relevant complete browser matrix. The prior
failure evidence remains unchanged. External sanitized trace evidence:
`transfer-browser-refusal-trace-source-review.md`,
`transfer-browser-refusal-trace-sanitized.json`, and
`transfer-browser-result-source-336.md` under `/workspace/itsm-env/evidence`.
