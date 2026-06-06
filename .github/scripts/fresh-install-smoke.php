<?php

declare(strict_types=1);

/*
 * Fresh-install smoke test.
 *
 * Designed to run from the root of a freshly-scaffolded Laravel application
 * that has just `composer require`d this package via a path repository.
 * Verifies:
 *
 *   1. The service provider auto-discovers and boots.
 *   2. A model using HasIdentityMap can be created and persisted.
 *   3. A second find() of the same primary key skips SQL (the whole point
 *      of the package).
 *
 * Exits 0 on success, 1 on any assertion failure. Output goes to stdout/stderr
 * for CI consumption.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\QueryRicerExtreme\HasIdentityMap;

if (! trait_exists(HasIdentityMap::class)) {
    fwrite(STDERR, "FAIL: HasIdentityMap trait is not autoloadable — service provider or composer wiring is broken.\n");
    exit(1);
}

class SmokeModel extends Model
{
    use HasIdentityMap;

    protected $table = 'smoke_models';

    protected $guarded = [];

    public $timestamps = false;
}

Schema::create('smoke_models', static function ($table): void {
    $table->id();
    $table->string('name');
});

SmokeModel::create(['name' => 'Alice']);

$queries = 0;
DB::listen(static function () use (&$queries): void {
    $queries++;
});

$first = SmokeModel::find(1);
$queriesAfterFirst = $queries;
$second = SmokeModel::find(1);

if ($first === null || $second === null) {
    fwrite(STDERR, "FAIL: find() returned null after create().\n");
    exit(1);
}

if ($first !== $second) {
    fwrite(STDERR, "FAIL: Identity map did not return the same instance on second find().\n");
    exit(1);
}

if ($queries !== $queriesAfterFirst) {
    fwrite(STDERR, "FAIL: Second find() issued SQL (queries before second find={$queriesAfterFirst}, after={$queries}). Identity map is not intercepting.\n");
    exit(1);
}

echo "OK: package boots, second find() served from memory with zero config.\n";
