# Testing

The package has six test layers, each targeting a distinct risk surface. Together they provide defence in depth: logic bugs, integration failures, configuration-space gaps, random edge cases, and performance regressions are all caught by different layers — often before any other layer would notice them.

## Overview

| Suite | Command | Included in CI |
|---|---|---|
| Unit + Feature + DataProviders | `composer test` | Yes |
| Performance | `vendor/bin/phpunit --testsuite Performance` | Yes (Bencher) |
| Fuzz | `composer fuzz` | Yes (`comprehensive` job, 4-DB matrix) |
| Mutation | `composer mutate` | Yes (Stryker dashboard) |

CI runs the full matrix: PHP 8.3, 8.4, 8.5 × Laravel 11, 12, 13 × SQLite, MySQL, MariaDB, PostgreSQL — 36 cells total. Set `DB_CONNECTION` to `sqlite` (default), `mysql`, `mariadb`, or `pgsql` to run a suite against a specific backend locally.

The four quality gates every change must pass before push are:

```bash
composer test          # PHPUnit, default suite (Package) — excludes Performance + fuzzer
composer analyse       # PHPStan / Larastan level 9, no baseline
composer pint:check    # Laravel Pint style check
composer rector:check  # Rector dry-run
```

## Unit tests

**Location:** `tests/Unit/` (see directory for the current set)
**Extends:** `PHPUnit\Framework\TestCase` — no database, no service container, no Laravel boot.

The unit suite tests the pure algorithms in isolation:

- `PredicateEvaluatorTest` — all three return values (`Match`, `Reject`, `Unknown`) for every supported operator and node type; AND-tree short-circuit behaviour; `process_truth` vs original-value routing.
- `AttributeKnowledgeTest` — `satisfies()` logic for full vs partial column knowledge; `recordFromModel()` and `mergeFromSaved()` behaviour.
- `PredicateExtractorTest` — conversion of Eloquent WHERE clause arrays into the typed node tree.
- `PredicateColumnsTest` — column set extraction from node trees.
- `CoverageRegistryTest` — `flushByColumns()` correctly invalidates only relevant entries; `isSubset()` for all supported predicate pair shapes.
- `RelationKnowledgeTest`, `RelationFactTest` — relation metadata tracking and fact structure.
- `ColumnSetTest`, `SubsetCheckerTest` — column set operations and subset checking logic.
- `ExplanationTest` — `Explanation` struct formatting and `__toString()` output.

The unit suite is the fastest feedback loop. Any regression in the pure core — a wrong return value from the evaluator, a broken column set operation — is caught here in isolation before an integration test is even needed.

Run a single unit test:

```bash
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit tests/Unit/PredicateEvaluatorTest.php
```

## Feature tests

**Location:** `tests/Feature/` (see directory for the current set, organised into subdirectories per subsystem)
**Extends:** `Vusys\QuantumSlipstreamDrive\Tests\TestCase` (Orchestra Testbench + SQLite)

The feature suite tests end-to-end behaviour with a real database and real Eloquent model lifecycle. It uses a fixed set of test models: `User` (with `HasIdentityMap` and `SoftDeletes`), `Post`, `Tag`, `Comment` (polymorphic morph), and `UuidUser` (UUID primary key).

Core files:

- `IdentityMapTest` — main builder path: `find()` caching and instance identity, absent-key tracking, `withoutIdentityMap()`, soft-delete scope separation, queries with joins/locks/aggregates that must bypass the map, `flush()` and `forget()`, the `explain()` API.
- `KeySetRewriteTest` — partial-hit rewriting: queries where some keys are in memory and some require SQL, with correct ordering and instance identity in the merged result.
- `BelongsToMemoryTest`, `HasManyMemoryTest`, `MorphManyMemoryTest`, `MorphToMemoryTest` — one file per relation type, each verifying memory resolution and SQL fallback conditions.
- `PredicateEvaluatorFeatureTest` — predicate evaluation integrated with real model hydration and Eloquent query building.
- `ProcessTruthTest` — dirty attribute changes evaluated correctly under `process_truth` mode.
- `MassWriteModelingTest` — bulk `update()` and `delete()` correctly propagate to the in-memory store.
- `UniqueKeyTest` — unique-key index lookups, absence tracking by unique value, compound keys.
- `QueryPatternExtractorTest`, `ScopeFingerprinterTest`, `CoverageRegistryFeatureTest`, `ServiceProviderTest` — coverage of remaining subsystems.

