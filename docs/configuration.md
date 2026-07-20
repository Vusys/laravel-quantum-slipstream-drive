# Configuration

Every setting ships with a safe default and an environment-variable override, so you can tune the package without publishing anything. Publish the file only when you want per-model unique keys or version-controlled settings:

```bash
php artisan vendor:publish --tag=quantum-slipstream-drive-config
```

This writes `config/quantum-slipstream-drive.php`. The sections below document every key it contains, its default, its env override, and what it does.

## `mode`

| | |
|---|---|
| **Env** | `IDENTITY_MAP_MODE` |
| **Default** | `default` |
| **Values** | `default`, `process_truth` |

Controls whether dirty in-memory attribute changes affect predicate evaluation.

- `default` — predicates evaluate against the last-committed attribute value. Dirty in-memory mutations are ignored until `save()`. In-memory results always match a fresh `SELECT`.
- `process_truth` — predicates evaluate against the current in-memory value, which may be dirty. This lets pending mutations affect query results within the same request. The unique-key index is bypassed under this mode because it is keyed on original values; querying it with dirty values would mislead.

`process_truth` is the only setting that can make memory-served results differ from what `SELECT` would return on a fresh connection. See [Process-truth vs database-truth](architecture.md#process-truth-vs-database-truth).

!!! note "Renamed from `attribute_truth`"
    Earlier pre-1.0 builds read this toggle from a key named `attribute_truth` (env `IDENTITY_MAP_ATTRIBUTE_TRUTH`) with values `database_only` / `process_truth`. That key is no longer read. Map `database_only` → `default` and keep `process_truth` as-is; rename the env var to `IDENTITY_MAP_MODE`.

## `models`

| | |
|---|---|
| **Env** | — (array only) |
| **Default** | `[]` |

Per-model configuration. Declare unique column sets here to enable unique-key lookups and absence tracking by columns other than the primary key. Each entry is a model class mapped to an array with a `unique` key — a list of column sets, where each set is itself a list (a single-column set is a one-element list; multi-column sets are compound unique keys):

```php
'models' => [
    App\Models\User::class => [
        'unique' => [
            ['email'],
            ['tenant_id', 'slug'],  // compound key
        ],
    ],
],
```

Config-declared indexes take precedence over anything found by [`schema_discovery`](#schema_discovery); discovery supplements them, it does not replace them. See [Unique-key lookups](usage.md#unique-key-lookups).

## `schema_discovery`

| Key | Env | Default |
|---|---|---|
| `schema_discovery.enabled` | `IDENTITY_MAP_SCHEMA_DISCOVERY` | `true` |

When enabled, the package inspects each model's table on first use via `Schema::getIndexes()` / `Schema::getColumns()` and registers any unique indexes it finds — including compound indexes — so unique-key elision fires without requiring entries in [`models`](#models). The discovered metadata also feeds the per-column [driver semantics](#database_semantics).

Disable it (`IDENTITY_MAP_SCHEMA_DISCOVERY=false`) if your DB driver does not expose index metadata in a way Laravel can read, or if you want to lock the package to only the unique sets declared in config. See [Schema discovery](architecture.md#schema-discovery-schema_discovery).

## `partial_models`

| | |
|---|---|
| **Env** | `IDENTITY_MAP_PARTIAL_MODELS` |
| **Default** | `query_normally` |
| **Values** | `query_normally`, `backfill_missing_columns` |

Controls what happens when a cached entry (loaded with a narrow `select([...])`) is missing a column a later query asks for.

- `query_normally` — cache miss → execute the full original query. Safe, equivalent to having no backfill.
- `backfill_missing_columns` — cache hit on the primary key but missing some requested columns → issue a narrow `SELECT only_missing_columns FROM table WHERE id = ?`, merge into the cached model, and return from memory. Dirty in-memory attributes are preserved (only `AttributeFact::originalValue` is updated for those columns).

Backfill fires only for point lookups (`find()`, unique-key lookups, and `MemoryBelongsTo`); coverage and `whereHas` paths still fall through to a full `SELECT`. See [Partial models & column backfill](architecture.md#partial-models-column-backfill-partial_models).

## `relation_graph`

| Key | Env | Default |
|---|---|---|
| `relation_graph.enabled` | `IDENTITY_MAP_RELATION_GRAPH_ENABLED` | `true` |
| `relation_graph.max_edges` | `IDENTITY_MAP_RELATION_GRAPH_MAX_EDGES` | `50000` |
| `relation_graph.max_coverage_entries` | `IDENTITY_MAP_RELATION_GRAPH_MAX_COVERAGE` | `5000` |

The [identity graph](architecture.md#identity-graph-relation_graph) records model-to-model relation edges so that relation queries — `whereHas`, `whereDoesntHave`, and `belongsToMany` traversal — can be answered from memory.

- `enabled` — turn the graph on or off entirely. Disabled, relation traversal falls back to per-relation memory paths or SQL.
- `max_edges` / `max_coverage_entries` — hard caps. When either is exceeded the graph is flushed entirely (the safest behaviour). A malformed value falls back to the default rather than coercing to `0`; a literal `0` removes the cap.

## `store_caps`

| Key | Env | Default |
|---|---|---|
| `store_caps.max_entries` | `IDENTITY_MAP_MAX_ENTRIES` | `100000` |
| `store_caps.max_unique_keys` | `IDENTITY_MAP_MAX_UNIQUE_KEYS` | `100000` |
| `store_caps.max_coverage_entries` | `IDENTITY_MAP_MAX_COVERAGE_ENTRIES` | `50000` |

Per-scope size caps. The store, unique-key index, and coverage registry accumulate state for the life of a scope — bounded for a normal request, but a single long-running queue job iterating millions of rows would otherwise grow them without limit. When a store exceeds its cap it is **flushed in full**: flush-all is the only safe semantics, because coverage and absence reasoning reference live entries and evicting individual ones could answer a query the database would not.

- `max_entries` — caps `IdentityMapStore` live entries + absence markers combined. Flush-all on overflow.
- `max_unique_keys` — caps the `UniqueKeyIndex` (live + absent fingerprints). Flushes only the index — point lookups miss to SQL until rebuilt.
- `max_coverage_entries` — caps recorded `CoverageRegistry` regions. Flush-all on overflow.

Values are parsed and validated in the service provider, so a malformed env value falls back to the safe default instead of coercing to `0`. Set any cap to a literal `0` to disable it (unbounded). See [Store size caps](architecture.md#store-size-caps-store_caps).

## `database_semantics`

| Key | Env | Default |
|---|---|---|
| `database_semantics.sqlite.string_comparisons` | `IDENTITY_MAP_SQLITE_STRING_COMPARISONS` | `database_collation` |
| `database_semantics.mysql.string_comparisons` | `IDENTITY_MAP_MYSQL_STRING_COMPARISONS` | `database_collation` |
| `database_semantics.mariadb.string_comparisons` | `IDENTITY_MAP_MARIADB_STRING_COMPARISONS` | `database_collation` |
| `database_semantics.pgsql.string_comparisons` | `IDENTITY_MAP_PGSQL_STRING_COMPARISONS` | `database_collation` |

Controls how the predicate evaluator resolves **string** equality per connection driver. Integer, boolean, UUID, and null comparisons are always resolved confidently — this setting only affects string semantics.

- `database_collation` *(default)* — read the column collation reported by `Schema::getColumns()` and compare under it. Falls back to the driver default (case-sensitive for SQLite/Postgres, `Unknown` for MySQL/MariaDB) when the collation is missing.
- `php_strict` — treat every string column as case-sensitive byte-equality. Fast, but **wrong** on MySQL with case-insensitive collations: in-memory results will diverge from SQL.
- `conservative_unknown` — return `Unknown` for every string comparison and let SQL handle it. Maximally safe but eliminates most string-predicate elision.

Set the relevant driver's env var when your MySQL/MariaDB deployment uses a case-insensitive collation (`utf8mb4_unicode_ci`, `utf8mb4_general_ci`, etc.) and you observe predicate-evaluation mismatches. See [Driver semantics](architecture.md#driver-semantics-database_semantics).

## `observability`

| Key | Env | Default |
|---|---|---|
| `observability.enabled` | `IDENTITY_MAP_OBSERVABILITY` | `false` |
| `observability.channel` | `IDENTITY_MAP_OBSERVABILITY_CHANNEL` | `null` (default log channel) |
| `observability.level` | `IDENTITY_MAP_OBSERVABILITY_LEVEL` | `info` |

The streaming decision log. When `enabled` is `true`, every finalised plan dispatches a `QueryDecided` event and writes a log line to `channel` at `level`, in addition to anything captured by `IdentityMap::explain()`. When `false`, no event is dispatched and no log line is written; `explain()` keeps working unchanged.

- `enabled` — turn streaming on.
- `channel` — log channel to write to. `null` routes to the default log channel.
- `level` — log level (`debug`, `info`, `notice`, `warning`, …).

See [Observability](observability.md#streaming-decision-log).
