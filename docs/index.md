# Laravel Quantum Slipstream Drive

> Fold your Eloquent queries into a slipstream until the database wonders where everybody went.

A scoped Eloquent identity map, process-truth engine, and query-elision planner for Laravel.

```php
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

final class User extends Model
{
    use HasIdentityMap;
}
```

```php
$a = User::find(1);
$b = User::find(1);

$a === $b; // true — the second call issues no SQL
```

## The problem it solves

In a typical Laravel request, the same model is often fetched more than once. A middleware loads `$user`, the controller loads it again, an authorization policy loads it a third time, and a view composer fetches it once more. Each call issues its own `SELECT`. Eager loading and manual caching can prevent this, but both require deliberate coordination at every call site — and that discipline erodes as a codebase grows.

This package hooks into the Eloquent query pipeline so that elision is automatic. No changes are needed to controllers, policies, or view composers. Any model that opts in simply stops issuing redundant SQL for the duration of the current request or job.

## What it is — and what it is not

It is an **in-process identity map** scoped to a single request, queue job, or Octane worker turn. Within that scope, a hydrated model instance is treated as the authoritative fact about its own known attributes. Subsequent queries that can be answered from memory are answered from memory; all others fall through to SQL unchanged.

It is not a distributed cache. It is not a query-result cache. Nothing is serialized, stored to Redis, or shared between processes. When the scope ends (the HTTP response is sent, the job finishes), all in-memory entries are discarded.

It operates at the Eloquent Builder level — overriding `find`, `getModels` (which powers `get`), `first`, `sole`, `exists`, `count`, `pluck`, `sum`, `min`, `max`, `avg`/`average`, `update`, `delete`, `forceDelete`, `whereHas`, and `whereDoesntHave`. `value()` and `firstOrFail()` are served indirectly through the overridden `first()`. Relation resolution is also intercepted (see [Relation optimizations](usage.md#relation-optimizations)). Raw `DB::` queries and queries on models that do not use the `HasIdentityMap` trait are never touched.

The package goes further than the classic single-key identity map. It rewrites key-set queries so only genuinely unknown IDs hit the database, evaluates `WHERE` predicates against cached attributes to prune query results in memory, serves `where('email', ...)->first()` style lookups from a secondary unique-key index, and tracks entire query regions so broad `->get()` calls can prime the map for narrower follow-up queries.

## What it does

**Exact primary key** — zero SQL if already in memory:

```php
$userA = User::find(1);
$userB = User::find(1);

$userA === $userB; // true — no second query
```

**Key-set queries** — only unknown keys hit the database:

```php
User::find([1, 2, 3, 4]);
// Already in map: 1, 2. Confirmed absent: 3.
// SQL: SELECT * FROM users WHERE id IN (4)
// Result merged in original-key order: [user#1, user#2, null, user#4]
```

**Predicate evaluation** — extra `where` conditions evaluated in memory before SQL:

```php
// Map already holds user#1 (active=1) and user#2 (active=0).
User::whereKey([1, 2, 3])->where('active', true)->get();
// user#1: active == true → Match, returned from memory
// user#2: active == false → Reject, excluded without SQL
// user#3: not in map → queried
// SQL: SELECT * FROM users WHERE id IN (3) AND active = 1
```

**Unique-key queries** — `first()`, `firstOrFail()`, `sole()`, `value()`, and `exists()` can be served from memory when the column is declared unique in config:

```php
User::find(1); // email = alice@example.com now in map

User::where('email', 'alice@example.com')->first();          // no SQL
User::where('email', 'alice@example.com')->value('email');   // no SQL
User::where('email', 'alice@example.com')->exists();         // no SQL — true
```

**Relation resolution** — `belongsTo`, `morphTo`, `hasMany`, and `morphMany` are also served from memory when possible:

```php
$post = Post::find(1);   // User already in the map from earlier work

$post->user;             // no SQL — cached User returned directly

$user->load('posts');
$user->posts()->where('published', true)->get();   // no SQL — filtered in memory
```

**Coverage mode** — loading a broad result set primes the map for narrower follow-up queries:

```php
User::where('active', true)->get();  // loads all active users; coverage recorded

// Later in the same request:
User::where('active', true)->where('role', 'admin')->get();  // no SQL — subset answered from memory
```

**process_truth** (`mode = 'process_truth'` in config) — unsaved in-memory attribute changes are treated as authoritative:

```php
$user->active = false;  // dirty, not yet saved

// Under process_truth, predicates evaluate against the current in-memory value:
User::whereKey([$user->id])->where('active', true)->get();  // → empty, no SQL
```

Absent-key tracking means the package remembers which primary keys and unique-key values returned nothing from a previous query. If those same lookups are repeated under the same scope, no SQL is issued.

## When it helps and when it does not apply

**Helps most when:**

- A controller, service, policy, and event listener each load the same user model independently.
- A job processes a batch of records that all reference the same parent model (e.g. thousands of order lines sharing a handful of product records).
- An API endpoint assembles a resource from several related models that were already loaded earlier in the same request.
- A legacy codebase cannot be refactored to add eager loading at every call site.

**Does not apply when:**

- The query uses aggregates (`SUM`, `AVG`, `GROUP BY`), raw SQL, joins, or unions.
- The model does not use the `HasIdentityMap` trait.
- You need results to persist across requests (use a cache layer instead).
- The query involves pessimistic locking (`lockForUpdate`, `sharedLock`).
- The model resolves its table name dynamically (e.g. per-tenant table switching via a custom `getTable()`). `initializeHasIdentityMap()` reads `getTable()` **once per class** and caches it (`self::$tableNameCache[static::class]`), so a model that returns different table names per instance must not use the trait as-is — the cached table would key store entries against the wrong table.

## Documentation map

| Topic | Page |
|---|---|
| Installing and requirements | [Installation](installation.md) |
| Opting in, opting out, flushing | [Getting started](getting-started.md) |
| Lookups, `explain()`, predicates, relations | [Usage](usage.md) |
| Every config key and env override | [Configuration](configuration.md) |
| How the engine works internally | [Architecture & internals](architecture.md) |
| `explain()`, the `QueryDecided` event, the decision log | [Observability](observability.md) |
| The six test layers and the CI matrix | [Testing](testing.md) |
