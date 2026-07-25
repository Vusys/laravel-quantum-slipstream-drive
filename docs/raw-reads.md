# Raw read-serving

The identity map intercepts **Eloquent** reads. A raw query-builder read —
`DB::table('users')->find(1)` — bypasses Eloquent entirely, so by default it
always issues SQL even when that exact row is already cached.

With raw read-serving enabled, the package answers raw single-key and key-set
reads of the full row from the identity map, issuing **zero SQL** and returning
`stdClass` rows byte-identical to a bypassed query. There is no fixed cap on a
key set's size — a `whereIn` of any length is served as long as **every** key in
it is covered by a current snapshot; the moment one key is uncovered the whole
read falls through to SQL.

```php
'raw_reads' => [
    'enabled' => (bool) env('IDENTITY_MAP_RAW_READS', false),
],
```

The feature is **off by default** and risk-managed: enabling it installs a
per-driver connection resolver so `DB::table()` reads can be intercepted.

## How it stays correct

Serving a raw row that differs from what SQL returns — even by a value's type or
a missing column — would be a correctness bug. Two design choices prevent it:

1. **The served row is a real database row, never a reconstruction.** On a
   genuine full-column `SELECT` through Eloquent, the store snapshots the row
   exactly as the driver returned it — native types, every column, original
   order. A raw read replays that snapshot verbatim. Rows created in-memory
   (`create()`, mass-writes) are **never** snapshotted: their attributes are
   cast PHP values and may omit database defaults, so they could not reproduce a
   raw row faithfully.

2. **A snapshot is served only while it is provably current.** Any change to the
   cached row — an Eloquent save, a mass update, a raw write that flushes the
   class — invalidates the snapshot immediately. A read that isn't fully covered
   by a fresh snapshot falls through to SQL. The rule is absolute: a covered read
   returns exactly what SQL would, or it runs SQL.

## What is served

Only the shapes that map cleanly to a cached row:

- `DB::table('users')->find(1)` and `->where('id', 1)->first()/->get()`
- `DB::table('users')->whereIn('id', [1, 2, 3])->get()` (fully covered)

A key-set read is returned in **primary-key-ascending** order — a `whereIn`
without an explicit `ORDER BY` has no defined SQL order, and ascending by key is
a deterministic, portable ordering of the same set.

## What falls through to SQL

Anything that can't be answered from a snapshot, exactly:

- Any non-key predicate (`->where('id', 1)->where('active', 1)`) — the snapshot
  isn't consulted for filters.
- A column projection (`->select('name')`) — the snapshot is the whole row.
- Joins, aggregates, `GROUP BY`, `DISTINCT`, `LIMIT`/`OFFSET` that could change
  the row set, unions, locks.
- A key-set read where **any** key is uncovered (the whole read runs so no row is
  silently dropped).
- The write-invalidated window: the first read after a change re-snapshots.

## Warming

A snapshot is captured when a genuine `SELECT` runs. Immediately after
`create()` the row is already in the map, so the next Eloquent read is served
from memory without a `SELECT` — and therefore without a native-row snapshot. In
practice the map is flushed at request / job boundaries, so a later request's
first Eloquent read of a row issues the `SELECT` that warms the snapshot; raw
reads in that request are then served.

## Observability

A served raw read records a `ReturnRawRowFromMemory` plan, visible through
[`IdentityMap::explain()`](observability.md) and the streaming decision log:

```php
$explanations = IdentityMap::explain(function () {
    DB::table('users')->find(1);
});
// => Explanation { type: ReturnRawRowFromMemory, sqlExecuted: false, ... }
```

## Enabling at runtime

Enable the feature before the connection is first resolved — via env in a real
app. Toggling it after a connection is live requires a reconnect
(`DB::purge()`), because the swapped connection class is chosen at connection
build time.
