# Architecture and internals

This page describes how the engine works internally. You do not need any of it to use the package — [Getting started](getting-started.md) and [Usage](usage.md) cover the public surface — but it explains why each decision is safe and where the boundaries are.

## High-level data flow

Every Eloquent query on a model that uses `HasIdentityMap` is routed through `IdentityMapBuilder` instead of the standard Eloquent builder. The builder delegates to `QueryPatternExtractor`, which classifies the incoming query into one of five shapes: single primary-key equality, bounded key-set `IN`, unique-key equality, full-predicate coverage candidate, or structural-hazard bypass.

For shapes the package can handle, it consults `IdentityMapStore` or `CoverageRegistry` under a scope fingerprint that isolates entries by soft-delete variant and active global scopes. If memory can fully answer the query, no SQL is issued. If memory can partially answer it (some keys known, some not), the query is rewritten to exclude the known portion and the two result sets are merged. If memory cannot safely answer it at all, the query falls through to SQL unmodified.

The decision order within the builder is: exact PK hit → absent-key short-circuit → key-set rewrite → unique-key index lookup → coverage region check → SQL execution with result remembering.

## Opt-in mechanism: HasIdentityMap

Applying the `HasIdentityMap` trait to a model makes five structural changes:

1. `newEloquentBuilder()` is overridden to return `IdentityMapBuilder` instead of the standard builder.
2. `newBelongsTo()` and `newMorphTo()` are overridden to return `MemoryBelongsTo` and `MemoryMorphTo`.
3. `newHasMany()` and `newMorphMany()` are overridden to return `MemoryHasMany` and `MemoryMorphMany`.
4. `newBelongsToMany()` is overridden to return `MemoryBelongsToMany`.
5. `bootHasIdentityMap()` registers model event listeners: `retrieved`, `saved`, `deleted` unconditionally, plus `restored` and `forceDeleted` when the model also uses `SoftDeletes`.

The trait is model-scoped. Models without it are never stored in or served from the map, and queries on those models are entirely unaffected.

## The store: IdentityMapStore and IdentityEntry

`IdentityMapStore` is a Laravel singleton. It holds two hash-maps: `$entries` for live model instances and `$absent` for confirmed-missing primary keys. Both are keyed by a compound string: `connection|modelClass|table|pkName|pkValue|scopeFingerprint`.

Each entry in `$entries` is an `IdentityEntry` containing:

- **model** — the actual Eloquent model instance.
- **AttributeKnowledge** — a per-column map of `AttributeFact` objects (original value, current value, dirty flag, confidence, source).
- **RelationKnowledge** — a record of which relations are fully loaded and the primary keys of their members.
- **LifecycleState** — `Exists`, `SoftDeleted`, or `Deleted`.
- **version** — an integer incremented on every update; used internally to detect whether a cached plan is still valid.

The absent map uses the same key format but stores only a `true` sentinel. When a query confirms that a key does not exist in the database, that key is added to `$absent`. Subsequent identical lookups return `null` (or are excluded from results) without touching the database.

## Scope isolation: ScopeFingerprinter

Every entry in the store is namespaced by a scope fingerprint so that models retrieved under different query conditions never cross-contaminate. The fingerprinter captures three dimensions:

- **Soft-delete variant** — three slots: the default scope (excludes trashed), `withTrashed()`, and `onlyTrashed()`. A `User::find(1)` and a `User::withTrashed()->find(1)` produce different fingerprints and are stored separately.
- **Extra global scopes** — any additional global scopes applied to the model are hashed into the fingerprint.
- **Connection name** — already part of the composite key, making multi-database setups safe by default.

This fingerprinting is what makes the package safe under Laravel Octane: two concurrent requests may share the same PHP process, but they execute under different scopes and will always produce distinct fingerprints. The store is flushed between requests anyway, but the fingerprinting provides an additional layer of isolation.

## Query classification: QueryPatternExtractor

`QueryPatternExtractor` analyses the query's WHERE clauses, joins, groups, havings, locks, and SELECT list before any memory lookup is attempted. It classifies the query into one of five shapes:

