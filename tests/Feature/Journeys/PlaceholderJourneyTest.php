<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;

final class PlaceholderJourneyTest extends JourneyTestCase
{
    #[Test]
    public function placeholder_journey_is_green_across_the_default_shuffle_count(): void
    {
        $this->journey(PlaceholderJourney::class)->shuffles(10)->run();
    }
}
