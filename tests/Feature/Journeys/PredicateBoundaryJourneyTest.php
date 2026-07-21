<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class PredicateBoundaryJourneyTest extends JourneyTestCase
{
    #[Test]
    public function covered_predicate_regions_track_rows_moving_across_their_boundaries(): void
    {
        User::create(['name' => 'apple', 'email' => 'apple@example.com', 'active' => true, 'score' => 40, 'bio' => null]);
        User::create(['name' => 'banana', 'email' => 'banana@example.com', 'active' => false, 'score' => 60, 'bio' => 'ripe']);
        User::create(['name' => 'avocado', 'email' => 'avocado@example.com', 'active' => true, 'score' => 50, 'bio' => null]);
        User::create(['name' => 'cherry', 'email' => 'cherry@example.com', 'active' => false, 'score' => 85, 'bio' => 'red']);
        User::create(['name' => 'date', 'email' => 'date@example.com', 'active' => true, 'score' => null, 'bio' => null]);

        $this->journey(PredicateBoundaryJourney::class)->shuffles(25)->run();
    }
}
