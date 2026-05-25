<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ConservativeSemantics;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class ConservativeSemanticsTest extends TestCase
{
    #[Test]
    public function equal_strings_match(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('active', '=', 'active', ColumnSemantics::unknown()));
    }

    #[Test]
    public function different_strings_reject_using_loose_eq_shim(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Reject, $s->compare('disabled', '=', 'active', ColumnSemantics::unknown()));
    }

    #[Test]
    public function int_one_matches_bool_true(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(1, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function int_zero_rejects_bool_true(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Reject, $s->compare(0, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function ordered_int_comparison_resolves(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(5, '>', 3, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(3, '>', 5, ColumnSemantics::unknown()));
    }

    #[Test]
    public function ordered_string_comparison_unknown(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('a', '>', 'b', ColumnSemantics::unknown()));
    }

    #[Test]
    public function compare_for_order_numeric_resolves_string_returns_null(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(-1, $s->compareForOrder(1, 2, ColumnSemantics::unknown()));
        self::assertSame(0, $s->compareForOrder(2, 2, ColumnSemantics::unknown()));
        self::assertSame(1, $s->compareForOrder(2, 1, ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder('a', 'b', ColumnSemantics::unknown()));
    }
}
