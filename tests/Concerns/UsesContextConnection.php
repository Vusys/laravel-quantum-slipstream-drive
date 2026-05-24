<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Tests\Fuzz\Support\ConnectionContext;

/**
 * @phpstan-require-extends Model
 */
trait UsesContextConnection
{
    public function initializeUsesContextConnection(): void
    {
        $connection = ConnectionContext::active();
        if ($connection !== null) {
            $this->setConnection($connection);
        }
    }
}
