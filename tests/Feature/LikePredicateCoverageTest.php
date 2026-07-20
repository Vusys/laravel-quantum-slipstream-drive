<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class LikePredicateCoverageTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->store->flush();
        $this->registry->flush();
    }

    private function seedUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Albert', 'email' => 'albert@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        User::all();
    }

    private function countSql(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    /** @return list<string> */
    private function groundTruth(string $operator, string $pattern): array
    {
        /** @var list<string> $names */
        $names = DB::table('users')
            ->where('name', $operator, $pattern)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        return $names;
    }

    #[Test]
    public function prefix_like_is_served_from_coverage_and_matches_database(): void
    {
        $this->seedUsers();

        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = User::where('name', 'like', 'Al%')
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertSame(0, $sql, 'ASCII LIKE over covered rows must issue no SQL');
        $this->assertSame($this->groundTruth('like', 'Al%'), $names);
        $this->assertSame(['Albert', 'Alice'], $names);
    }

    #[Test]
    public function not_like_is_served_from_coverage_and_matches_database(): void
    {
        $this->seedUsers();

        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = User::where('name', 'not like', 'Al%')
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertSame(0, $sql, 'ASCII NOT LIKE over covered rows must issue no SQL');
        $this->assertSame($this->groundTruth('not like', 'Al%'), $names);
        $this->assertSame(['Bob'], $names);
    }

    #[Test]
    public function underscore_wildcard_matches_single_character(): void
    {
        User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::all();

        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = User::where('name', 'like', 'An_')
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertSame(0, $sql);
        $this->assertSame(['Ann'], $names);
    }

    #[Test]
    public function non_ascii_pattern_defers_to_sql_but_stays_correct(): void
    {
        User::create(['name' => 'Café', 'email' => 'cafe@example.com']);
        User::create(['name' => 'Cabin', 'email' => 'cabin@example.com']);
        User::all();

        // The 'Café' row is non-ASCII, so the evaluator returns Unknown for it and
        // the query is completed by SQL rather than risking a wrong fold.
        $sql = $this->countSql(function (): void {
            $names = User::where('name', 'like', 'Caf%')
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame(['Café'], $names);
        });

        $this->assertGreaterThan(0, $sql, 'Non-ASCII LIKE must fall through to SQL');
    }
}
