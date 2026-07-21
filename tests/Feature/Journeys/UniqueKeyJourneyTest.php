<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class UniqueKeyJourneyTest extends JourneyTestCase
{
    #[Test]
    public function unique_key_resolution_tracks_ground_truth_through_edits(): void
    {
        config(['quantum-slipstream-drive.models' => [
            User::class => [
                'unique' => [
                    ['email'],
                    ['name'],
                ],
            ],
        ]]);

        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);
        User::create(['name' => 'Dijkstra', 'email' => 'dijkstra@example.com', 'active' => false]);

        $this->journey(UniqueKeyJourney::class)->shuffles(35)->run();
    }
}
