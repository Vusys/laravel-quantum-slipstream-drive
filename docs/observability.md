# Observability

The package can tell you exactly what it decided for every query it saw — which plan it chose, which keys came from memory, and whether SQL ran. There are two entry points: `IdentityMap::explain()` for a bounded block of code, and a streaming decision log for a whole request or job.

## `explain()`

`IdentityMap::explain(Closure $fn)` wraps a block of code and returns a list of `Explanation` objects — one per query that the package considered. Each object contains:

| Field | Type | Description |
|---|---|---|
| `type` | `PlanType` | The decision the package made (see table below). |
| `modelClass` | `string` | Fully-qualified model class name. |
| `reason` | `string` | Human-readable explanation of why this plan was chosen. |
| `sqlExecuted` | `bool` | Whether a SQL query was issued. |
| `knownKeys` | `list` | Primary keys that were found in the store. |
| `missingKeys` | `list` | Primary keys confirmed absent. |
| `memoryKeys` | `list` | Keys answered from memory (subset of knownKeys after predicate evaluation). |
| `rejectedKeys` | `list` | Keys whose predicate evaluated to Reject. |
| `coverageRegion` | `?string` | String representation of the coverage region, when applicable. |

```php
$explanations = IdentityMap::explain(fn () => User::whereKey([1, 2, 3])->where('active', true)->get());
```

## `PlanType` values

| Value | Meaning |
|---|---|
| `execute_normally` | Query fell through to SQL unchanged (structural hazard or no memory data available). |
| `return_model_from_memory` | Single model returned from the store without SQL. |
| `return_null` | Known-absent key; returned `null` without SQL. |
| `return_collection_from_memory` | Entire collection returned from the store without SQL. |
| `return_empty_collection` | All keys were absent; returned empty collection without SQL. |
| `rewrite_primary_keys_and_merge` | Query rewritten to exclude known keys; SQL result merged with memory result. |
| `rewrite_predicate_and_merge` | As above, with predicate evaluation applied to known keys before merge. |
| `return_scalar_from_memory` | `value()` call answered from a cached attribute. |
| `return_exists_from_memory` | `exists()` call answered from a cached entry or absent record. |
| `return_count_from_coverage` | `count()` answered from coverage registry. |
| `return_belongs_to_from_memory` | `belongsTo` / `morphTo` relation resolved from the store. |
| `filter_has_many_in_memory` | `hasMany` / `morphMany` relation filtered from the store. |
| `return_collection_from_coverage` | `get()` answered from coverage registry. |
| `return_exists_from_coverage` | `exists()` answered from coverage registry. |
| `return_pluck_from_coverage` | `pluck()` answered from coverage registry. |
| `return_first_from_coverage` | `first()` answered from coverage registry. |
| `return_sole_from_coverage` | `sole()` answered from coverage registry. |
| `return_sum_from_coverage` | `sum()` aggregate answered from coverage registry. |
| `return_min_from_coverage` | `min()` aggregate answered from coverage registry. |
| `return_max_from_coverage` | `max()` aggregate answered from coverage registry. |
| `return_avg_from_coverage` | `avg()` / `average()` aggregate answered from coverage registry. |
| `where_has_from_graph` | `whereHas()` rewritten to a parent-key IN clause using the identity graph. |
| `where_doesnt_have_from_graph` | `whereDoesntHave()` rewritten using the identity graph. |
| `belongs_to_many_from_graph` | `belongsToMany` relation served from the identity graph (no SQL). |
| `where_pivot_in_memory` | `belongsToMany` query filtered in memory via captured pivot columns. |
| `backfill_columns_from_database` | Narrow `SELECT` issued to backfill missing columns on a cached entry (see [Partial models & column backfill](architecture.md#partial-models--column-backfill-partial_models)). |

`Explanation::__toString()` renders a short summary suitable for logging:

```
Plan: rewrite_predicate_and_merge
Model: App\Models\User
Reason: ...
Known keys: [1, 2]
Missing keys: [3]
SQL executed: yes
```

`Explanation::toArray()` returns the same fields as a structured payload — used as the log context by the streaming sink below, and useful for serialising into custom event sinks.

## Streaming decision log

`explain()` is the right API when the caller knows the scope to inspect. For a rolling decision log over a whole request / job / test, enable the streaming sink in `config/quantum-slipstream-drive.php`:

```php
'observability' => [
    'enabled' => (bool) env('IDENTITY_MAP_OBSERVABILITY', false),
    'channel' => env('IDENTITY_MAP_OBSERVABILITY_CHANNEL'),
    'level'   => env('IDENTITY_MAP_OBSERVABILITY_LEVEL', 'info'),
],
```

When `enabled = true`, every finalised plan emits to two sinks in addition to anything captured by `IdentityMap::explain()`:

1. A `Vusys\QuantumSlipstreamDrive\Events\QueryDecided` event is dispatched. Its single public property `explanation` carries the `Explanation` — wire bespoke sinks (Sentry breadcrumbs, NR custom events, custom log shippers) by listening to this event.
2. A log line is written to `channel` at `level`, with `Explanation::toString()` as the message and `['context' => Explanation::toArray()]` as the log context. If `channel` is null, the default log channel is used.

```php
use Illuminate\Support\Facades\Event;
use Vusys\QuantumSlipstreamDrive\Events\QueryDecided;

Event::listen(function (QueryDecided $event): void {
    Sentry\addBreadcrumb(new Sentry\Breadcrumb(
        level: Sentry\Breadcrumb::LEVEL_DEBUG,
        type: 'query',
        category: 'identity-map',
        message: $event->explanation->reason,
        metadata: $event->explanation->toArray(),
    ));
});
```

When `enabled = false`, no event is dispatched and no log line is written. `IdentityMap::explain()` keeps working unchanged — the streaming sink fires in addition, not instead, so nesting an `explain()` inside a streamed request still produces the buffer the caller asked for.

The sink is fire-and-forget. Built-in formatters beyond `Explanation::__toString()` / `toArray()`, sampling, and async delivery are out of scope — wrap your own listener (or use a queued log driver) if you need them.
