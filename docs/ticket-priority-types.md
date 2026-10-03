# Ticket priority types across input and persistence

Root-owned PostgreSQL execution of the original broader application selection
at `b05dfcf808fa6d49d27016ad21ff399e11bae1a4` failed
`Ticket::testComputePriority`: integer `2` did not satisfy its string assertion.
The run retained 283 cases and finished in 280.658486 seconds; it is a failed
validation result, not a completed green milestone.

The original five provider inputs were native integers, while every expected
urgency, impact and priority was text. The old `stripslashes_deep()` pass after
Ticket rules implicitly converted all three fields to strings. Preserving
scalar types removes that coercion. The authoritative Doctrine Ticket properties
and columns declare integer urgency, impact and priority. Missing update inputs
come from those integer stored fields, and the frozen default priority matrix
contains numeric JSON values. Its exact original five priority results remain
2, 4, 4, 5 and 2; this is the configured matrix, not a replacement arithmetic rule.

The form submits numeric strings. `front/ticket.form.php` authorizes the Ticket
then forwards its POST input to public `update()`. `ajax/priority.php` sends its
POST urgency and impact to `computePriority()` and echoes the result as text.
Numeric-string array keys select the same matrix cells. Supplied urgency/impact
retain their string representation during preparation; missing fields retain
the stored integer type and computed default-matrix priorities remain integers.
RecordWriter converts integer-mapped fields at persistence, and public and ORM
reloads expose integers. The configuration UI can save matrix values as numeric
strings: `computePriority()` returns the selected configured value unchanged.
This test targets the frozen default matrix and does not assert every custom
configuration must produce a native integer before persistence. No production
cast, algorithm, rule, authorization or presentation change was made.

The original contract now strictly checks the native integer expectations and
adds numeric-string variants of the same five inputs. It preserves the returned
array guard and verifies exact string/integer preparation types at their owning
boundaries. Each case also checks actual update permission, public update success,
fresh public reload and fresh Doctrine reload, with strict integer/value assertions
for all three persisted fields.

Atoum executes all provider datasets inside one DbTestCase transaction. A nested
transaction with `finally` rollback isolates each case's actual writes, preserving
the missing-field fallback data for subsequent cases. The test checks its entry
transaction depth and fresh original priority values after rollback. It does not
reset fixture values through application updates or weaken an assertion to accept
either type. It still requires the original `_ticket01` fixture, authenticated
test user and default matrix.

Syntax, formatting, whitespace and disconnected provider enumeration are source
checks only. Ten provider cases must execute through the actual original assertion
runner on both providers, followed by the relevant broader application and full
portability suites. Numeric-string input cases are public lifecycle controls;
they do not claim HTTP or browser execution.
