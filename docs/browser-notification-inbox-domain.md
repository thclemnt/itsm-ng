# Recipient-owned browser notification presentation

This successor is a SOURCE candidate based on the accepted combined provider
composition `4d60504e8be067232f201829a6b7dfbf2e76ab6b`, whose ancestor is
`53cf6297473bc31400a0777ff905bd79f1e65698`. The original four inbox commits and
seven path bodies were applied unchanged before this locking-admission follow-up.
No PHP execution, compiler, database, browser or remote CI validation has run
for this candidate. It does not complete the modernization objective.

The public browser endpoint `ajax/notifications_ajax.php` checks the login and
calls `NotificationAjax::getMyNotifications()` or `raisedNotification()`.
Polling and acknowledgement previously used direct adapter queries, while
`NotificationQueueRepository` already owns queue deduplication, cron selection,
expiry and template detachment. This change extends that existing repository;
it does not introduce a parallel queue abstraction.

`BrowserNotificationInbox` accepts the connection selected by the application.
Polling selects mapped `QueuedNotification` entities for the positive recipient,
`ajax` mode and pending flag. Rendered messages remain available across active
entity changes: the existing queue is a recipient-owned snapshot, rather than
a new permission to read the linked ticket. Subject links still use the public
core or plugin form URL; visiting them retains the destination's authorization.
Notification admission, targets, entity resolution and transport remain in
their current public lifecycle, including plugin vetoes and logging.

The mapped entity owns the presentation transition. Acknowledgement locks the
selected pending message inside a transaction on the supplied writer, checks
recipient/channel ownership, sets the presentation time and deletion flag, and
flushes that one entity. It uses a fresh manager inside the transaction and
never substitutes the global adapter or keeps managed records across calls.
The caller's existing transaction/savepoint owns rollback. A replica-designated
adapter refuses acknowledgement before persistence. The original direct update
did not invoke CommonDBTM update hooks, audit history or notifications; this
operation does not introduce those unrelated effects.

Every repository locking selection calls the accepted
`MySQLConnection::assertCurrentReads()` admission API. A caller callback can
change its SESSION snapshot capability after the physical connection and outer
transaction were admitted; beginning a nested savepoint alone cannot protect
direct repository locking calls. The guard inspects actual capability and
managed ownership, refuses incompatible frames, and never repairs the caller's
transaction or session flag. This successor requires the existing provider470
composition/API. The retained original standalone53/V3 candidate is HELD and
must not be treated as independently ready or silently given a method-existence
fallback. No historical migration or provider policy implementation is copied.

The pending entity predicate is also applied after hydration, so native MySQL
collation cannot expose a noncanonical channel that the domain refuses to
consume. The public browser producer already writes the canonical `ajax` value.
This makes manually populated `AJAX`, padded channel/recipient strings and other
noncanonical collation variants ineligible for browser presentation on both
providers without altering their stored rows. Recipient IDs must be the exact
decimal string produced by the public browser admission path. The query has no
pagination; a future page limit must account for eligibility before limiting
visible results, rather than underfilling pages after this predicate.

Behavior corrections are explicit. A browser acknowledgement cannot cancel
a numeric-recipient mailing row. Repeating an acknowledgement retains the first
presentation time instead of overwriting it. Malformed, boolean, array and
out-of-range public identifiers no longer coerce into another message ID.
The endpoint's existing successful JSON response and public void method remain.
Queue admission, deduplication, cron retries, expiry, clone/purge logic and
historical migrations are unchanged.

`recipient` remains nullable TEXT because different delivery modes store user
IDs, addresses or opaque destination values. Adding a global User association
would be unsound. This bounded batch does not solve channel-discriminated
recipient ownership or retained polymorphic subjects; those need a separate
historical adoption design and plugin policy. It adds no schema/history change.

The new `browser-notification-inbox.php` contract exercises real mapped queue
rows and the public presentation API. It checks physical duplicates, literal
Unicode payloads, a non-active entity, future scheduling, other users/modes,
NULL recipients/kinds, core/plugin links, invalid identifiers, captured writer
ownership, replica designation, idempotency, nested rollback and complete native
payload preservation. A clone shares the same physical test connection for
routing-policy assertions; this is explicitly not live replica validation.
The original notification admission/recipient/template contracts must also run.

When the actual native SESSION capability exists, the contract invokes a caller
callback that enables it after outer-frame admission, checks direct-repository
and domain-command refusal, original physical frame/depth, unchanged mode and
complete native payload, then explicitly restores that caller setting and
retries with rollback. It reports the capability-case count; unavailable
capability is not evidence for the MariaDB snapshot behavior. The future queue
fixture is `2037-01-01`, within the older native MySQL TIMESTAMP range. Frozen
`Baseline20261001` declares queue `send_time`/`sent_time` as native TIMESTAMP;
the original2099 fixture assumption was invalid for MySQL and is retained only
in the held earlier SOURCE origin.

Next validation: independent SOURCE review; parser/style/metadata checks on an
owned composed worktree; focused inbox and existing notification contracts on
both disposable engines; concurrent acknowledgements and cleanup using two
real writer connections; original complete portability suites and final native
schema inspection. Browser polling/JSON validation requires restored assets and
an isolated authenticated application. All of these runtime checks are pending.
