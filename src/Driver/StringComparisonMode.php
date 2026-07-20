<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Driver;

enum StringComparisonMode
{
    case CaseSensitive;
    case CaseInsensitive;
    case Unknown;
}
