# Processor stock AJAX fixture request context

At `e658117283f872269c55782be33592519f988399`, the original PostgreSQL processor ownership contract passes its actual stock creation and actor scoping controls, then fails when including the stock AJAX controller. The retained run is `owned-domains-followon-e658-e658117283f8-pg-focused-20261004T033913568877Z-33472fb219`. Shared bootstrap reaches its non-AJAX invalid-CSRF feedback path and reads an absent HTTP_REFERER.

The CLI fixture supplies the real controller POST but previously kept the surrounding CLI request URI. It now supplies the controller's actual AJAX URI, script path and Device form referrer for that scoped request, restoring all server variables afterward. Production bootstrap, CSRF/referrer policies, ACLs, actor grants, response parsing, stock expectations and all original assertions stay unchanged. The controller include remains anchored to its own directory.

This source correction awaits independent review and ROOT compilation, followed by the original contract on both providers. A CLI controller inclusion is separate from browser verification; the overall modernization goal remains open.
