# Changelog

All notable changes to `vusys/laravel-quantum-slipstream-drive` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Renamed the truth-mode config key from `attribute_truth` (env `IDENTITY_MAP_ATTRIBUTE_TRUTH`, values `database_only` / `process_truth`) to `mode` (env `IDENTITY_MAP_MODE`, values `default` / `process_truth`). The old key is no longer read; installs that still set it fall back to the new default (`mode = default`). Map `database_only` → `default` and keep `process_truth`. See the [upgrade note](docs/architecture.md#upgrade-note-attribute_truth--mode).

### Added

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
