<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Driver;

/**
 * PostgreSQL uses case-sensitive equality by default: byte-equality maps to
 * database equality (its default collations are deterministic) unless the column
 * is a citext extension type, surfaced via
 * ColumnSemantics::stringComparison = CaseInsensitive.
 *
 * String *ordering* is a different matter: it follows the database's locale
 * collation (e.g. en_US.UTF-8), which does not match PHP byte order — under that
 * collation 'alice' sorts before 'Alice', the opposite of strcmp(). Because the
 * server's collation cannot be reproduced faithfully in PHP, relational string
 * comparisons (<, <=, >, >=) defer to SQL rather than risk a wrong answer.
 *
 * Postgres orders NULLs LAST for ASC and FIRST for DESC by default.
 */
final class PostgresSemantics extends AbstractDriverSemantics
{
    #[\Override]
    protected function likeCaseSensitivity(ColumnSemantics $column): bool
    {
        // Postgres LIKE is case-sensitive by default regardless of column
        // metadata; only a citext column (surfaced as CaseInsensitive) folds case.
        return $column->stringComparison !== StringComparisonMode::CaseInsensitive;
    }

    #[\Override]
    protected function compareStrings(string $left, string $right, ColumnSemantics $column): bool
    {
        if ($column->stringComparison === StringComparisonMode::CaseInsensitive) {
            return strcasecmp($left, $right) === 0;
        }

        return $left === $right;
    }

    #[\Override]
    protected function orderStrings(string $left, string $right, ColumnSemantics $column): ?int
    {
        // Ordering follows the server's locale collation, which PHP cannot
        // reproduce — defer to SQL for every relational string comparison.
        return null;
    }
}
