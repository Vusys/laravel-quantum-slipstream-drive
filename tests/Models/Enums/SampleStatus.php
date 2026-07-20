<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models\Enums;

enum SampleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
