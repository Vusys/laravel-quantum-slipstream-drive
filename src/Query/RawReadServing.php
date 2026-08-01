<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Vusys\QuantumSlipstreamDrive\Query\Connections\RawServingMariaDbConnection;
use Vusys\QuantumSlipstreamDrive\Query\Connections\RawServingMySqlConnection;
use Vusys\QuantumSlipstreamDrive\Query\Connections\RawServingPostgresConnection;
use Vusys\QuantumSlipstreamDrive\Query\Connections\RawServingSqliteConnection;

/**
 * Installs per-driver connection resolvers so `DB::table()` reads are built on a
 * {@see RawServingQueryBuilder}. Only invoked when `raw_reads.enabled`.
 *
 * The resolver reproduces {@see ConnectionFactory}'s
 * own construction, swapping only the connection subclass; everything else about
 * the connection is unchanged. The swapped builder self-gates on the config flag,
 * so a connection built by these resolvers behaves exactly as stock whenever the
 * feature is off.
 */
final class RawReadServing
{
    /** @var array<string, class-string<Connection>> driver => raw-serving connection subclass */
    private const array CONNECTIONS = [
        'sqlite' => RawServingSqliteConnection::class,
        'mysql' => RawServingMySqlConnection::class,
        'mariadb' => RawServingMariaDbConnection::class,
        'pgsql' => RawServingPostgresConnection::class,
    ];

    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        foreach (self::CONNECTIONS as $driver => $connectionClass) {
            // Respect a resolver another package already registered for this
            // driver — overwriting it could break their connection wiring.
            if (Connection::getResolver($driver) !== null) {
                continue;
            }

            Connection::resolverFor($driver, static fn ($connection, $database, $prefix, array $config): Connection => new $connectionClass(
                $connection,
                is_string($database) ? $database : '',
                is_string($prefix) ? $prefix : '',
                $config,
            ));
        }
    }
}
