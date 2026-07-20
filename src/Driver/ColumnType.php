<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Driver;

enum ColumnType
{
    case Integer;
    case Float;
    case Boolean;
    case String;
    case Uuid;
    case Date;
    case DateTime;
    case Json;
    case Binary;
    case Unknown;
}
