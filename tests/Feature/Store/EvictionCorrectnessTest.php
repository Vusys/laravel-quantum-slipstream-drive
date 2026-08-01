<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Store;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Issue #102 acceptance: a cap breach evicts cold entries while hot ones
 * survive, and no coverage region or relation grant ever answers a query the
 * database would not — an eviction can only ever cost a fall-through to SQL.
 */
final class EvictionCorrectnessTest extends TestCase
{
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
    public function a_covered_query_still_returns_complete_results_after_its_entries_are_evicted(): void
    {
        config(['quantum-slipstream-drive.store_caps.max_entries' => 10]);
        app()->forgetInstance(IdentityMapStore::class);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => false]);

        $query = static fn () => User::where('active', true)->get();

        $query();
        $this->assertSame(0, $this->countSql($query), 'sanity: the repeated read is served from coverage before eviction');

        // Churn unrelated keys far past the cap so every covered entry is
        // evicted (each breach sheds the coldest key).
        $store = resolve(IdentityMapStore::class);
        foreach (range(1_000, 1_030) as $i) {
            $store->recordAbsent('default', User::class, 'users', 'id', $i, 'fp');
        }

        $served = null;
        $sql = $this->countSql(function () use (&$served, $query): void {
            $served = $query();
        });

        $this->assertNotNull($served);
        $this->assertGreaterThan(0, $sql, 'with its entries evicted, the region must fall back to SQL rather than serve from memory');
        $this->assertEqualsCanonicalizing(
            [$alice->id, $bob->id],
            $served->pluck('id')->all(),
            'the fallback returns every row the database matches — eviction never narrows a result',
        );
    }

    #[Test]
    public function a_loaded_pivot_relation_still_returns_complete_results_after_its_bucket_is_evicted(): void
    {
        config(['quantum-slipstream-drive.relation_graph.max_edges' => 2]);
        app()->forgetInstance(IdentityGraph::class);

        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post = Post::create(['title' => 'Covered', 'user_id' => $author->id]);
        $php = Tag::create(['name' => 'php']);
        $laravel = Tag::create(['name' => 'laravel']);
        $post->tags()->attach([$php->id, $laravel->id]);

        $other = Post::create(['title' => 'Churn', 'user_id' => $author->id]);
        $ruby = Tag::create(['name' => 'ruby']);
        $rails = Tag::create(['name' => 'rails']);

        $post->load('tags');

        // Loading another post's tags overflows max_edges and evicts the
        // first post's pivot bucket — its coverage grant must go with it.
        $other->tags()->attach([$ruby->id, $rails->id]);
        $other->load('tags');

        $names = Post::query()->findOrFail($post->id)->tags()->get()->pluck('name');

        $this->assertEqualsCanonicalizing(
            ['php', 'laravel'],
            $names->all(),
            'an evicted pivot bucket must send the relation back to SQL, never serve an empty collection',
        );
    }
}
