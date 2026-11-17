<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Connection;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;

/**
 * Remembers the rows of a SELECT so an identical one, run again before anything could have
 * changed them, is answered without a round trip. Only invoked when `query_memo.enabled`.
 *
 * "Identical" is the connection object, the SQL and the bindings. "Before
 * anything could have changed them" is enforced by forgetting everything, across every
 * connection, whenever a connection runs anything that is not a memoisable SELECT, whenever a
 * transaction begins, commits or rolls back, at request and job boundaries, and after `ttl_ms`.
 * The TTL is what bounds a write by another process, which nothing in this process can see.
 *
 * A SELECT is memoisable only when its answer depends on nothing but the data: one that locks
 * rows, reads the clock, draws a random number or a sequence value, takes an advisory lock or
 * sleeps is run every time. Each hit hands back fresh copies of the rows, so a caller that
 * modifies what it got cannot change what the next caller gets.
 */
final class SelectMemo
{
    /** Clauses and keywords that make a SELECT's answer depend on more than the data. */
    private const string VOLATILE = '/\b(?:for\s+(?:no\s+key\s+)?update|for\s+(?:key\s+)?share|lock\s+in\s+share\s+mode|into|sql_calc_found_rows'
        .'|current_timestamp|current_date|current_time|localtime|localtimestamp)\b'
        .'|\b(?:nextval|currval|lastval|setval|last_insert_id|last_insert_rowid|found_rows|row_count|changes|total_changes'
        .'|get_lock|release_lock|release_all_locks|is_free_lock|is_used_lock|pg_\w*advisory\w*|pg_sleep\w*|sleep|benchmark'
        .'|rand|random|randomblob|random_bytes|uuid\w*|gen_random_uuid|newid|now|sysdate|curdate|curtime|utc_timestamp|utc_date|utc_time'
        .'|unix_timestamp|clock_timestamp|statement_timestamp|transaction_timestamp|timeofday|txid_current\w*|pg_current_xact_id'
        .'|connection_id|pg_backend_pid|set_config|setseed|pg_notify)\s*\('
        // SQLite reads the clock with no argument, or with a one-argument strftime().
        .'|\b(?:date|time|datetime|julianday|unixepoch)\s*\(\s*\)|\bstrftime\s*\(\s*\'[^\']*\'\s*\)'
        .'|[\'"]now[\'"]|:=/i';

    /** @var array<string, array{rows: array<array-key, mixed>, expires: int}> */
    private static array $entries = [];

    private static int $bypassing = 0;

    /** Bumped by every flush, so a read that raced one is not remembered. */
    private static int $generation = 0;

    /**
     * Runs $callback with every SELECT going to the database, as `withoutIdentityMap()` asks.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function bypassing(\Closure $callback): mixed
    {
        self::$bypassing++;

        try {
            return $callback();
        } finally {
            self::$bypassing--;
        }
    }

    /**
     * The memo key for a SELECT, or null when it must run every time.
     *
     * A read from the write PDO is one: Laravel's `refresh()` and `fresh()` read that way, and so
     * does anyone asking for the latest truth with `useWritePdo()`. So is one inside
     * `IdentityMap::disabled()` or a `withoutIdentityMap()` query.
     *
     * A binding that is a stream is one too: it serialises to nothing that tells two apart.
     *
     * @param  string  $identity  this connection object's, so a purged and rebuilt connection of
     *                            the same name, perhaps to another host, shares nothing
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $fetchUsing
     */
    public static function keyFor(Connection $connection, string $identity, string $query, array $bindings, bool $useReadPdo, array $fetchUsing): ?string
    {
        foreach ($bindings as $binding) {
            if (is_resource($binding) || gettype($binding) === 'resource (closed)') {
                return null;
            }
        }

        if (! $useReadPdo || self::$bypassing > 0 || $fetchUsing !== [] || $connection->pretending() || resolve(IdentityMapStore::class)->isDisabled() || ! preg_match('/^\s*\(?\s*select\b/i', $query) || preg_match(self::VOLATILE, $query)) {
            return null;
        }

        try {
            $bound = serialize($connection->prepareBindings($bindings));
        } catch (\Throwable) {
            return null;
        }

        if (in_array('now', array_map(static fn (mixed $b): mixed => is_string($b) ? strtolower(trim($b)) : $b, $bindings), true)) {
            return null;
        }

        return implode("\0", [$identity, $query, $bound]);
    }

    /**
     * Fresh copies of the remembered rows, or null on a miss.
     *
     * @return ?array<array-key, mixed>
     */
    public static function get(string $key): ?array
    {
        $entry = self::$entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if (hrtime(true) > $entry['expires']) {
            unset(self::$entries[$key]);

            return null;
        }

        return self::copies($entry['rows']);
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Remembers $rows unless the memo was flushed since $generation, when the read began: a
     * listener on the query, or anything else, may have written in between. A result holding a
     * stream (Postgres `bytea`) is not remembered, because a copy of the row would share it.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function remember(string $key, array $rows, int $generation): void
    {
        if ($generation !== self::$generation) {
            return;
        }

        foreach ($rows as $row) {
            foreach (is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : [$row]) as $value) {
                if (is_resource($value)) {
                    return;
                }
            }
        }

        $config = config('quantum-slipstream-drive.query_memo');
        $maxRows = is_array($config) && is_int($config['max_rows'] ?? null) ? $config['max_rows'] : 1000;
        $maxEntries = is_array($config) && is_int($config['max_entries'] ?? null) ? $config['max_entries'] : 500;
        $ttlMs = is_array($config) && is_int($config['ttl_ms'] ?? null) ? $config['ttl_ms'] : 1000;

        if (count($rows) > $maxRows || $maxEntries < 1 || $ttlMs < 1) {
            return;
        }

        // Oldest first: a key re-remembered moves to the end.
        unset(self::$entries[$key]);

        while (count(self::$entries) >= $maxEntries) {
            unset(self::$entries[array_key_first(self::$entries)]);
        }

        self::$entries[$key] = ['rows' => self::copies($rows), 'expires' => hrtime(true) + $ttlMs * 1_000_000];
    }

    public static function flush(): void
    {
        self::$generation++;
        self::$entries = [];
    }

    public static function size(): int
    {
        return count(self::$entries);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<array-key, mixed>
     */
    private static function copies(array $rows): array
    {
        return array_map(static fn (mixed $row): mixed => is_object($row) ? clone $row : $row, $rows);
    }
}
