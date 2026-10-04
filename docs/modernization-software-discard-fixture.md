# Software discard fixture scope

The earlier merge creates `$movedLink` on the same `$otherAsset` selected by the later direct transfer. It survives the failed-copy rollback and accepted same-version transfer, so the real discard must purge this selected installation alongside `$moveInstallation` and `$rejectInstallation`. ROOT’s actual PostgreSQL observation recorded exactly three installation purge IDs (161,163,165), against the fixture’s incomplete two-ID expectation (163,165); licence hook IDs matched.

The expected list now includes all three explicitly created selected installation links. Its existing exact sorted equality, actual pre/post purge callbacks, licence assertion, excluded-version and same-number other-kind controls, public command, payloads, history and cleanup remain unchanged. No production selection or authorization change.

SOURCE-only correction; ROOT must compile and run both original provider contracts.