The feature suite verifies that the package integrates correctly with Eloquent hydration, global scopes, model events, and the soft-delete system. Most tests assert SQL query count explicitly (via `DB::getQueryLog()`) to confirm that memory hits genuinely avoid the database.

## Cartesian / data-provider tests

**Location:** `tests/Feature/DataProviders/` (5 files)
**Extends:** Same TestCase as feature tests; uses `ProvidesCartesian` concern.

These tests use PHPUnit data providers to generate the Cartesian product of multiple dimension arrays, running each combination as a separate test case. This brute-forces coverage of the configuration space without hand-writing exponentially many test methods.

| File | Dimensions covered |
|---|---|
| `ConfigPermutationTest` | Unique-key config shapes (none / single-column / compound / multi-index) crossed with lookup methods and absence-tracking paths |
| `PkTypeTest` | Integer and UUID primary key types |
| `LifecycleStateTest` | All three `LifecycleState` values (Exists, SoftDeleted, Deleted) |
| `WhereShapeTest` | Supported and unsupported WHERE operators; qualified vs unqualified column names; safe scoped queries |
| `KeySetShapeTest` | Full hit, partial hit, no hit, empty key-set |

The Cartesian suite catches bugs that only surface in specific combinations — for example, a predicate evaluation error that only appears when using UUID keys under `process_truth` mode with a `whereNotIn` condition. Those interactions are invisible to hand-written tests but explicit in a Cartesian product.

## Fuzz tests

**Location:** `tests/Fuzz/` (3 test files)
**Command:** `composer fuzz` (PHPUnit group `fuzzer`)
**CI:** runs in the `comprehensive` job against all four database backends

The fuzz suite uses seeded randomness so failures are reproducible. Each test method runs across multiple seeds × steps (default 3 × 20 = 60 iterations per method). When a test fails, the output includes `[seed=N step=M]`. Exact replay:

```bash
FUZZER_SEEDS=N FUZZER_STEPS=$((M+1)) composer fuzz
```

**`QueryCorrectnessTest`** — differential (oracle) testing. Runs the same query through both the identity-map path and `IdentityMap::disabled()`, then asserts the two results are identical. Five methods:

- `test_find_by_primary_key_matches_oracle` — `find()` with 60/40 known/absent key ratio.
- `test_where_key_collection_matches_oracle` — `whereKey()->get()` with random key subset and guaranteed-unknown IDs mixed in.
- `test_active_predicate_via_key_set_matches_oracle` — `whereKey()->where('active', ...)->get()` with partial warm state and predicate pruning.
- `test_where_has_with_graph_coverage_matches_oracle` — `whereHas('posts', …)` rewritten against the identity graph must match the bypassed query.
- `test_where_doesnt_have_matches_oracle` — `whereDoesntHave` rewrite inverts membership semantics correctly.

**`QuerySavingsTest`** — property-based SQL-count testing. Rather than checking equivalence, it asserts that the identity-map path fires *fewer* SQL queries than the oracle. Four methods:

- `test_find_warm_entry_fires_no_sql` — `find()` on an already-cached ID must issue 0 SQL (oracle: 1).
- `test_where_key_all_warm_fires_no_sql` — `whereKey()` on a fully-warm key set must issue 0 SQL (oracle: 1).
- `test_absent_tracking_fires_no_sql_on_repeat` — a second `find()` on a confirmed-absent ID must issue 0 SQL.
- `test_where_has_with_full_graph_coverage_fires_no_sql` — `whereHas` with complete graph coverage must answer from memory.

