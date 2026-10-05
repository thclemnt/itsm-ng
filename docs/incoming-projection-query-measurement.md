# Incoming projection catalogue workload

The per-planning-call global capture remains authoritative. It includes incoming consumers from every visible referencing schema and does not survive a DDL phase.

A proposed per-target MySQL lookup was source reviewed but rejected by an actual read-only MariaDB 10.11 comparison on the retained cloud catalogue. Three measurements took 1.463, 1.381 and 1.344 seconds for one global capture; eight target-scoped queries took 11.043, 11.043 and 11.045 seconds. Complete target projections matched. The read-only transaction retained migration receipts and the native table definition. These observations establish this workload's regression; they do not establish the entire component contract timeout's cause or claim equivalent MySQL 8.4 timings.

The original 300-second component diagnostic timed out during its second family after 696 assertions. Its protected-parent comparison passed; its final native admission failed because reconstruction was interrupted. Preserve that failed database and evidence. The next candidate shares one inspected table within a single read-only plan and retains fresh reads after DDL, native index checks and incoming-reference preservation. Runtime validation is pending.

Private comparison: incoming-query-comparison-76cd-actual-v1/result.json, SHA 57f77d6892703c710a3d9af7beea31b983818294fd5a2720cf056e419059fc0f. Diagnostic terminal SHA e675698ce56ae4bbae91c83b004cc8cf52fc1841d51d87a07fcf16e0439fd725.
