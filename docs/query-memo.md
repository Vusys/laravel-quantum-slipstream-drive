# SELECT memo

The identity map serves reads by key: `find()`, `whereKey()`, a `belongsTo`. A query it can't answer by key, such as a constrained `where()`, a relation query with pivot columns, or a `DB::table()` read of part of a row, runs as SQL every time, even when the same query ran a moment ago and nothing has changed since.

With the SELECT memo enabled, an identical SELECT is answered from memory until anything could have changed its rows. "Identical" means the same connection, SQL and bindings. Each hit returns fresh copies of the rows, so a caller that modifies what it got can't change what the next caller gets.

```php
'query_memo' => [
    'enabled' => (bool) env('IDENTITY_MAP_QUERY_MEMO', false),
    'ttl_ms' => (int) env('IDENTITY_MAP_QUERY_MEMO_TTL_MS', 1000),
    'max_rows' => 1000,
    'max_entries' => 500,
],
```

It's **off by default**. Enabling it installs the same per-driver connection resolvers as [raw read-serving](raw-reads.md). Enable it before the connection is first resolved; toggling it at runtime requires `DB::purge()`.

## When it forgets

Everything is forgotten, across every connection, when:

- any connection runs anything other than a SELECT it can remember: a write, DDL, a locking read, a procedure call, `insert … returning`;
- a transaction begins, commits or rolls back, savepoints included;
- a request or queued job ends;
- `IdentityMap::flush()`, `IdentityMap::forget()` or a flush of one model class is called;
- `ttl_ms` passes.

## What always runs

These SELECTs always run, and are never remembered:

- a read from the write PDO, which is how Laravel's `refresh()` and `fresh()` read, and what `useWritePdo()` asks for;
- anything inside `IdentityMap::disabled()`, or a `withoutIdentityMap()` query;
- a SELECT whose answer depends on more than the data:
  - locks: `for update`, `for share`, `lock in share mode`, advisory locks;
  - the clock: `now()`, `current_timestamp`, `'now'`;
  - randomness: `random()`, `rand()`, UUID functions;
  - sequences: `nextval()`, `last_insert_id()`;
  - `sleep()`;
  - session and row-count functions;
- a query under `DB::pretend()`;
- a result larger than `max_rows`.

## The one staleness bound

A write by **another process** is invisible to this one, so nothing above can detect it. The TTL bounds it. A loop that polls the database more often than `ttl_ms` sees another process's change up to `ttl_ms` late. Polling with `refresh()` or `fresh()` is never affected, because both read from the write PDO.

## Measured

Per-request crawls with all-models mode on, memo off and then on, on the same seeded data:

| App | Requests | Queries |
| --- | --- | --- |
| Koel (API) | 63 | 413 → 357 (−13.6%) |
| Monica | 150 | 1,125 → 1,035 (−8.0%) |
| BookStack | 150 | 2,638 → 2,536 (−3.9%) |

No request ran more queries, every status code matched, and every response body was identical, apart from the pages that are random by design.
