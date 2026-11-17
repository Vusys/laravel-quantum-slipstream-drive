# Changelog

All notable changes to `vusys/laravel-quantum-slipstream-drive` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## \[Unreleased\]

### Fixed

- Saving a model no longer marks its cached row as complete. A created model holds only what the insert set, and a `select()`ed one only its columns, but a save marked the row fully known, so the next full read was served a model missing the database's defaults. Saving a narrower instance also replaced a richer cached one; the saved values are now carried onto the cached instance instead, as a read already does. In the app this showed as `JsonResource` answering 201 for an existing user: its lazily loaded owner was the test's own `create()` instance, still flagged `wasRecentlyCreated`.
- Saving a cached model keeps Eloquent's dirty state until `save()` finishes. The model's own `UPDATE` runs through the identity-map builder, which mirrored the write onto the cached instance, the same one being saved, and synced all of its original attributes. So `$touches` never touched the owners, `getChanges()` and `wasChanged()` came back empty, and the package's own column-based coverage flush flushed nothing. The mirror now syncs only the columns it wrote, and skips those the instance is saving itself, so another unsaved edit on a cached instance also survives a mass update. Found by running an app's suite with the map on every model.
- A row read without its primary key (`value()`, `pluck()`, `select('name')`) no longer throws `MissingAttributeException` under `Model::shouldBeStrict()`. The map asked each hydrated row for its key, and strict mode throws for an unselected one. Found by running a strict-mode app's suite with the map on.
- A key-set read whose `select()` leaves the primary key out, such as `select('name')->whereKey([1, 2])->get()`, returns its rows again. Key-set rewriting merged the fetched rows by key, so it returned none, and it recorded every requested key as absent, so a later `find()` returned `null`. Such reads now run as plain SQL.
- `fresh()` and `refresh()` always read the database, as they do without the package. They ask for it through `useWritePdo()`, but a cached model could answer them, so they missed database defaults on a just-created model and writes the map can't see, like a foreign-key cascade. The row they read still refreshes the map.
- `find()` keeps Laravel's conditional return type: the model or `null` for a single id, a `Collection` for an array or `Arrayable` of ids. The identity-map override declared `Model|Collection|null` for every call, so callers had to narrow the result of `find($id)` before using it. Found in a Chain of Command controller.

### Changed

- Cap breaches now evict the least-recently-used tenth of the affected store instead of flushing it entirely ([#102](https://github.com/Vusys/laravel-quantum-slipstream-drive/issues/102)). Applies to the identity-map store (`store_caps.max_entries`), unique-key index (`store_caps.max_unique_keys`), coverage registry (`store_caps.max_coverage_entries`), and identity graph (`relation_graph.max_edges` / `max_coverage_entries`). Hot entries survive sustained churn; correctness is preserved because every consumer re-validates its references at serve time (a coverage region that lost an entry falls through to SQL), coverage regions referencing an evicted row are pruned with it, and an evicted edge bucket takes its same-key coverage grant with it. The key that tripped the cap is now stored rather than dropped.

- Renamed the truth-mode config key from `attribute_truth` (env `IDENTITY_MAP_ATTRIBUTE_TRUTH`, values `database_only` / `process_truth`) to `mode` (env `IDENTITY_MAP_MODE`, values `default` / `process_truth`). The old key is no longer read; installs that still set it fall back to the new default (`mode = default`). Map `database_only` → `default` and keep `process_truth`. See the [upgrade note](docs/architecture.md#upgrade-note-attribute_truth--mode).

### Added

- Raw `DB::table()` read-serving (`raw_reads.enabled`, env `IDENTITY_MAP_RAW_READS`, default off): raw single-key and bounded key-set reads of the full row against a mapped table are served from a database-native row snapshot captured on an earlier full-column Eloquent read — issuing zero SQL and returning `stdClass` rows byte-identical to a bypassed query. The snapshot is taken only from a genuine full-column `SELECT` (never `create()`/mass-write) and invalidated the instant the cached row changes; anything not fully covered falls through to SQL unchanged. New `ReturnRawRowFromMemory` plan type. See [Raw read-serving](docs/raw-reads.md).
- Documentation site: the README has been split into a [MkDocs Material](https://vusys.github.io/laravel-quantum-slipstream-drive/) site under `docs/`, deployed to GitHub Pages. The README is now a landing page with a topic table linking into the site.
- `CHANGELOG.md` (this file) and `CONTRIBUTING.md`.
- Scoped Eloquent identity map: opt in per model with the `HasIdentityMap` trait. Redundant reads within a request or job are served from memory; `withoutIdentityMap()` opts out per query and `IdentityMap::disabled()` opts out per scope.
- Key-set rewriting: `find([...])` / `whereKey([...])` queries are rewritten so only genuinely unknown IDs reach the database, with results merged back in original-key order.
- In-memory predicate evaluation for `=`, `!=`/`<>`, `>`, `>=`, `<`, `<=`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNotBetween`, and AND-trees, with a three-valued (`Match` / `Reject` / `Unknown`) evaluator that falls through to SQL whenever it cannot answer safely.
- Unique-key index: `where('col', …)->first()` / `firstOrFail()` / `sole()` / `value()` / `exists()` served from memory when the column is a declared or discovered unique key, with absence tracking by unique value.
- Coverage registry: a broad `->get()` records a query region so provably-narrower follow-up queries can be answered from memory without SQL.
- Relation optimizations: memory-backed `belongsTo`, `morphTo`, `hasMany`, `morphMany`, and `belongsToMany` (via the identity graph, including `wherePivot` predicates), each falling back to SQL on any unsafe condition.
- `process_truth` mode: evaluate predicates against dirty in-memory attribute values.
- Partial-model column backfill (`partial_models = backfill_missing_columns`): issue a narrow `SELECT` for missing columns on a point lookup instead of re-running the full query.
- Schema discovery: unique indexes and column collations read from the database and fed into the unique-key index and driver semantics.
- Per-driver string-comparison semantics (`database_collation` / `php_strict` / `conservative_unknown`) for SQLite, MySQL, MariaDB, and PostgreSQL.
- Store size caps (`store_caps`) and identity-graph caps (`relation_graph.max_edges` / `max_coverage_entries`) that flush safely on overflow.
- Automatic flushing at scope boundaries: HTTP request termination, queue job start/finish/failure, and transaction rollback (with a per-connection `TransactionJournal` that restores pre-transaction snapshots).
- Mass-write modeling: bulk `update()` / `delete()` propagate to cached entries, evicting any that cannot be classified safely.
- Observability: `IdentityMap::explain()` returns per-query `Explanation` objects, and an opt-in streaming decision log dispatches a `QueryDecided` event and writes a log line per finalised plan.
