<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class CoverageInvalidationJourneyTest extends JourneyTestCase
{
    #[Test]
    public function covered_regions_are_never_served_stale_under_shuffled_writes(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true, 'score' => 70]);
        User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false, 'score' => 30]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true, 'score' => 55]);
        User::create(['name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false, 'score' => 45]);

        $this->journey(CoverageInvalidationJourney::class)->shuffles(30)->run();
    }
}
