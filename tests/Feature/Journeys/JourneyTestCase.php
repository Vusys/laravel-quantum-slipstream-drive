<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Closure;
use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\TransactionJournal;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Base case for Runabout journey tests (#95).
 *
 * Each trail runs inside a rolled-back transaction (Runabout's default) with the
 * engine's in-memory state flushed on both sides of it, so a trail never inherits
 * cached rows, coverage, or graph edges from the trail before it. What the engine
 * caches *within* a trail is exactly what the journeys are here to stress.
 */
abstract class JourneyTestCase extends TestCase
{
    use RunsJourneys;

    protected function wrapTrail(Closure $trail): void
    {
        $this->flushEngine();

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $trail();
        } finally {
            $connection->rollBack();
            $this->flushEngine();
        }
    }

    private function flushEngine(): void
    {
        IdentityMap::flush();
        resolve(CoverageRegistry::class)->flush();
        resolve(IdentityGraph::class)->flush();
        resolve(TransactionJournal::class)->flush();
    }
}
