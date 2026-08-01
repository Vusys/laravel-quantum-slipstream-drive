<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query\Connections;

use Illuminate\Database\MySqlConnection;
use Vusys\QuantumSlipstreamDrive\Query\ServesRawReads;

final class RawServingMySqlConnection extends MySqlConnection
{
    use ServesRawReads;
}
