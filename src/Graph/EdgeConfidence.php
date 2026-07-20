<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

enum EdgeConfidence: string
{
    case Certain = 'certain';
    case Inferred = 'inferred';
}
