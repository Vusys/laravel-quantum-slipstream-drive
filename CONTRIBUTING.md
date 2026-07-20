# Contributing

Thanks for your interest in improving `vusys/laravel-quantum-slipstream-drive`. This is a library-only package targeting **PHP 8.3+** and **Laravel 11 / 12 / 13**. It is pre-1.0, so backwards-compatibility breaks are acceptable when they are called out.

## The four quality gates

Every change must pass all four checks **locally before you push**. CI runs the identical commands, and a failure there is just noise that could have been caught here.

```bash
composer test          # PHPUnit — Unit + Feature + DataProviders (excludes Performance + fuzzer)
composer analyse       # PHPStan / Larastan level 9 — no baseline
composer pint:check    # Laravel Pint style check (run `composer pint` to auto-fix)
composer rector:check  # Rector dry-run (run `composer rector` to auto-fix)
```

If any check fails, fix it before pushing. Do not bypass or skip a gate.

## Code conventions

- **`declare(strict_types=1);`** at the top of every PHP file.
- **PHPStan level 9, no baseline, no `@phpstan-ignore`.** If the analyser complains, fix the type — do not silence it with an ignore comment or a baseline entry. Both are explicitly disallowed.
- **No code comments explaining *what*.** Add a comment only when the *why* is non-obvious.
- **Laravel Pint** enforces style. Run `composer pint` to auto-format, then re-run `composer pint:check`.

## Tests

PHPUnit 11–13 with Orchestra Testbench. Put new tests in the right layer:

- `tests/Unit/` — pure-PHP unit tests. Extend `PHPUnit\Framework\TestCase` directly; do **not** boot Laravel.
- `tests/Feature/` — DB-backed integration tests. Extend `Vusys\QuantumSlipstreamDrive\Tests\TestCase`.
- `tests/Feature/DataProviders/` — Cartesian / data-provider tests over the configuration space.
- `tests/Fuzz/` — seeded fuzzers (run with `composer fuzz`; group `fuzzer`).
- `tests/Performance/` — benchmarks (`vendor/bin/phpunit --testsuite Performance`; not run by `composer test`).

Run a single test while iterating:

```bash
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit tests/Feature/IdentityMapTest.php
```

The [Testing guide](docs/testing.md) explains what each layer catches and how to run it against every database backend.

## Database backends

The suite runs against SQLite (default), MySQL, MariaDB, and PostgreSQL. Set `DB_CONNECTION` to target one:

```bash
DB_CONNECTION=pgsql composer test
```

CI runs the full matrix — PHP 8.3–8.5 × Laravel 11–13 × 4 databases (36 cells). If your change touches driver-specific behaviour (string comparison semantics, schema discovery, transaction journalling), verify it on more than SQLite before opening a PR.

## Documentation

User-facing docs live in `docs/` and are published with MkDocs Material. If your change alters public behaviour or config, update the relevant page and add a `## [Unreleased]` entry to [CHANGELOG.md](CHANGELOG.md) following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Pull requests

- Keep PRs focused; land each workstream as its own PR.
- Make sure the four gates pass and CHANGELOG/docs are updated where relevant.
- Call out any intentional backwards-compat break in the PR description.
