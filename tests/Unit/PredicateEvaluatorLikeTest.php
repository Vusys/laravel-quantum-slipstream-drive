<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\DriverSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\MariaDbSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\MySqlSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\PostgresSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\SqliteSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\StringComparisonMode;
use Vusys\QuantumSlipstreamDrive\Enums\EvaluationResult;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Predicate\LikeNode;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateEvaluator;

final class PredicateEvaluatorLikeTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function attributes(array $values): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;

        foreach ($values as $column => $value) {
            $knowledge->set($column, new AttributeFact(
                column: $column,
                originalValue: $value,
                currentValue: $value,
                isDirty: false,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return $knowledge;
    }

    private function sqliteEvaluator(): PredicateEvaluator
    {
        // SQLite LIKE is case-insensitive regardless of column metadata, so the
        // evaluator is deterministic without a model-backed column resolver.
        return new PredicateEvaluator(new SqliteSemantics);
    }

    // --- pattern translation (via SQLite, ASCII case-insensitive) ---

    #[Test]
    public function percent_matches_suffix(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new LikeNode('name', 'Al%', false);

        $this->assertSame(EvaluationResult::Match, $this->sqliteEvaluator()->evaluate($attrs, $node));
    }

    #[Test]
    public function percent_matches_contains(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);

        $this->assertSame(
            EvaluationResult::Match,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', '%lic%', false)),
        );
    }

    #[Test]
    public function underscore_matches_single_character(): void
    {
        $attrs = $this->attributes(['code' => 'A1']);

        $this->assertSame(
            EvaluationResult::Match,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('code', 'A_', false)),
        );
        $this->assertSame(
            EvaluationResult::Reject,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('code', 'A__', false)),
        );
    }

    #[Test]
    public function exact_pattern_without_wildcards_matches_whole_string(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);

        $this->assertSame(
            EvaluationResult::Match,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'Alice', false)),
        );
        $this->assertSame(
            EvaluationResult::Reject,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'Alic', false)),
        );
    }

    #[Test]
    public function regex_metacharacters_in_pattern_are_literal(): void
    {
        $attrs = $this->attributes(['path' => 'a.b']);

        // '.' is a literal dot, not the regex any-char, so 'axb' must not match.
        $this->assertSame(
            EvaluationResult::Match,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('path', 'a.b', false)),
        );
        $this->assertSame(
            EvaluationResult::Reject,
            $this->sqliteEvaluator()->evaluate($this->attributes(['path' => 'axb']), new LikeNode('path', 'a.b', false)),
        );
    }

    #[Test]
    public function not_like_negates_the_result(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);

        $this->assertSame(
            EvaluationResult::Reject,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'Al%', true)),
        );
        $this->assertSame(
            EvaluationResult::Match,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'Z%', true)),
        );
    }

    #[Test]
    public function missing_attribute_is_unknown(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);

        $this->assertSame(
            EvaluationResult::Unknown,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('missing', 'A%', false)),
        );
    }

    #[Test]
    public function null_attribute_is_unknown(): void
    {
        $attrs = $this->attributes(['name' => null]);

        $this->assertSame(
            EvaluationResult::Unknown,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'A%', false)),
        );
    }

    #[Test]
    public function non_ascii_operands_defer_to_sql(): void
    {
        $attrs = $this->attributes(['name' => 'Café']);

        $this->assertSame(
            EvaluationResult::Unknown,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'Caf%', false)),
        );
    }

    #[Test]
    public function backslash_in_pattern_defers_to_sql(): void
    {
        $attrs = $this->attributes(['name' => 'a_b']);

        // Backslash escape semantics diverge across drivers → Unknown.
        $this->assertSame(
            EvaluationResult::Unknown,
            $this->sqliteEvaluator()->evaluate($attrs, new LikeNode('name', 'a\\_b', false)),
        );
    }

    // --- per-driver case sensitivity ---

    #[Test]
    public function sqlite_like_is_case_insensitive(): void
    {
        $this->assertSame(
            EvaluationResult::Match,
            (new SqliteSemantics)->like('Foo', 'foo', ColumnSemantics::unknown()),
        );
    }

    #[Test]
    public function postgres_like_is_case_sensitive_by_default(): void
    {
        $pg = new PostgresSemantics;

        $this->assertSame(EvaluationResult::Reject, $pg->like('Foo', 'foo', ColumnSemantics::unknown()));
        $this->assertSame(EvaluationResult::Match, $pg->like('foo', 'foo', ColumnSemantics::unknown()));
    }

    #[Test]
    public function postgres_citext_column_folds_case(): void
    {
        $citext = new ColumnSemantics(stringComparison: StringComparisonMode::CaseInsensitive);

        $this->assertSame(
            EvaluationResult::Match,
            (new PostgresSemantics)->like('Foo', 'foo', $citext),
        );
    }

    #[Test]
    public function mysql_like_follows_collation(): void
    {
        $this->assertLikeAcrossCaseInsensitiveDriver(new MySqlSemantics);
    }

    #[Test]
    public function mariadb_like_follows_collation(): void
    {
        $this->assertLikeAcrossCaseInsensitiveDriver(new MariaDbSemantics);
    }

    private function assertLikeAcrossCaseInsensitiveDriver(DriverSemantics $driver): void
    {
        $ci = new ColumnSemantics(stringComparison: StringComparisonMode::CaseInsensitive);
        $cs = new ColumnSemantics(stringComparison: StringComparisonMode::CaseSensitive);

        $this->assertSame(EvaluationResult::Match, $driver->like('Foo', 'foo', $ci));
        $this->assertSame(EvaluationResult::Reject, $driver->like('Foo', 'foo', $cs));
        // Unresolved collation must defer rather than guess.
        $this->assertSame(EvaluationResult::Unknown, $driver->like('Foo', 'foo', ColumnSemantics::unknown()));
    }
}
