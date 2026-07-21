<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class OrderingJourneyTest extends JourneyTestCase
{
    #[Test]
    public function ordered_reads_keep_identical_order_to_a_bypassed_read_through_edits(): void
    {
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'active' => true, 'score' => 40]);
        User::create(['name' => 'alice', 'email' => 'alice@example.com', 'active' => false, 'score' => null]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true, 'score' => 80]);
        User::create(['name' => 'ábc', 'email' => 'abc@example.com', 'active' => false, 'score' => 20]);
        User::create(['name' => 'Zebra', 'email' => 'zebra@example.com', 'active' => true, 'score' => null]);

        $this->journey(OrderingJourney::class)->shuffles(30)->run();
    }
}