1. **Single PK equality** — `WHERE id = ?` with no other constraints the package cannot evaluate.
2. **Bounded key-set IN** — `WHERE id IN (?, ?, ...)` optionally with additional AND predicates.
3. **Unique-key equality** — `WHERE email = ?` (or a compound unique key) where all columns in one of the configured unique indexes are present as equality conditions.
4. **Coverage candidate** — a predicate-only WHERE clause with no key constraints; eligible for the coverage registry.
5. **Structural-hazard bypass** — anything else: the query falls straight through to SQL.

Structural hazards that trigger bypass include: joins, unions, `GROUP BY`, `HAVING`, pessimistic locks (`lockForUpdate`, `sharedLock`), non-string SELECT columns introduced by `withCount` or `selectRaw`, and `orWhere` clauses. The extractor is stateless and pure — it only reads the query object, never modifying it.

## Predicate evaluation

When the extractor identifies predicates that should be evaluated in memory, `PredicateExtractor` converts the Eloquent WHERE clause list into a typed tree. The tree has five node types:

- **`AndNode`** — a list of child nodes that must all evaluate to `Match`.
- **`ComparisonNode`** — a single column/operator/value triple; supports `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`.
- **`InNode`** — `whereIn` (positive) or `whereNotIn` (negated).
- **`NullNode`** — `whereNull` or `whereNotNull`.
- **`BetweenNode`** — `whereBetween` (positive) or `whereNotBetween` (negated).

`PredicateEvaluator` walks the tree against the `AttributeKnowledge` of a cached entry and returns one of three results:

- **`Match`** — the entry satisfies all conditions; it can be returned from memory.
- **`Reject`** — at least one condition is definitely false; the entry can be excluded without SQL.
- **`Unknown`** — the entry does not have a known value for a required attribute, or an operator is not supported. The entry is excluded from the memory path and its key is forwarded to the SQL query.

`Unknown` is the safe fallback. It never produces a wrong answer; it produces a SQL query. An entry with only partial attributes loaded — for example, from a `select('id', 'name')` query — will return `Unknown` for any predicate on a column outside that select list, and the key will be re-fetched.

Under `process_truth` mode, the evaluator uses the current in-memory attribute value (which may be dirty) instead of the original database-committed value. This is the only mode in which the package can return results that differ from what the database currently contains.

## Attribute knowledge

`AttributeKnowledge` tracks what the package knows about a model's columns. For each column that has been observed, it stores an `AttributeFact` containing:

- **originalValue** — the value as it was last committed to the database (or hydrated from it).
- **currentValue** — the value currently on the model instance, which may differ if the model is dirty.
- **isDirty** — whether the two values differ.
- **confidence** — `Certain` (hydrated from a `SELECT *` or explicitly confirmed) or `Assumed` (inferred from a partial select or a mass-write plan).
- **source** — where the fact came from. One of the `FactSource` enum cases:
  - `HydratedFromDatabase` — read directly from a `SELECT` result.
  - `AssignedInMemory` — user code assigned the attribute on the model instance.
  - `CastedModelAttribute` — value reflects an Eloquent cast applied during hydration.
  - `AppendedAttribute` — value comes from a model accessor in `$appends`.
  - `RelationDerived` — value was inferred from a related model (e.g. a foreign key set when a relation was assigned).
  - `MassWrite` — value was written by a bulk `update()` whose predicate matched the entry.
  - `Unknown` — fact source could not be classified.

The `allColumnsKnown` flag is set when a model is hydrated from a full-row query. When it is `false`, the predicate evaluator will return `Unknown` for any column not present in the fact map, preventing the package from returning a stale partial model in place of a full-row result.

## Unique-key index

`UniqueKeyIndex` is a secondary hash-map inside the store, keyed on a fingerprint of `connection|class|table|scopeFingerprint|sorted(column→value)`. It enables `where('email', '...')->first()` style queries to be answered from memory without a linear scan of all entries.

When a model is remembered by the store, its attribute facts are cross-referenced against the unique column sets declared in config. For each declared unique set where all columns are known and `Certain`, an entry is added to the unique-key index pointing at the primary key of the cached model.

Stale index entries are detected at lookup time: if the primary-key entry retrieved via the index no longer has matching attribute values (because the column was updated), the index entry is discarded and the lookup falls through to SQL.

