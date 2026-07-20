<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Enums;

enum LifecycleState: string
{
    case Exists = 'exists';
    case SoftDeleted = 'soft-deleted';
    case Deleted = 'deleted';
}
