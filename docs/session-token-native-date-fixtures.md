# Session token fixture date domain

Source-only follow-up to `ab424228903be3d88f953e2b040dd57f33ce86e4`. Root's MariaDB personal-token contract failed at its first future-account write: `2099-01-01` exceeds the intentionally preserved native MySQL/MariaDB TIMESTAMP domain. PostgreSQL accepted that value. This was a fixture boundary error; the account admission policy and column type remain unchanged.

Both personal-token uses of 2099 now use the actual authenticated session clock plus one day. Expired admission and the accepted explicit admission interval use the matching clock minus one day. The HTTP source fixture's ten-year offsets also become one-day offsets, preserving future/expired semantics without an approaching January 2038 overflow. An explicit clock prerequisite diagnoses a test environment unable to supply those portable dates; it does not skip or catch an invalid native write.

All original token authorization, grant/entity/group, initialization-hook, session/language restoration and accepted-date assertions remain. The fixed 2030 equality and one-second interior controls still test the exact shared predicate independently of the real clock. Cookie HTTP controls retain their portable 2000 expired timestamp and SQL NULL; cookie rotation uses the actual session clock. Other new Session/CLI fixtures contain no 2050/2099 or out-of-range date literal.

No production, entity or historical schema definition changes. Source preparation and independent review are separate from pending PHP syntax/style, both-provider contracts, and actual HTTP verification. No runtime or compiler job was executed by the author.