Under `process_truth` mode, the unique-key index is bypassed entirely. The index is built on original (committed) values; querying against dirty values via the index would produce incorrect results.

## Coverage: CoverageRegistry and SubsetChecker

`CoverageRegistry` tracks entire query regions that have been fully resolved. After a SQL query whose shape and predicate are safe for caching, the registry stores a `CoverageEntry` recording: the predicate region (the AND-tree of conditions), the set of primary keys returned, the column set loaded, and the scope fingerprint.

When a subsequent query arrives, `SubsetChecker` tests whether the new query's predicate region is provably a subset of a recorded coverage region. If the new conditions are strictly narrower — every condition in the new query is also present in the recorded region, or adds further restrictions — then the registered primary keys are re-evaluated against the new predicate using `PredicateEvaluator`, and the result is assembled from the in-memory entries for those keys. No SQL is issued.

Coverage entries are invalidated conservatively. When a model is saved, the registry flushes any coverage entries whose predicate region references columns that were changed. When a model is created or deleted, the entire coverage for that model class is flushed, since a new row could fall into any previously-recorded region.

## Process-truth vs database-truth

The `mode` config key controls a single behavioural switch: whether dirty in-memory attributes affect predicate evaluation.

| Mode | What it does | Default? |
|---|---|---|
| `default` | Predicates evaluate against the last-committed (original) attribute value. Dirty mutations are ignored until `save()`. In-memory results always match a fresh `SELECT`. | **yes** |
| `process_truth` | Predicates evaluate against the current in-memory value, which may be dirty. The unique-key index path is bypassed under this mode, since the index is keyed on original values. | |

`mode = process_truth` is the only setting that can make memory-served results differ from what `SELECT` would return on a fresh connection. Use it when your workload expects assigned-but-unsaved attribute writes to be visible to downstream queries within the same request.

The `IDENTITY_MAP_MODE` environment variable may be used to override the config value without republishing.

The set of optimizations the package performs — primary-key reuse, key-set rewriting, unique-key lookup, coverage, the relation graph, and `whereHas` rewriting — is not individually configurable; they are always on. Disable per query with `->withoutIdentityMap()` or per scope with `IdentityMap::disabled(...)`.

### Upgrade note: `attribute_truth` → `mode`

Earlier pre-1.0 builds read the toggle from a config key named `attribute_truth` (env var `IDENTITY_MAP_ATTRIBUTE_TRUTH`) with values `database_only` / `process_truth`. That key never appeared in the published config file, so most installs never set it. It has been renamed to `mode` (env var `IDENTITY_MAP_MODE`) with values `default` / `process_truth`.

The old key is **not** read anymore: installs that still have `attribute_truth` set will silently fall back to the new default (`mode = default`). To retain previous behaviour:

| Old | New |
|---|---|
| `'attribute_truth' => 'database_only'` (or unset) | `'mode' => 'default'` (or unset) |
| `'attribute_truth' => 'process_truth'` | `'mode' => 'process_truth'` |
| `IDENTITY_MAP_ATTRIBUTE_TRUTH=process_truth` | `IDENTITY_MAP_MODE=process_truth` |

If you published the config, re-publish (or delete `attribute_truth` and add `mode`) and update any `.env` references. If you never published the config, only the environment variable rename matters.

## Identity graph (`relation_graph`)

`IdentityGraph` records `(parent, relation, related)` edges between mapped models so that relation queries can be answered from memory when the package has seen enough of the structure. It supports:

- **Plain `RelationEdge`** entries for `belongsTo` / `hasMany` / `morphMany` / `morphTo`, captured each time a relation is hydrated.
- **`PivotEdge`** entries for `belongsToMany`, including the captured pivot column values so that `wherePivot()`-style filters can be evaluated against the graph instead of the pivot table.
- **`RelationCoverage`** / **`PivotCoverage`** markers that record "this parent's relation is fully loaded" so subsequent `$user->roles` reads can be served without SQL.

