<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class PartialBackfillJourneyTest extends JourneyTestCase
{
    #[Test]
    public function partial_entries_backfill_without_serving_stale_columns(): void
    {
        config(['quantum-slipstream-drive.partial_models' => 'backfill_missing_columns']);

        $this->seedUsers();

        $this->journey(PartialBackfillJourney::class)->shuffles(30)->run();
    }

    #[Test]
    public function partial_entries_refetch_without_serving_stale_columns_in_query_normally_mode(): void
    {
        config(['quantum-slipstream-drive.partial_models' => 'query_normally']);

        $this->seedUsers();

        $this->journey(PartialBackfillJourney::class)->shuffles(30)->run();
    }

    private function seedUsers(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true, 'score' => 40, 'bio' => 'first']);
        User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false, 'score' => 60, 'bio' => null]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true, 'score' => 80, 'bio' => 'third']);
        User::create(['name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false, 'score' => 20, 'bio' => null]);
    }
}
