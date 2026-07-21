<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Driver;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\ProvidesCartesian;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Differential oracle for driver semantics (#91).
 *
 * Every case runs the same predicate twice — once through the in-memory engine
 * (coverage warmed) and once with the identity map disabled (raw SQL) — and
 * asserts the two agree. Because CI runs every DB cell, a single green run here
 * proves the engine's NULL handling, string collation, and LIKE folding match
 * the live backend for sqlite / mysql / mariadb / pgsql.
 */
final class DriverSemanticsCrossCheckTest extends TestCase
{
    use ProvidesCartesian;

    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function seedUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 50]);
        User::create(['name' => 'alice', 'email' => 'alice2@example.com', 'active' => true, 'score' => 10]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true, 'score' => 90]);
        User::create(['name' => 'Albert', 'email' => 'albert@example.com', 'active' => true, 'score' => null]);
        User::create(['name' => 'ROBERT', 'email' => 'robert@example.com', 'active' => true, 'score' => null]);

        // Warm full coverage so the engine can serve WHERE predicates from memory.
        User::query()->get();
    }

    /**
     * @return list<int>
     */
    private function engineIds(callable $query): array
    {
        /** @var list<int> $ids */
        $ids = $query()->pluck('id')->map(static fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->sort()->values()->all();

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function oracleIds(callable $query): array
    {
        /** @var list<int> $ids */
        $ids = IdentityMap::disabled(
            fn () => $query()->pluck('id')->map(static fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->sort()->values()->all()
        );

        return $ids;
    }

    /**
     * @return array<string, array{string, string, int|string}>
     */
    public static function comparisonPredicates(): array
    {
        return [
            'string equality byte-identical' => ['name', '=', 'Alice'],
            'string equality lowercase variant' => ['name', '=', 'alice'],
            'string equality uppercase variant' => ['name', '=', 'ROBERT'],
            'string inequality' => ['name', '!=', 'Alice'],
            'numeric gt over nullable column' => ['score', '>', 50],
            'numeric gte over nullable column' => ['score', '>=', 50],
            'numeric lt over nullable column' => ['score', '<', 90],
            'numeric equality over nullable column' => ['score', '=', 50],
        ];
    }

    #[Test]
    #[DataProvider('comparisonPredicates')]
    public function comparison_predicate_matches_the_live_backend(string $column, string $operator, int|string $value): void
    {
        $this->seedUsers();

        $query = fn () => User::where($column, $operator, $value)->get();

        $this->assertSame(
            $this->oracleIds($query),
            $this->engineIds($query),
            "in-memory {$column} {$operator} {$value} must match the backend result set",
        );
    }

    /**
     * The Cartesian product of case-variant literals and the collation-sensitive
     * operators. Each cell is one predicate whose truth depends on how the active
     * driver folds case and orders strings.
     *
     * @return array<string, array{string, string}>
     */
    public static function collationMatrix(): array
    {
        $values = ['Alice', 'alice', 'ALICE', 'Bob', 'bob', 'ROBERT', 'robert'];
        $operators = ['=', '!=', '<', '>'];

        $cases = [];
        foreach (self::cartesian($values, $operators) as [$value, $operator]) {
            if (! is_string($value)) {
                continue;
            }
            if (! is_string($operator)) {
                continue;
            }
            $cases["name {$operator} '{$value}'"] = [$operator, $value];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('collationMatrix')]
    public function collation_comparison_matches_the_live_backend(string $operator, string $value): void
    {
        $this->seedUsers();

        $query = fn () => User::where('name', $operator, $value)->get();

        $this->assertSame(
            $this->oracleIds($query),
            $this->engineIds($query),
            "in-memory name {$operator} '{$value}' must match the backend collation",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function likePatterns(): array
    {
        return [
            'prefix' => ['like', 'Al%'],
            'suffix' => ['like', '%rt'],
            'contains' => ['like', '%o%'],
            'underscore single char' => ['like', '_ob'],
            'lowercase prefix vs mixed data' => ['like', 'al%'],
            'uppercase prefix vs mixed data' => ['like', 'RO%'],
            'negated prefix' => ['not like', 'Al%'],
            'negated contains' => ['not like', '%o%'],
        ];
    }

    #[Test]
    #[DataProvider('likePatterns')]
    public function like_predicate_matches_the_live_backend(string $operator, string $pattern): void
    {
        $this->seedUsers();

        $query = fn () => User::where('name', $operator, $pattern)->get();

        $this->assertSame(
            $this->oracleIds($query),
            $this->engineIds($query),
            "in-memory name {$operator} '{$pattern}' must match the backend collation",
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function nullChecks(): array
    {
        return [
            'where null' => [true],
            'where not null' => [false],
        ];
    }

    #[Test]
    #[DataProvider('nullChecks')]
    public function null_check_matches_the_live_backend(bool $wantNull): void
    {
        $this->seedUsers();

        $query = fn () => $wantNull
            ? User::whereNull('score')->get()
            : User::whereNotNull('score')->get();

        $this->assertSame(
            $this->oracleIds($query),
            $this->engineIds($query),
            'in-memory NULL check must match the backend three-valued logic',
        );
    }

    /**
     * ORDER BY over a nullable column is where drivers diverge most (SQLite/MySQL
     * sort NULLs first ascending, Postgres sorts them last). The engine must never
     * hand back an order that disagrees with the live backend for any direction.
     *
     * @return array<string, array{'asc'|'desc'}>
     */
    public static function orderDirections(): array
    {
        return [
            'ascending' => ['asc'],
            'descending' => ['desc'],
        ];
    }

    #[Test]
    #[DataProvider('orderDirections')]
    public function order_by_nullable_column_matches_the_live_backend(string $direction): void
    {
        $this->seedUsers();

        $dir = $direction === 'desc' ? 'desc' : 'asc';

        // Ordered result: keep the DB's exact order (do not re-sort in PHP), so a
        // wrong NULL placement would surface as a mismatch against the oracle.
        $ordered = fn (): array => User::orderBy('score', $dir)->orderBy('id')->get()
            ->pluck('id')->map(static fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->values()->all();

        $engine = $ordered();
        $oracle = IdentityMap::disabled($ordered);

        $this->assertSame(
            $oracle,
            $engine,
            "engine ORDER BY score {$direction} must place NULLs exactly where ".DB::connection()->getDriverName().' does',
        );
    }
}
