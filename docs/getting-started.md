# Getting started

The identity map is opt-in per model. Add one trait and every redundant query on that model, for the life of the current request or job, is served from memory.

## Opt in per model

Add the `HasIdentityMap` trait to any Eloquent model:

```php
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

final class User extends Model
{
    use HasIdentityMap;
}
```

That is the entire setup. The trait overrides the model's Eloquent builder and relation objects, and registers the model-event listeners that keep the map in step with saves, deletes, and restores. Models without the trait are never stored in or served from the map, and queries on them are entirely unaffected.

## Per-query opt-out

To force a single query to bypass the map and hit the database, chain `withoutIdentityMap()`:

```php
User::query()->withoutIdentityMap()->find(1); // always hits the database
```

## Manual flush

Entries are flushed automatically at scope boundaries (see [Lifecycle hooks](architecture.md#lifecycle-hooks-and-automatic-flushing)), but you can flush by hand at any time:

```php
use Vusys\QuantumSlipstreamDrive\IdentityMap;

IdentityMap::flush();               // all entries
IdentityMap::flush(User::class);    // one model class
IdentityMap::forget($user);         // one instance
```

## Disable for a scope

To run a block of code with the map turned off — every query inside hits the database — wrap it in `IdentityMap::disabled()`:

```php
IdentityMap::disabled(function () {
    return User::find(1); // always hits DB
});
```

This is also the mechanism the package's own [oracle fuzz tests](testing.md#fuzz-tests) use to compare the identity-map path against a bypassed baseline.

## Next steps

- [Usage](usage.md) — unique-key lookups, `explain()`, the supported in-memory predicates, and relation optimizations.
- [Configuration](configuration.md) — declare unique keys, pick a truth mode, tune caps.
