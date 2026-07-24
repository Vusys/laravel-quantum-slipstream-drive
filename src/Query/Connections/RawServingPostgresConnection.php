<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query\Connections;

use Illuminate\Database\PostgresConnection;
use Vusys\QuantumSlipstreamDrive\Query\ServesRawReads;

final class RawServingPostgresConnection extends PostgresConnection
{
    use ServesRawReads;
}
