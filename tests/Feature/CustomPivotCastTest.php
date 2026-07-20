<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Pivots\CastTagging;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A belongsToMany using a custom Pivot (`->using()`) with casts may expose
 * in-memory pivot attribute values that diverge from the raw column values SQL
 * compares against in wherePivot. The memory path must still match the oracle
 * (serving when provably equivalent, bailing otherwise).
 */
final class CustomPivotCastTest extends TestCase
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

    private function makePost(): Post
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);

        $on = Tag::create(['name' => 'on', 'priority' => 1]);
        $off = Tag::create(['name' => 'off', 'priority' => 2]);
        $post->castTags()->attach($on, ['active' => true, 'priority' => 5, 'role' => 'r']);
        $post->castTags()->attach($off, ['active' => false, 'priority' => 9, 'role' => 'r']);

        return $post;
    }

    #[Test]
    public function where_pivot_boolean_cast_matches_oracle(): void
    {
        $post = $this->makePost();
        $post->load('castTags');

        $ricer = $post->castTags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn (): array => $post->castTags()->wherePivot('active', true)->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame(['on'], $ricer);
    }

    #[Test]
    public function where_pivot_integer_cast_matches_oracle(): void
    {
        $post = $this->makePost();
        $post->load('castTags');

        $ricer = $post->castTags()->wherePivot('priority', '>', 6)->get()->pluck('name')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn (): array => $post->castTags()->wherePivot('priority', '>', 6)->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame(['off'], $ricer);
    }

    #[Test]
    public function where_pivot_datetime_cast_column_matches_oracle(): void
    {
        $post = $this->makePost();
        $post->load('castTags');

        // created_at is a Carbon in memory but a string in the column; whatever the
        // decision (serve or bail), the result must equal SQL.
        $ricer = $post->castTags()->wherePivot('created_at', '>', '2000-01-01 00:00:00')->get()->pluck('name')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn (): array => $post->castTags()->wherePivot('created_at', '>', '2000-01-01 00:00:00')->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame($oracle, $ricer);
    }

    #[Test]
    public function pivot_accessor_is_the_custom_class(): void
    {
        $post = $this->makePost();
        $post->load('castTags');

        $first = $post->castTags()->first();
        $this->assertNotNull($first);
        $this->assertInstanceOf(CastTagging::class, $first->getRelation('pivot'));
        // Cast applied in memory.
        $this->assertIsBool($first->getRelation('pivot')->getAttribute('active'));
    }
}
