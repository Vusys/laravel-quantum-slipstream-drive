# Usage

Once a model [opts in](getting-started.md), the map handles primary-key reuse and key-set rewriting with no further configuration. This page covers the richer paths: unique-key lookups, inspecting a decision with `explain()`, the predicates the engine can evaluate in memory, and the relation optimizations.

## Unique-key lookups

Declare unique indexes in the published config to allow `where('col', value)->first()` style queries to be answered from memory:

```php
// config/quantum-slipstream-drive.php
'models' => [
    App\Models\User::class => [
        'unique' => [
            ['email'],
            ['tenant_id', 'slug'],  // compound key
        ],
    ],
],
```

!!! tip "Automatic discovery"
    You do not have to declare every unique index by hand. With [`schema_discovery`](configuration.md#schema_discovery) enabled (the default), the package inspects each model's table and registers the unique indexes it finds. Config-declared indexes take precedence; discovered indexes supplement them.

Once configured, any of the following can be served without SQL when the model is already in the identity map:

```php
User::where('email', 'alice@example.com')->first();
User::where('email', 'alice@example.com')->firstOrFail();
User::where('email', 'alice@example.com')->sole();
User::where('email', 'alice@example.com')->value('email');
User::where('email', 'alice@example.com')->exists();
```

Additional `where` conditions on top of the unique key are evaluated in memory using the same predicate engine used for key-set queries. If the extra predicate can be evaluated and the unique key match is rejected, the package returns `null` or `false` with no SQL — because the unique key guarantees there can be no other row with that value.

```php
// Map holds user#1: email = alice@example.com, active = true
User::where('email', 'alice@example.com')->where('active', false)->first();
// → null, no SQL. The unique key found the only candidate; active = false rejects it.
```

Absence is also tracked: if a unique-key lookup returns `null` from the database, subsequent identical lookups skip SQL until the model is created and remembered.

## Explain a query decision

`IdentityMap::explain()` wraps a block of code and returns the decision the package made for every query it considered:

```php
$explanations = IdentityMap::explain(fn () => User::whereKey([1, 2, 3])->where('active', true)->get());
// Plan: rewrite_predicate_and_merge
// Model: App\Models\User
// Reason: ...
// Known keys: [1, 2]      ← evaluated in-memory (match or reject)
// Missing keys: [3]       ← unknown, sent to database
// SQL executed: yes
```

For the full list of fields each `Explanation` carries, every `PlanType` value, and the streaming decision log, see [Observability](observability.md).

## Supported in-memory predicates

The following are evaluated against cached attributes without touching the database. They apply both as extra conditions on top of a key-set or unique-key query, and as the basis for determining whether a unique-key candidate matches:

| Eloquent method | Operators |
|---|---|
| `where($col, $val)` / `where($col, '=', $val)` | `=` |
| `where($col, '!=', $val)` / `where($col, '<>', $val)` | `!=`, `<>` |
| `where($col, '>', $val)`, `>=`, `<`, `<=` | `>`, `>=`, `<`, `<=` |
| `whereIn($col, [...])` | `IN` |
| `whereNotIn($col, [...])` | `NOT IN` |
| `whereNull($col)` | `IS NULL` |
| `whereNotNull($col)` | `IS NOT NULL` |
| `whereBetween($col, [$min, $max])` | `BETWEEN` |
| `whereNotBetween($col, [$min, $max])` | `NOT BETWEEN` |
| Multiple `where` chained with `AND` | AND-tree |

Anything the package cannot evaluate in memory falls through to SQL unchanged — unsupported operators (`LIKE`, `ILIKE`), raw `whereRaw` clauses, `orWhere` conditions, and attributes not present on a partially loaded model. String comparisons may also resolve to `Unknown` (and fall through) depending on the configured [driver semantics](configuration.md#database_semantics). See [Predicate evaluation](architecture.md#predicate-evaluation) for how the engine decides.

## Relation optimizations

When `HasIdentityMap` is applied, five relation types gain memory-backed implementations. They fall back to SQL transparently on any condition the package cannot safely evaluate in memory.

### `belongsTo` / `morphTo`

Resolved without SQL when the related model is already in the identity map:

```php
$post->user;       // no SQL if User#N is already in the map
$comment->owner;   // no SQL for polymorphic relations too
```

Fallback to SQL when: FK is null, the related model class does not use `HasIdentityMap`, the entry is absent from the map, or the query has joins, unions, groups, havings, or a lock.

### `hasMany` / `morphMany`

When the parent's relation is already completely loaded, additional `where` constraints are evaluated in memory:

```php
$user->load('posts');                                    // marks the relation complete in the map

$user->posts()->get();                                   // no SQL — full cached collection
$user->posts()->where('published', true)->get();         // no SQL — filtered in memory
```

Fallback to SQL when: the relation has not been loaded, the relation was loaded with extra constraints (constrained eager load), the query adds joins/unions/groups/havings/lock/offset/limit, the predicate cannot be evaluated in memory, or any member of the loaded collection has left the identity map.

### `belongsToMany`

Many-to-many traversal is served from the [identity graph](architecture.md#identity-graph-relation_graph) when both sides are mapped and the pivot edges have been recorded. The graph stores `(parent, relation, related)` pivot edges, including pivot column values, so that `wherePivot`-style filters and basic predicates on the related model can be evaluated in memory:

```php
$user->load('roles');                                    // pivot edges + related models recorded

$user->roles;                                            // no SQL — served from the graph
$user->roles()->wherePivot('granted', true)->get();      // no SQL — pivot predicate evaluated in memory
```

Fallback to SQL when: the related model does not use `HasIdentityMap`, pivot coverage for the parent's `(class, id, relation)` slot has not been recorded, the query touches custom pivot accessors not present in the captured pivot columns, the query has joins/unions/groups/havings/lock/offset/limit, the relation uses `wherePivotIn` / `wherePivotNull` on columns not extractable to a predicate node, or the relation-graph cap is hit (config `relation_graph.max_edges` / `max_coverage_entries`). Disable the graph entirely with `IDENTITY_MAP_RELATION_GRAPH_ENABLED=false` if you suspect a bug.

See [Architecture & internals](architecture.md) for how the memory relation implementations work, and [Identity graph](architecture.md#identity-graph-relation_graph) for the data structure backing `belongsToMany`.
