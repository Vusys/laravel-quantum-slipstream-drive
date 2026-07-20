<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class RelationPredicateCoverageTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    private function seedUser(): User
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true, 'view_count' => 100]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => true, 'view_count' => 5]);
        Post::create(['user_id' => $user->id, 'title' => 'P3', 'published' => false, 'view_count' => 200]);

        return $user;
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
    public function subset_read_is_served_from_recorded_filtered_coverage(): void
    {
        $user = $this->seedUser();

        // Record filtered coverage: all published posts.
        $published = $user->posts()->where('published', true)->get();
        $this->assertCount(2, $published);

        // A read whose predicate is a subset (published = true AND view_count > 10)
        // reuses that coverage and prunes in memory — no SQL.
        $titles = null;
        $explanations = [];
        $sql = $this->countSql(function () use ($user, &$titles, &$explanations): void {
            $explanations = IdentityMap::explain(function () use ($user, &$titles): void {
                $titles = $user->posts()
                    ->where('published', true)
                    ->where('view_count', '>', 10)
                    ->get()
                    ->pluck('title')
                    ->sort()
                    ->values()
                    ->all();
            });
        });

        $this->assertSame(0, $sql, 'subset relation read must be served from filtered coverage');
        $this->assertSame(['P1'], $titles);

        $reasons = array_map(static fn (Explanation $e): string => $e->reason, array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::FilterHasManyInMemory,
        ));
        $this->assertContains(
            'has-many-graph-coverage-filtered',
            $reasons,
            'the subset read must be served from the graph filtered coverage path',
        );
    }

    #[Test]
    public function superset_read_falls_through_to_sql(): void
    {
        $user = $this->seedUser();

        $user->posts()->where('published', true)->get(); // filtered coverage

        // An unfiltered load is a superset of the recorded predicate — it must not
        // be served from the filtered coverage.
        $titles = null;
        $sql = $this->countSql(function () use ($user, &$titles): void {
            $titles = $user->posts()->get()->pluck('title')->sort()->values()->all();
        });

        $this->assertGreaterThan(0, $sql, 'unfiltered (superset) read must fall through to SQL');
        $this->assertSame(['P1', 'P2', 'P3'], $titles);
    }

    #[Test]
    public function creating_a_related_row_invalidates_filtered_coverage(): void
    {
        $user = $this->seedUser();

        $user->posts()->where('published', true)->get(); // filtered coverage

        // A newly created published post could belong to the recorded predicate set,
        // so the coverage must be invalidated.
        Post::create(['user_id' => $user->id, 'title' => 'P4', 'published' => true, 'view_count' => 50]);

        $titles = null;
        $sql = $this->countSql(function () use ($user, &$titles): void {
            $titles = $user->posts()
                ->where('published', true)
                ->get()
                ->pluck('title')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertGreaterThan(0, $sql, 'a related insert must invalidate filtered coverage');
        $this->assertSame(['P1', 'P2', 'P4'], $titles);
    }

    #[Test]
    public function updating_a_related_row_invalidates_filtered_coverage(): void
    {
        $user = $this->seedUser();

        $user->posts()->where('published', true)->get(); // filtered coverage

        // Publishing a previously-unpublished post adds it to the predicate set.
        $p3 = Post::where('title', 'P3')->firstOrFail();
        $p3->update(['published' => true]);

        $titles = null;
        $sql = $this->countSql(function () use ($user, &$titles): void {
            $titles = $user->posts()
                ->where('published', true)
                ->get()
                ->pluck('title')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertGreaterThan(0, $sql, 'a related update must invalidate filtered coverage');
        $this->assertSame(['P1', 'P2', 'P3'], $titles);
    }
}
