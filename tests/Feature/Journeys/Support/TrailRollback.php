<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Support;

use RuntimeException;

/**
 * Sentinel thrown to force a transaction/savepoint rollback from inside a
 * DB::transaction() closure. A journey step catches exactly this type so the
 * rollback is intentional (the engine must restore its snapshot) without
 * swallowing a genuine failure raised by the code under test.
 */
final class TrailRollback extends RuntimeException {}
