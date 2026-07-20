# Installation

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Install

```bash
composer require vusys/laravel-quantum-slipstream-drive
```

The package registers its service provider automatically via Laravel package discovery. There is nothing to wire up by hand — once installed, any model that uses the [`HasIdentityMap`](getting-started.md) trait is served through the identity map.

## Publish the config

Publishing the config file is optional. The package ships with safe defaults for every setting, and all of them can be overridden with environment variables without publishing anything (see [Configuration](configuration.md)). Publish the file when you want to declare per-model unique keys or keep the settings under version control:

```bash
php artisan vendor:publish --tag=quantum-slipstream-drive-config
```

This writes `config/quantum-slipstream-drive.php`. See the [Configuration reference](configuration.md) for every key it contains.

## Next steps

- [Getting started](getting-started.md) — opt a model in, opt out per query, flush manually.
- [Usage](usage.md) — unique-key lookups, `explain()`, supported predicates, relation optimizations.
