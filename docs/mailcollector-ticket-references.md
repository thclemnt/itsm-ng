# Mail subject references and ticket ownership

Remote IMAP run 37235444443 at the 76cd tree executed 10 methods and failed
`MailCollector::testCollect` on `fk_tickets_tickets_tickets_id_2`. The stack
passes through `MailCollector::collect`, public `Ticket::add`, its ordinary
post-add callback and public `Ticket_Ticket::add`. Source tracing identifies
an unchecked subject-reference path: when the subject contains a ticket
number that cannot be loaded, `buildTicket` still supplied `_linkedto` to the
new ticket. Its relation then referenced a nonexistent target.

The builder now remembers the actual public target lookup result. Existing
closed tickets and existing tickets ineligible for a followup retain the
normal new-ticket/link behavior. A missing subject target becomes ordinary
new-ticket input without an invalid link. Existing eligible-ticket followups,
mail header precedence, requester/supplier authorization, entity routing,
public lifecycle hooks, notifications and audit remain on their existing
paths. No identifier is invented and no FK is disabled. A concurrent purge
after a successful lookup remains subject to the existing FK/transaction
behavior; this narrow change does not claim to make all mail collection atomic.

The remote unexpected Laminas-address log assertion is downstream evidence,
not a demonstrated separate parsing regression. The full test intentionally
expects malformed-address and missing-date log entries, but checks and clears
them only after `collect` returns. The ticket exception aborts before that
cleanup; the generic after-test check observes the retained expected address
log. Both existing exact malformed-mail assertions and collection counts
remain unchanged. Only an actual rerun can establish their final results.

The new focused public test builds normal messages against actual open,
closed and publicly purged ticket identities. It persists the open followup,
checks the closed-ticket link, and verifies the stale reference creates a
ticket with no relation and no resurrection. It uses an actual requester email
and collector row, no mailbox connection, and explicit normal entity routing
because this focused builder test disables collection rules. The unchanged
full collect test still covers those real rules and mailbox parsing.

Source inspection and Git whitespace/inverse checks are the only validation
so far. PHP syntax, the focused test on both providers, actual IMAP/Dovecot
collection, broader application/portability suites and remote CI reruns are
pending. The earlier remote run remains failed. This is separate from the
LDAP fixture, canonical User creation and E2E-wrapper batches.
