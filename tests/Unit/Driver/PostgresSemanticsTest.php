<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnType;
use Vusys\QuantumSlipstreamDrive\Driver\PostgresSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\StringComparisonMode;
use Vusys\QuantumSlipstreamDrive\Enums\EvaluationResult;

final class PostgresSemanticsTest extends TestCase
{
    #[Test]
    public function strings_are_case_sensitive_by_default(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('Bryan', '=', 'Bryan', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('BRYAN', '=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function citext_column_folds_case(): void
    {
        $s = new PostgresSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertSame(EvaluationResult::Match, $s->compare('BRYAN', '=', 'bryan', $col));
    }

    #[Test]
    public function bool_to_bool(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(true, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(true, '=', false, ColumnSemantics::unknown()));
    }

    #[Test]
    public function int_to_int_ordering(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(5, '>=', 5, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(4, '>', 5, ColumnSemantics::unknown()));
    }

    #[Test]
    public function string_ordering_defers_because_postgres_collation_is_locale_dependent(): void
    {
        // Postgres's default (locale) collation does not order strings by byte
        // value — under en_US.UTF-8, 'alice' sorts before 'Alice', the opposite
        // of strcmp(). Since we cannot reproduce the server's collation in PHP,
        // string ordering must defer to SQL rather than guess a byte order.
        $s = new PostgresSemantics;
        self::assertNull($s->compareForOrder('alice', 'bob', ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder('bob', 'alice', ColumnSemantics::unknown()));
    }

    #[Test]
    public function citext_ordering_defers_too(): void
    {
        // citext ordering is likewise the underlying locale collation, case-folded
        // — not reproducible as a strcasecmp(), so it must also defer.
        $s = new PostgresSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertNull($s->compareForOrder('alice', 'BOB', $col));
    }

    #[Test]
    public function relational_string_predicates_defer_to_sql(): void
    {
        // The bug this guards: a WHERE name < 'Alice' must not be answered from
        // memory with PHP byte order, because Postgres would include locale-ordered
        // rows (e.g. 'alice') that strcmp() excludes.
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('alice', '<', 'Alice', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare('Bob', '>', 'Alice', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare('alice', '<=', 'bob', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare('bob', '>=', 'alice', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_sensitive_strings_match_only_byte_identical(): void
    {
        $s = new PostgresSemantics;
        // Direct evidence that byte-identical resolves Match; byte-different
        // resolves Reject (kills the `$left === $right` → `!==` mutation).
        self::assertSame(EvaluationResult::Match, $s->compare('bryan', '=', 'bryan', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('bryan', '=', 'BRYAN', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('bryan', '=', 'alice', ColumnSemantics::unknown()));
    }
}
