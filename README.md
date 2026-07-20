# Laravel Quantum Slipstream Drive

[![Tests](https://github.com/Vusys/laravel-quantum-slipstream-drive/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-quantum-slipstream-drive/actions/workflows/tests.yml) [![codecov](https://codecov.io/gh/Vusys/laravel-quantum-slipstream-drive/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-quantum-slipstream-drive) [![tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-quantum-slipstream-drive/badges/tests.json)](https://github.com/Vusys/laravel-quantum-slipstream-drive/actions/workflows/tests.yml) [![assertions](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-quantum-slipstream-drive/badges/assertions.json)](https://github.com/Vusys/laravel-quantum-slipstream-drive/actions/workflows/tests.yml) [![test LOC](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-quantum-slipstream-drive/badges/test-ratio.json)](tests/) [![CI matrix](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-quantum-slipstream-drive/badges/matrix.json)](.github/workflows/tests.yml) [![Bencher](https://img.shields.io/badge/Bencher-tracked-FD6F1B?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0id2hpdGUiPjxwYXRoIGQ9Ik0xMiAyTDMgN3YxMGw5IDUgOS01VjdaIi8+PC9zdmc+)](https://bencher.dev/perf/vusys-laravel-quantum-slipstream-drive) [![Mutation testing](https://img.shields.io/endpoint?style=flat&url=https://badge-api.stryker-mutator.io/github.com/Vusys/laravel-quantum-slipstream-drive/master)](https://dashboard.stryker-mutator.io/reports/github.com/Vusys/laravel-quantum-slipstream-drive/master) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Vusys/laravel-quantum-slipstream-drive/badge)](https://scorecard.dev/viewer/?uri=github.com/Vusys/laravel-quantum-slipstream-drive) [![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json) [![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json) [![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon) [![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php) [![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint) [![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> Fold your Eloquent queries into a slipstream until the database wonders where everybody went.

A scoped Eloquent identity map, process-truth engine, and query-elision planner for Laravel. Add one trait and the same model loaded by a middleware, a controller, a policy, and a view composer is fetched **once** — every redundant `SELECT` for the life of the request or job is served from memory instead.

It goes beyond the classic single-key identity map: it rewrites key-set queries so only unknown IDs hit the database, evaluates `WHERE` predicates against cached attributes, serves `where('email', …)->first()` lookups from a unique-key index, and tracks whole query regions so a broad `->get()` primes narrower follow-ups. Nothing is serialized or shared between processes; when the scope ends, the map is discarded.

**📚 Full documentation: [vusys.github.io/laravel-quantum-slipstream-drive](https://vusys.github.io/laravel-quantum-slipstream-drive/)**

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require vusys/laravel-quantum-slipstream-drive
```

## Quick start

Add the `HasIdentityMap` trait to any Eloquent model — that is the entire setup:

```php
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

final class User extends Model
{
    use HasIdentityMap;
}
```

Now redundant reads within a request or job are elided automatically:

```php
$a = User::find(1);
$b = User::find(1);

$a === $b; // true — the second call issues no SQL

User::find([1, 2, 3, 4]);
// Already in map: 1, 2. Confirmed absent: 3.
// SQL: SELECT * FROM users WHERE id IN (4)
```

The [Getting started guide](docs/getting-started.md) covers opting out per query, manual flushing, and disabling for a scope.

## Documentation

Full docs live at **[vusys.github.io/laravel-quantum-slipstream-drive](https://vusys.github.io/laravel-quantum-slipstream-drive/)**. By topic:

| Topic | Page |
|---|---|
| The problem, what it is / is not, when it helps | [Home](docs/index.md) |
| Installing and requirements | [Installation](docs/installation.md) |
| Opt-in trait, opt-out, flush, disable | [Getting started](docs/getting-started.md) |
| Unique-key lookups, `explain()`, predicates, relations | [Usage](docs/usage.md) |
| Every config key and env override | [Configuration](docs/configuration.md) |
| How the engine works internally | [Architecture & internals](docs/architecture.md) |
| `explain()`, the `QueryDecided` event, the decision log | [Observability](docs/observability.md) |
| The six test layers and the CI matrix | [Testing](docs/testing.md) |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the four quality gates and code conventions, and [CHANGELOG.md](CHANGELOG.md) for release history.

## License

MIT. See [LICENSE](LICENSE).
