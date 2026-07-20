<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class OrPredicateCoverageTest extends TestCase
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
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true]);
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

    #[Test]
    public function top_level_or_where_is_served_from_coverage(): void
    {
        $this->seedUsers();

        $names = null;

        $sql = $this->countSql(function () use (&$names): void {
            $names = User::where('name', 'Alice')
                ->orWhere('name', 'Bob')
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertSame(0, $sql, 'where()->orWhere() over covered rows must issue no SQL');
        $this->assertSame(['Alice', 'Bob'], $names);
    }

    #[Test]
    public function nested_or_group_is_served_from_coverage(): void
    {
        $this->seedUsers();

        $names = null;

        $sql = $this->countSql(function () use (&$names): void {
            $names = User::where('active', true)
                ->where(function ($q): void {
                    $q->where('name', 'Alice')->orWhere('name', 'Carol');
                })
                ->get()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertSame(0, $sql, 'active AND (name=Alice OR name=Carol) must serve from memory');
        $this->assertSame(['Alice', 'Carol'], $names);
    }

    #[Test]
    public function or_result_matches_database_ground_truth(): void
    {
        $this->seedUsers();

        // Ground truth from a fresh, bypassing connection query.
        $expected = DB::table('users')
            ->where('name', 'Alice')
            ->orWhere('active', false)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $served = User::where('name', 'Alice')
            ->orWhere('active', false)
            ->get()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $served);
        $this->assertSame(['Alice', 'Bob'], $served);
    }

    #[Test]
    public function top_level_or_excludes_soft_deleted_rows(): void
    {
        $this->seedUsers(); // Alice (active), Bob (inactive), Carol (active); coverage recorded

        // Soft-delete Bob, who matches the `active = false` branch of the OR.
        User::where('name', 'Bob')->firstOrFail()->delete();

        // DB ground truth mirroring the default soft-delete scope:
        // deleted_at IS NULL AND (name = 'Alice' OR active = false).
        $expected = DB::table('users')
            ->whereNull('deleted_at')
            ->where(function ($q): void {
                $q->where('name', 'Alice')->orWhere('active', false);
            })
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $served = User::where('name', 'Alice')
            ->orWhere('active', false)
            ->get()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $served);
        $this->assertNotContains('Bob', $served, 'the soft-deleted user must not be served');
        $this->assertSame(['Alice'], $served);
    }
}
