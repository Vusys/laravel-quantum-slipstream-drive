<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\QuantumSlipstreamDriveServiceProvider;

/**
 * Booting must not open a database connection: `artisan package:discover`,
 * run by every `composer install`, boots the providers wherever the app is
 * installed — including CI and image builds, where no database exists yet.
 */
final class BootWithoutDatabaseTest extends OrchestraTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [QuantumSlipstreamDriveServiceProvider::class];
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'missing');
        $app['config']->set('database.connections.missing', [
            'driver' => 'sqlite',
            'database' => '/nonexistent/database.sqlite',
        ]);
    }

    #[Test]
    public function booting_resolves_no_connection(): void
    {
        $this->assertSame([], DB::getConnections());
    }

    #[Test]
    public function raw_writes_are_still_watched(): void
    {
        $this->assertTrue(Event::hasListeners(QueryExecuted::class));
    }
}
