<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class NestedTransactionJourneyTest extends JourneyTestCase
{
    #[Test]
    public function identity_map_survives_nested_savepoint_commits_and_rollbacks(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true, 'score' => 40]);
        User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false, 'score' => 60]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true, 'score' => 80]);
        User::create(['name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false, 'score' => 20]);

        $this->journey(NestedTransactionJourney::class)->shuffles(30)->run();
    }
}