**`RelationalCorrectnessTest`** — dual-database relational correctness. Uses a secondary isolated database connection as the oracle so relation traversal and write→read consistency are verified against a real second database, not just the disabled-flag path. Four methods:

- `test_keyset_reads_match_oracle` — partial-hit keyset reads via `whereKey()->get()`.
- `test_relation_traversal_matches_oracle` — `user→posts→tag` and `user→comments` relation chains.
- `test_mutation_read_consistency_matches_oracle` — save/delete/restore then re-read; detects stale-cache bugs the oracle would expose as a mismatch.
- `test_pivot_mutation_read_consistency_matches_oracle` — `belongsToMany` pivot mutations (attach/detach/sync/updateExistingPivot) followed by re-reads must match the oracle.

Together the three files cover two orthogonal properties — *correctness* (results match the database) and *savings* (SQL count is reduced) — and extend correctness testing to relation traversal and write consistency across every supported DB engine.

## Performance tests

**Location:** `tests/Performance/` (1 file)
**Command:** `vendor/bin/phpunit --testsuite Performance` (separate suite, not run by `composer test`)

The performance suite measures wall-clock time and SQL query count, not functional correctness. Results are emitted to STDERR in a Bencher-compatible format and tracked for regression via the Bencher CI badge in the header.

Three benchmarks run 100 iterations each:

| Benchmark | Expected SQL queries |
|---|---|
| Repeated `find()` on a known ID with the identity map | 1 (first load only) |
| Repeated `find()` on an absent ID with absence tracking | 1 (first miss recorded) |
| Repeated `find()` with `withoutIdentityMap()` as baseline | 100 |

The performance suite catches query-count regressions: a code change that accidentally stops the cache from being consulted will produce 100 SQL queries instead of 1, which the suite will flag even if every correctness test still passes.

## Mutation testing

**Command:** `composer mutate` (runs Infection with 4 threads)
**Results:** `build/infection/summary.log`, `build/infection/infection.log`
**Dashboard:** Stryker badge in the header

Infection mutates the source code one change at a time and checks whether the test suite kills each mutant (i.e., at least one test fails). A surviving mutant means a line of code can be changed without any test noticing — which usually indicates either dead code or an under-specified test.

Intentional exclusions are documented in `infection.json5`. The suppressions fall into two categories:

- **False positives** — mutations that are behaviourally equivalent given the package's invariants (e.g. the `version++` counter, which no test observes directly because it is an internal staleness token).
- **Architectural limits** — mutations that escape because the internal data structure required to kill them is not accessible from the test layer (e.g. a logical condition in `IdentityMapBuilder::getModels()` where the only distinguishing state is inside the absent map).

The mutation suite validates that the correctness tests are actually discriminating, not merely achieving line coverage. A high mutation score means a change to a predicate condition or a return value will be caught; it does not guarantee correctness in the absence of a failing test, but it significantly raises the bar.

## How the suites complement each other

| Suite | Catches | Does not catch |
|---|---|---|
| Unit | Logic bugs in pure algorithms; wrong return values from the predicate evaluator | Integration failures; database-specific behaviour |
| Feature | Eloquent integration bugs; soft-delete scope separation; event wiring | Configuration-space combinations; random edge cases |
| Cartesian | Mode/type/operator combination bugs invisible to hand-written tests | Random-state edge cases; performance regression |
| Fuzz | Behavioral divergence from SQL baseline under random model state | Deterministic bugs; performance regression |
| Performance | Query-count regression; wall-time degradation | Functional correctness of any kind |
| Mutation | Under-specified tests; lines that can be changed without a failure | Everything above (it validates test quality, not code quality) |

A correctness test suite that fully passes can still mask a query-count regression — only the performance suite catches that. A deterministic test suite that fully passes can still miss a rare state combination — only the fuzz suite catches that. The layers are designed to be non-overlapping in what they can miss.
