# Search architecture

The public `Search` class remains a compatibility facade for existing callers and plugins. Implementation now lives under `itsmng\Search`:

| Component | Responsibility |
| --- | --- |
| `SearchEngine` | Normalize parameters and orchestrate retrieval |
| `SearchOption` | Search metadata, available fields and meta relationships |
| `Input\QueryBuilder` | Existing form/criteria input and saved-search parameters |
| `Provider\SQLProvider` | Select a query plan, execute it, decode legacy rows |
| `Provider\TwoPhasePlanner`, `SearchPlan` | Eligibility, independent count, stable page, hydration |
| `Provider\CriteriaBuilder`, `JoinBuilder` | Field predicates, permissions and relationship joins |
| `Provider\FieldReference`, `ProjectionBuilder`, `SortBuilder` | Shared identifiers, typed projections and sort keys |
| `Provider\SelectList`, `SelectExpression`, `Dialect` | Explicit scalar/aggregate expressions and provider syntax |
| `Output\LegacyOutput` | Existing HTML, map and export rendering |

This follows the engine/input/provider/output separation examined in local GLPI `11.0/bugfixes` (`af37be7a77`). It reorganizes this fork's own implementation; it does not copy GLPI's newer search engine wholesale. The two-phase design was compared with `th/experimental/two-phase-search` at `5cd234178de98a4fca14d4cdf49e59e2ed934377` in the local ITSM checkout.

## Planning

1. Build the criteria tree. Root scalar predicates are evaluated directly. Multivalue and meta predicates produce independent matching-ID sets. Aggregate predicates filter a grouped subquery, so aliases never depend on MySQL's HAVING extension. Nested groups and saved-search AND-before-OR precedence are retained. Negation complements a matching set, including rows with no matching relationship.
2. Apply deletion, template, entity and item-specific access restrictions outside the complete user predicate. Count eligible IDs without display or sort-only joins.
3. Clamp the requested offset using that count. Select a page of IDs with only the joins needed for sorting. Explicit NULL ordering and an ID tie-break make pages deterministic. A zero limit or full export retrieves all matches.
4. Join display fields to those page IDs. Filtering one assigned group does not remove the other assigned groups from the displayed cell. Count-only requests skip hydration.

The page is a derived table in the hydration query, avoiding an application-generated list of thousands of IDs. Relation ID sets use an explicit aggregate to prevent MySQL from flattening dozens of predicates into one expensive join-order search. This is query construction, not SQL parsing or rewriting. Performance depends on filters, indexes and data; a smaller hydration stage is not a guarantee that every query becomes faster.

The old prototype excluded aggregate and meta criteria and could clamp an out-of-range page after hydration. The new planner handles both as matching sets and clamps first. It also preserves full display values and adds deterministic pagination.

## Expressions and compatibility

A projection declares its alias and whether it is aggregate. Grouped scalar projections use `MIN` consistently on both engines. PostgreSQL uses `STRING_AGG`, MySQL uses `GROUP_CONCAT`. Ordered DISTINCT values on PostgreSQL are aggregated into an ordered array, deduplicated by first ordinal position, then joined. NULL sort policy is explicit. Followups use timestamp and ID ordering; user/profile association arrays share their relation-ID ordering. Sorting user names uses the configured name format; IP-address sorting uses numeric IPv4/IPv6 components.

Native PostgreSQL booleans are cast to integers when encoding legacy cells, preserving NULLs from outer joins. Date computations use typed `CASE`, `NULLIF`, and platform date arithmetic. The transitional `Database\SearchProjection` parser has been deleted.

Plugins supplying raw MySQL SELECT fragments retain that path on MySQL. PostgreSQL rejects such fragments explicitly; the `addSelect` and `addDefaultSelect` hooks can return a structured `SelectList` instead. Its expressions must use provider-aware syntax before enabling the plugin on PostgreSQL. Arbitrary plugin SQL is not made portable automatically. Core search metadata with raw `computation` SQL still requires an explicitly portable expression.

Union searches (`AllAssets` and related types), maps, plugin item types, and nonempty `all`/`view` criteria retain the legacy query-planning path. The facade and output contracts remain available; these paths are not covered by the new planner's performance or portability guarantees. `disable_two_phase_search` in search parameters or application configuration selects that fallback for diagnosis.

## Validation

Use separate disposable installations with names beginning `itsm_port_`:

```sh
php tests/database-portability/search.php /path/to/config
php tests/database-portability/search-columns.php /path/to/config
```

The behavior contract writes fixtures in a rolled-back transaction. It checks stable pages, clamping, total counts, exports, zero limits, count-only retrieval, multivalue negation and complete display, aggregate/scalar OR and negated groups, zero counts, ordered aggregation under join multiplication, meta criteria, deletion/templates, entity isolation and boolean values. Column planning separately checks 728 columns for nine core types using `EXPLAIN` on each provider. The existing `tests/functional/Search.php` suite also passes on MariaDB with PHP 8.3: 33 methods and 565,107 assertions (CI's 512 MB memory limit). These tests do not establish all-plugin or full browser coverage.

For the optional composite-search benchmark:

```sh
php tests/database-portability/search-benchmark.php /path/to/mysql-config
```

It creates 300 tickets, each with eight assigned groups and twelve followups, verifies identical totals/page IDs, alternates plans, discards warmup runs and reports three-run medians. All fixtures roll back. On the local MariaDB test instance the legacy plan took 1.767 s versus 0.237 s for two-phase retrieval (about 7.5x faster). This synthetic fixture measures a 20-row page with three criteria and multiple display aggregates; it is not a production workload guarantee.
