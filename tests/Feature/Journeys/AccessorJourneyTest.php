<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Gadget;

final class AccessorJourneyTest extends JourneyTestCase
{
    #[Test]
    public function accessor_shadowed_column_reads_and_predicates_track_ground_truth(): void
    {
        Gadget::create(['code' => 'g1', 'qty' => 3]);
        Gadget::create(['code' => 'g2', 'qty' => 7]);
        Gadget::create(['code' => 'widget', 'qty' => 10]);
        Gadget::create(['code' => 'sprocket', 'qty' => 0]);

        $this->journey(AccessorJourney::class)->shuffles(30)->run();
    }
}
