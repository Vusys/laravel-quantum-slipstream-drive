<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class SoftDeleteScopeJourneyTest extends JourneyTestCase
{
    #[Test]
    public function scoped_reads_match_ground_truth_through_shuffled_soft_deletes(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);
        User::create(['name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false])->delete();

        $this->journey(SoftDeleteScopeJourney::class)->shuffles(30)->run();
    }
}