The graph powers the `where_has_from_graph`, `where_doesnt_have_from_graph`, `belongs_to_many_from_graph`, and `where_pivot_in_memory` plans. It is invalidated per-model on `saved` (for the changed model's identity) and per-class on creation, deletion, and rolled-back transactions touching that class.

| Config key | Default | Env override | Effect |
|---|---|---|---|
| `relation_graph.enabled` | `true` | `IDENTITY_MAP_RELATION_GRAPH_ENABLED` | Disable to bypass all graph-based plans; relation traversal falls back to per-relation memory paths or SQL. |
| `relation_graph.max_edges` | `50000` | `IDENTITY_MAP_RELATION_GRAPH_MAX_EDGES` | When exceeded, the graph flushes entirely (safest behaviour). `0` removes the cap; a malformed value falls back to the default. |
| `relation_graph.max_coverage_entries` | `5000` | `IDENTITY_MAP_RELATION_GRAPH_MAX_COVERAGE` | When exceeded, the graph flushes entirely. `0` removes the cap; a malformed value falls back to the default. |

## Store size caps (`store_caps`)

The identity-map store, unique-key index, and coverage registry accumulate state for the life of a scope. That is bounded for a normal HTTP request, but a single long-running queue job that iterates millions of rows would otherwise grow them without limit — job-boundary flushes only fire *between* jobs, not within one. These caps bound that growth.

When a store exceeds its cap it is **flushed in full**, mirroring the identity graph. Flush-all is the only safe semantics: coverage regions and absence markers reference live entries, so evicting individual entries (LRU or otherwise) could leave a coverage region that answers a query the database would not. A flush only ever costs a cold cache on the next query — never a wrong answer.

| Config key | Default | Env override | Effect |
|---|---|---|---|
| `store_caps.max_entries` | `100000` | `IDENTITY_MAP_MAX_ENTRIES` | Caps `IdentityMapStore` live entries + absence markers combined. Flush-all on overflow. |
| `store_caps.max_unique_keys` | `100000` | `IDENTITY_MAP_MAX_UNIQUE_KEYS` | Caps the `UniqueKeyIndex` (live + absent fingerprints). Flushes only the index — point lookups miss to SQL until rebuilt. |
| `store_caps.max_coverage_entries` | `50000` | `IDENTITY_MAP_MAX_COVERAGE_ENTRIES` | Caps recorded `CoverageRegistry` regions. Flush-all on overflow. |

Set any cap to `0` to disable it (unbounded). The defaults are generous; most applications never approach them within a single scope.

## Partial models & column backfill (`partial_models`)

When a cached entry was loaded with a narrow `select(['id', 'name'])` and a later query asks for additional columns, the package can either re-run the original query (default) or issue a small `SELECT only_missing_columns FROM table WHERE id = ?` and merge the result into the cached instance.

| Value | Behaviour |
|---|---|
| `query_normally` *(default)* | Cache miss → execute the full original query. Safe, equivalent to having no backfill. |
| `backfill_missing_columns` | Cache hit on the primary key but missing some requested columns → issue a narrow backfill SELECT, merge into the cached model, return from memory. Dirty in-memory attributes are preserved (only `originalValue` is updated for those columns). |

Backfill fires only for point lookups: `find()`, unique-key lookups, and `MemoryBelongsTo`. Coverage paths and `whereHas` rewrites still fall through to a full `SELECT` when columns are missing. Each backfill emits a `backfill_columns_from_database` explanation with `sqlExecuted: true`.

Override via the `IDENTITY_MAP_PARTIAL_MODELS` environment variable.

## Schema discovery (`schema_discovery`)

`SchemaDiscovery` inspects each model's table on first use via `Schema::getIndexes()` / `Schema::getColumns()` and feeds the result into both the unique-key index and the per-column driver semantics. Config-declared unique indexes (under `models.{ClassName}.unique`) take precedence; discovered indexes supplement them.

| Config key | Default | Env override |
|---|---|---|
| `schema_discovery.enabled` | `true` | `IDENTITY_MAP_SCHEMA_DISCOVERY` |

Discovery results are cached on the singleton resolver and flushed on the same scope boundaries as the store (request termination, job processed/failed, scope flush). Disable it (`IDENTITY_MAP_SCHEMA_DISCOVERY=false`) if your DB driver does not expose index metadata in a way Laravel can read, or if you want to lock the package to only the unique sets declared in config.

## Driver semantics (`database_semantics`)

The predicate evaluator resolves comparisons through a per-connection `DriverSemantics` (one of `SqliteSemantics`, `MySqlSemantics`, `MariaDbSemantics`, `PostgresSemantics`, or `ConservativeSemantics`). Integer, boolean, UUID, and null comparisons are always resolved confidently. The `database_semantics.{driver}.string_comparisons` config key controls how string equality is handled:

| Value | Behaviour |
|---|---|
| `database_collation` *(default)* | Read the column collation reported by `Schema::getColumns()` and compare under that collation. Falls back to the driver default — case-sensitive for SQLite/Postgres, Unknown for MySQL/MariaDB — when the collation is missing. |
| `php_strict` | Treat every string column as case-sensitive byte-equality. Fast, but **wrong** on MySQL with case-insensitive collations: in-memory results will diverge from SQL. |
| `conservative_unknown` | Return `Unknown` for every string comparison and let SQL handle it. Maximally safe but eliminates most string-predicate elision. |

Each driver has its own env var (`IDENTITY_MAP_SQLITE_STRING_COMPARISONS`, `IDENTITY_MAP_MYSQL_STRING_COMPARISONS`, `IDENTITY_MAP_MARIADB_STRING_COMPARISONS`, `IDENTITY_MAP_PGSQL_STRING_COMPARISONS`). Set them when your MySQL deployment uses a case-insensitive collation (`utf8mb4_unicode_ci`, `utf8mb4_general_ci`, etc.) and you observe predicate-evaluation mismatches.

## Lifecycle hooks and automatic flushing

The store and coverage registry are flushed automatically at scope boundaries to prevent stale data from leaking between independent units of work.

**HTTP requests** — the store is flushed via `app()->terminating()` when the response is sent. Models hydrated during one request are never visible to the next.

**Queue jobs** — the store is flushed before and after each job via the `JobProcessing`, `JobProcessed`, and `JobFailed` events. This applies to both traditional queue workers and Octane workers processing jobs. A crashed job does not leave stale entries visible to the next job.

**Database transaction rollback** — a per-connection `TransactionJournal` snapshots the pre-modification state of any map entry touched inside a transaction. On `TransactionRolledBack` the journal **restores** those snapshots into the store, so cached attributes return to their pre-transaction values rather than reflecting rows that no longer exist. Coverage and graph entries for any model class touched in the rolled-back level are invalidated. Nested savepoints stack: rolling back an inner savepoint restores only that level; an outer rollback restores everything inherited by the parent. If a rollback fires without a matching tracked `begin()` — for example, when the package boots mid-transaction — the store, coverage registry, and identity graph are flushed entirely as a safe fallback.

**Model events** — within a scope, three to five model events drive incremental updates rather than full flushes (three by default; five when the model uses `SoftDeletes`):

| Event | Action |
|---|---|
| `retrieved` | Model added to store with all currently-known attributes. |
| `saved` | Cached attributes updated to match committed values; coverage flushed for changed columns (or for the whole class on creation). |
| `deleted` | Entry marked `Deleted`; coverage for the model class flushed. |
| `restored` *(SoftDeletes only)* | Treated as a save; coverage for the model class flushed. |
| `forceDeleted` *(SoftDeletes only)* | Entry removed from store entirely. |

## Mass writes

Bulk `->update([...])` and `->delete()` calls on a builder — those that affect multiple rows at once — also update the in-memory store rather than leaving it stale.

After the SQL executes, the package evaluates the builder's predicate against every cached entry for the affected model class:

- **`Match`** — the entry's attribute facts are updated to reflect the written values (for `update`) or the entry is marked `SoftDeleted`/`Deleted` (for `delete`).
- **`Reject`** — the entry is definitely outside the affected set; left unchanged.
- **`Unknown`** — the entry cannot be safely classified; it is evicted from the store so a future query will re-fetch it from the database.

Eviction triggers a coverage flush for the model class, since any previously-recorded query region may now be incomplete.

## See also

- [Observability](observability.md) — the `explain()` API, the `PlanType` catalogue, and the streaming decision log.
- [Configuration](configuration.md) — every config key and env override referenced above.
