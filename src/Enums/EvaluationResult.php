<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Enums;

enum EvaluationResult
{
    case Match;
    case Reject;
    case Unknown;
}
