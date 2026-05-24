<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

enum EdgeConfidence: string
{
    case Certain = 'certain';
    case Inferred = 'inferred';
}
