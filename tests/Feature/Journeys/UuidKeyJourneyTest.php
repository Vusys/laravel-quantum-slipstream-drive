<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\UuidUser;

final class UuidKeyJourneyTest extends JourneyTestCase
{
    #[Test]
    public function identity_map_keys_string_primary_keys_consistently(): void
    {
        UuidUser::create(['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        UuidUser::create(['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);
        UuidUser::create(['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);
        UuidUser::create(['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false]);

        $this->journey(UuidKeyJourney::class)->shuffles(30)->run();
    }
}
