<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Fuzz\Support;

use Closure;

final class ConnectionContext
{
    private static ?string $active = null;

    public static function active(): ?string
    {
        return self::$active;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function using(string $connection, Closure $fn): mixed
    {
        $previous = self::$active;
        self::$active = $connection;
        try {
            return $fn();
        } finally {
            self::$active = $previous;
        }
    }
}
