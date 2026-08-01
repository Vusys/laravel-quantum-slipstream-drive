<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query\Connections;

use Illuminate\Database\SQLiteConnection;
use Vusys\QuantumSlipstreamDrive\Query\ServesRawReads;

final class RawServingSqliteConnection extends SQLiteConnection
{
    use ServesRawReads;
}
