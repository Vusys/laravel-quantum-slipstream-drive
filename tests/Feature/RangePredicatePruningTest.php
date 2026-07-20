<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class RangePredicatePruningTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function createFresh(string $name, string $email, int $score): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'score' => $score,
        ]);
        $this->store->flush();

        return $user;
    }

    #[Test]
    public function gte_prunes_known_models_and_queries_only_unknown_keys(): void
    {
        $u1 = $this->createFresh('U1', 'u1@example.com', 10);
        $u2 = $this->createFresh('U2', 'u2@example.com', 20);
        $u3 = $this->createFresh('U3', 'u3@example.com', 30);
        $u4 = $this->createFresh('U4', 'u4@example.com', 40);

        User::find($u1->id);
        User::find($u2->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$u1->id, $u2->id, $u3->id, $u4->id])
            ->where('score', '>=', 15)
            ->get();

        $this->assertSame(1, $queryCount, 'Exactly one SQL query for the unknown keys [u3,u4]');

        $ids = $result->pluck('id')->all();
        sort($ids);
        $this->assertSame([$u2->id, $u3->id, $u4->id], $ids);
    }

    #[Test]
    public function between_prunes_using_memory_only(): void
    {
        $u1 = $this->createFresh('U1', 'u1@example.com', 10);
        $u2 = $this->createFresh('U2', 'u2@example.com', 20);

        User::find($u1->id);
        User::find($u2->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$u1->id, $u2->id])
            ->whereBetween('score', [15, 25])
            ->get();

        $this->assertSame(0, $queryCount, 'Both models known; predicate must resolve in memory');
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($u2->id, $first->id);
    }

    #[Test]
    public function not_between_prunes_using_memory_only(): void
    {
        $u1 = $this->createFresh('U1', 'u1@example.com', 10);
        $u2 = $this->createFresh('U2', 'u2@example.com', 20);

        User::find($u1->id);
        User::find($u2->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$u1->id, $u2->id])
            ->whereNotBetween('score', [15, 25])
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($u1->id, $first->id);
    }

    #[Test]
    public function between_boundary_values_inclusive(): void
    {
        $low = $this->createFresh('Low', 'low@example.com', 15);
        $high = $this->createFresh('High', 'high@example.com', 25);

        User::find($low->id);
        User::find($high->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$low->id, $high->id])
            ->whereBetween('score', [15, 25])
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(2, $result, 'BETWEEN is inclusive at both bounds');
    }

    #[Test]
    public function gt_strict_excludes_boundary(): void
    {
        $u1 = $this->createFresh('U1', 'u1@example.com', 18);
        $u2 = $this->createFresh('U2', 'u2@example.com', 19);

        User::find($u1->id);
        User::find($u2->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$u1->id, $u2->id])
            ->where('score', '>', 18)
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($u2->id, $first->id);
    }

    #[Test]
    public function range_with_null_score_falls_through_to_sql(): void
    {
        $known = $this->createFresh('Known', 'known@example.com', 25);
        $nullScore = User::create(['name' => 'Null', 'email' => 'null@example.com', 'score' => null]);
        $this->store->flush();

        User::find($known->id);
        User::find($nullScore->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // null score cannot be resolved → falls through to SQL for that key.
        $result = User::whereKey([$known->id, $nullScore->id])
            ->where('score', '>=', 18)
            ->get();

        $this->assertSame(1, $queryCount, 'Null attribute forces SQL fallback for that key');
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($known->id, $first->id);
    }

    #[Test]
    public function range_combined_with_equality_short_circuits_on_reject(): void
    {
        $u1 = $this->createFresh('U1', 'u1@example.com', 20);
        $u2 = $this->createFresh('U2', 'u2@example.com', 40);

        User::find($u1->id);
        User::find($u2->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$u1->id, $u2->id])
            ->where('score', '>=', 15)
            ->where('name', '=', 'U1')
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($u1->id, $first->id);
    }
}
