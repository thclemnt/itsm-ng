# Notification admission fixture hierarchy

At `8c00b7669b02ba1d74c7d93f37eb7393959b3d91`, the complete PostgreSQL run
finished 175/178 contracts in 751.162168 seconds. Its notification admission
retry assertion observed accepted=1, attempts=2 and queued=2 rather than the
required child refusal followed by two ancestor admissions.

The contract directly inserted a child Entity at level1. Frozen seed data and
the actual root0 native row both have level1. Notifications are selected by
descending entity depth, so that malformed hierarchy leaves child/ancestor
precedence undefined. Root's controlled public-API probes observed PostgreSQL
selecting root first at the tie: two accepted root queues suppress both child
attempts. MariaDB happened to select child first and passed with the same tie.
That incidental result does not establish correct hierarchy or portable order.

In the comparison, deriving child depth from actual parent depth plus one makes
the original contract pass on both engines: 22 cases and 75 assertions. The actual
queue hook sees two child vetoes then two ancestor admissions, yielding explicit
event refusal while admitting the two retry queues. All four before/derived
probes restore the exact root row, remove their owned child and leave transaction
level zero. The external wrapper initially failed to decode framework diagnostic
text preceding a cleanup JSON object; raw evidence contained the successful
cleanup. Embedded decoding recovered those results without rerunning fixtures.
Logs are `notification-order-{pg,mysql}-{before,derived}-8c.log` in the cloud
evidence directory; before/derived probes remain diagnosis rather than execution
of this final fixture change.

The fixture now uses public `Entity::add()` inside its existing caller transaction.
The owning tree lifecycle derives parent, level and complete name and executes its
normal cache/session hooks. A native assertion verifies the resulting actual
parent/depth. Original recipient-count, veto, overlap, queue, Contract rollback
and retry assertions remain intact. Existing isolated cache and full Session
restoration contain the public hooks; final cleanup additionally verifies the
complete root row and absence of the owned child after rollback. Production
notification selection, Boolean admission and tree behavior are unchanged.

This final source change has not run against a provider. PHP syntax, formatting
and whitespace checks are source checks only. Run the unchanged strengthened
notification contract on both providers, then the complete dynamically discovered
suite and final native schema checks with the independently required scalar
escaping repair. Earlier failures remain preserved; no passing full current
milestone is claimed.
