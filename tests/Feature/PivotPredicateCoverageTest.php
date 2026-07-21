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
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class PivotPredicateCoverageTest extends TestCase
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

    private function seedPost(): Post
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);

        $editor = Tag::create(['name' => 'Editor', 'priority' => 1]);
        $viewer = Tag::create(['name' => 'Viewer', 'priority' => 1]);
        $admin = Tag::create(['name' => 'Admin', 'priority' => 1]);

        $post->tags()->attach($editor, ['active' => true, 'priority' => 10, 'role' => 'editor']);
        $post->tags()->attach($viewer, ['active' => true, 'priority' => 1, 'role' => 'viewer']);
        $post->tags()->attach($admin, ['active' => false, 'priority' => 5, 'role' => 'admin']);

        return $post;
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
    public function subset_pivot_read_is_served_from_recorded_filtered_coverage(): void
    {
        $post = $this->seedPost();

        // Record filtered coverage: all active pivot rows.
        $active = $post->tags()->wherePivot('active', true)->get();
        $this->assertCount(2, $active);

        // A read whose predicate is a subset (active = true AND priority > 5)
        // reuses that coverage and prunes in memory — no SQL.
        $names = null;
        $explanations = [];
        $sql = $this->countSql(function () use ($post, &$names, &$explanations): void {
            $explanations = IdentityMap::explain(function () use ($post, &$names): void {
                $names = $post->tags()
                    ->wherePivot('active', true)
                    ->wherePivot('priority', '>', 5)
                    ->get()
                    ->pluck('name')
                    ->sort()
                    ->values()
                    ->all();
            });
        });

        $this->assertSame(0, $sql, 'subset pivot read must be served from filtered coverage');
        $this->assertSame(['Editor'], $names);

        $reasons = array_map(
            static fn (Explanation $e): string => $e->reason,
            array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::WherePivotInMemory),
        );
        $this->assertContains('belongs-to-many-graph-coverage-filtered', $reasons);
    }

    #[Test]
    public function identical_pivot_read_matches_bypassed_query(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // record filtered coverage

        $slipstream = null;
        $sql = $this->countSql(function () use ($post, &$slipstream): void {
            $slipstream = $post->tags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame(0, $sql, 'an identical pivot read must be served from filtered coverage');
        $this->assertSame($oracle, $slipstream);
        $this->assertSame(['Editor', 'Viewer'], $slipstream);
    }

    #[Test]
    public function pivot_in_subset_of_recorded_pivot_in_is_served_from_memory(): void
    {
        $post = $this->seedPost();

        // Record coverage for a bounded set of roles.
        $post->tags()->wherePivotIn('role', ['editor', 'viewer'])->get();

        // role = 'editor' is a subset of role IN ('editor', 'viewer').
        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->wherePivot('role', 'editor')->get()->pluck('name')->all();
        });

        $this->assertSame(0, $sql, 'a pivot column = v read is a subset of a recorded IN and must be served from memory');
        $this->assertSame(['Editor'], $names);
    }

    #[Test]
    public function superset_pivot_read_falls_through_to_sql(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // filtered coverage

        // An unfiltered load is a superset of the recorded predicate — it must not
        // be served from the filtered coverage.
        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->get()->pluck('name')->sort()->values()->all();
        });

        $this->assertGreaterThan(0, $sql, 'an unfiltered (superset) pivot read must fall through to SQL');
        $this->assertSame(['Admin', 'Editor', 'Viewer'], $names);
    }

    #[Test]
    public function disjoint_pivot_read_falls_through_to_sql(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // filtered coverage on active = true

        // active = false is disjoint from the recorded active = true coverage.
        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->wherePivot('active', false)->get()->pluck('name')->all();
        });

        $this->assertGreaterThan(0, $sql, 'a disjoint pivot read must fall through to SQL');
        $this->assertSame(['Admin'], $names);
    }

    #[Test]
    public function related_column_filtered_coverage_serves_subset_read(): void
    {
        $post = $this->seedPost();

        // Filter on a related (Tag) column rather than a pivot column.
        $post->tags()->where('name', 'Editor')->get();

        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->where('name', 'Editor')->get()->pluck('name')->all();
        });

        $this->assertSame(0, $sql, 'a related-column subset read must be served from filtered coverage');
        $this->assertSame(['Editor'], $names);
    }

    #[Test]
    public function updating_a_related_row_invalidates_filtered_pivot_coverage(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // filtered coverage

        // A change to a related row could shift the coverage's membership.
        $editor = Tag::where('name', 'Editor')->firstOrFail();
        $editor->update(['name' => 'Chief Editor']);

        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all();
        });

        $this->assertGreaterThan(0, $sql, 'a related update must invalidate filtered pivot coverage');
        $this->assertSame(['Chief Editor', 'Viewer'], $names);
    }

    #[Test]
    public function attaching_a_new_row_invalidates_filtered_pivot_coverage(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // filtered coverage

        $extra = Tag::create(['name' => 'Extra', 'priority' => 1]);
        $post->tags()->attach($extra, ['active' => true, 'priority' => 2, 'role' => 'extra']);

        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all();
        });

        $this->assertGreaterThan(0, $sql, 'attaching a matching row must invalidate filtered pivot coverage');
        $this->assertSame(['Editor', 'Extra', 'Viewer'], $names);
    }

    #[Test]
    public function updating_an_existing_pivot_invalidates_filtered_pivot_coverage(): void
    {
        $post = $this->seedPost();

        $post->tags()->wherePivot('active', true)->get(); // filtered coverage

        $viewer = Tag::where('name', 'Viewer')->firstOrFail();
        $post->tags()->updateExistingPivot($viewer->id, ['active' => false]);

        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all();
        });

        $this->assertGreaterThan(0, $sql, 'a pivot-attribute update must invalidate filtered pivot coverage');
        $this->assertSame(['Editor'], $names);
    }

    #[Test]
    public function unfiltered_read_is_not_downgraded_by_a_later_filtered_load(): void
    {
        $post = $this->seedPost();

        // Establish complete (unfiltered) coverage first.
        $post->tags()->get();

        // A filtered load must not downgrade the complete coverage.
        $post->tags()->wherePivot('active', true)->get();

        // The unfiltered read is still served entirely from memory.
        $names = null;
        $sql = $this->countSql(function () use ($post, &$names): void {
            $names = $post->tags()->get()->pluck('name')->sort()->values()->all();
        });

        $this->assertSame(0, $sql, 'complete coverage must survive a later filtered load');
        $this->assertSame(['Admin', 'Editor', 'Viewer'], $names);
    }
}
