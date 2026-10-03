# PostgreSQL DBAL driver ownership

The PostgreSQL legacy facade now uses a DBAL-owned PDO connection. ORM, DBAL,
legacy queries, prepared commands, session advisory locks and transaction guards
use the same caller-supplied connection. The old native-handle borrowing driver
and transfer bookkeeping are removed. MySQL/MariaDB retain their DBAL-owned
mysqli driver and strict session initialization.

This is a transport ownership change. Application queries still need meaningful
ORM entities, repositories and domain services; moving their SQL to DBAL alone
does not complete that goal. Historical migrations, seeds and the existing
migration ledger are unchanged.

## Driver and row boundary

The small `Driver/Postgres` implementation composes Doctrine's PDO PostgreSQL
driver for connection creation, platform selection, exception conversion,
quoting, version reporting, identity and transaction behavior. Its statements
and results keep the real `PDOStatement` available for ordinal native column
metadata. PostgreSQL primitive result types drive legacy conversions; no entity
table/type catalogue, column-name rule or date-looking string rule is maintained.

Ordinary DBAL/ORM results retain PDO's native conventions. The explicit legacy
execution boundary normalizes only legacy rows before constructing the shared
seekable `LegacyResult`: real boolean columns produce 1/0/null, fitting native
integers become integers, native floats become floats, and `timestamptz` displays
as `Y-m-d H:i:s`. Numeric/date/boolean-looking text remains text. Duplicate and
empty columns keep their ordinal names and values, and rowsets buffer once so
seek/repeated iteration never rereads consumed streams.

The pinned DBAL logging middleware preserves the actual driver result. A custom
middleware that replaces it with a generic result wrapper must preserve the
metadata capability; otherwise the legacy boundary refuses explicitly. It does
not reflect into DBAL internals or infer missing types. The compatibility query
API is scalar and positional; it does not promise DBAL array parameter expansion
or query-cache behavior.

## Binary correction and plugin compatibility

Legacy PostgreSQL `bytea` results now represent raw binary bytes. Previously
`pg_fetch_row` exposed PostgreSQL's encoded wire text, while the DBAL PgSQL driver
decoded bytes. A plugin that expected `\\x...` text must stop decoding the new
legacy value. Real PDO `bytea` metadata selects stream-to-bytes conversion, so
backend `bytea_output=hex` or `escape` does not change the application value.
Ordinary DBAL binary values retain their native PDO representation.

The old compatibility `b` binding stringified resources, and PostgreSQL rejected
NUL-bearing text. Binary bindings now keep strings/resources intact and select
DBAL binary or large-object parameter types on both providers. Input streams
remain caller-owned; text NUL is still refused. No core mapped binary or
large-object caller was found in the source audit. This change does not claim
that binary streams previously worked or that external plugins were verified.

## Parameters and physical lifetime

Internal and public PostgreSQL `queryParams` retain numbered `$n` binding with
repeated references and multidigit ordinals. A lexical adapter maps these to PDO
positions while shielding literals, identifiers, comments and dollar-quoted
bodies. Dollar bodies become equivalent escaped string literals for PDO's
parameter parser, and native question-mark SQL operators remain operators.
Unused, zero and missing positions are diagnosed before execution. Prepared
legacy `?` bindings continue reading reference variables at execution time.
This is parameter/delimiter adaptation, not vendor SQL keyword rewriting.

`PostgresConnection` owns retained compatibility prepared commands and
invalidates them when it closes, including DBAL's automatic connection-loss
path. A command cannot execute against a stale or foreign physical owner.
Already buffered legacy result values can outlive the physical session.
Ordinary externally retained native PDO/DBAL statements follow their native
lifetimes; exposing an external native handle does not promise forced disposal
of the caller's own references.

The protected adapter factory is genuinely lazy. A connected adapter opens that
same factory owner and initializes the selected schema, UTF-8, standard strings,
application name and timezone. Physical reconnect repeats initialization. TLS
mode, CA, client certificate/key, separate port, IPv6 and socket directory flow
through the PDO driver. A five-second PDO timeout option is supplied; timeout
and TLS behavior need actual native verification, not a configuration claim.
Connections are nonpersistent and equal credentials still create distinct
physical owners. Existing application read/write routing remains with its
caller; the driver does not select the global writer.

## Validation checkpoint and next step

All PHP compilation, native focused/full tests, fresh installations, populated
upgrades, retry, application/API/browser tests and remote CI for this transport
source are **unrun**. The earlier transaction ownership results belong to their
recorded historical source. The source audit at `30a4cce` independently found
22 direct native function sites, all in `DBpgsql`; this source removes those
calls. The canonical PHP inventory has not yet been rerun on this change.

The new `postgresql-driver.php` contract includes typed binary string/stream
insert and update, NULL/empty/NUL/non-UTF8 bytes, both PostgreSQL wire-output
modes, strict native-type versus text controls, numbered parameter lexical
cases, buffered seek, direct owner close with a retained command and a real
contended advisory lock, and reconnect. The transaction contract retains
physical PID, supplied connection, aborted commit/savepoint/raw-BEGIN and
owned connection-loss tests; transfer-specific checks now test the real lazy
factory and directly connected owner. PostgreSQL parameter parsing inside
nested comments/dollar bodies, PDO metadata values and resource lifetime are
native gates, not passing claims.

ROOT must first lint/style and run focused physical contracts on PostgreSQL and
MySQL/MariaDB, including retained-command automatic-loss behavior. Then validate
fresh canonical replay, populated adoption, invalid data, MySQL nontransactional
DDL retry and idempotency; discover and run the full suite on both engines and
inspect final native schema. TLS/mTLS, bounded timeout, IPv6/socket/nonpublic
schema and live replica routing require their actual infrastructure evidence.
The overall modernization goal remains open.
