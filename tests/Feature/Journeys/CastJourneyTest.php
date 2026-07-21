<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\CastSample;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Enums\SampleStatus;

final class CastJourneyTest extends JourneyTestCase
{
    #[\Override]
    protected function defineEnvironment($app): void
    {
        // Deterministic key so the `encrypted` cast has a working cipher.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    #[Test]
    public function cast_attributes_survive_the_cast_boundary_through_interleaved_writes(): void
    {
        CastSample::create([
            'name' => 'a',
            'happened_at' => Date::parse('2026-01-01 10:00:00'),
            'archived_at' => Date::parse('2026-02-01 08:00:00'),
            'payload' => ['tier' => 'gold', 'n' => 1],
            'status' => SampleStatus::Draft,
            'amount' => '10.00',
            'secret' => 'alpha-secret',
        ]);
        CastSample::create([
            'name' => 'b',
            'happened_at' => Date::parse('2026-03-15 12:30:00'),
            'archived_at' => null,
            'payload' => ['tier' => 'silver', 'n' => 2],
            'status' => SampleStatus::Published,
            'amount' => '19.99',
            'secret' => 'beta-secret',
        ]);
        CastSample::create([
            'name' => 'c',
            'happened_at' => Date::parse('2026-06-30 23:59:59'),
            'archived_at' => Date::parse('2026-07-01 00:00:00'),
            'payload' => ['tier' => 'gold', 'n' => 3],
            'status' => SampleStatus::Published,
            'amount' => '100.50',
            'secret' => 'gamma-secret',
        ]);

        $this->journey(CastJourney::class)->shuffles(30)->run();
    }
}
