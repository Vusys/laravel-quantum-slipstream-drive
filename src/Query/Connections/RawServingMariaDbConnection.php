<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query\Connections;

use Illuminate\Database\MariaDbConnection;
use Vusys\QuantumSlipstreamDrive\Query\ServesRawReads;

final class RawServingMariaDbConnection extends MariaDbConnection
{
    use ServesRawReads;
}
