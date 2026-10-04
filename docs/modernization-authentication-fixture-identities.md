# Authentication callback fixture identities

The original authentication-completion contract at `e658117283f872269c55782be33592519f988399` passes on PostgreSQL but fails on MariaDB during the real late nested account update. Native error 1406/22001 rejects the anonymous PHP class name written to `glpi_logs.itemtype`; that generated name includes a NUL and the source pathname and exceeds the mapped 100-character field. The failed run is retained in `owned-domains-followon-e658-e658117283f8-mysql-focused-20261004T033419764603Z-be00481f72`.

Both anonymous callback models already declare the normal User table. They now also declare the normal User domain identity. Their public nested completion, normal preference edits, callbacks, audit writes, rule outcomes, rejection assertions and cleanup stay intact. Schema widths and application audit behavior are unchanged. The prior PostgreSQL pass does not establish correct anonymous audit identity or embedded-NUL text transport semantics.

This source correction awaits independent review, PHP/style checks and the original contract on both providers. Full suites and application flows require separate evidence; the modernization goal remains open.
