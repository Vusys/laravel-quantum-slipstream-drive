<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Graph;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Regression tests for two pivot-serve divergences surfaced by the sync journey
 * (#108): sync() must cache pivot attributes in the database's representation
 * (not the caller's PHP types), and a belongsToMany read served from pivot
 * coverage must never hand back a partial related model.
 */
final class SyncPivotRepresentationTest extends TestCase
{
    /** @return array<int, mixed> */
    private function pivotActiveByTag(int $postId): array
    {
        $post = Post::find($postId);
        if (! $post instanceof Post) {
            return [];
        }

        $out = [];
        foreach ($post->tags()->get() as $tag) {
            $pivot = $tag->getAttribute('pivot');
            $out[$tag->id] = $pivot instanceof Model ? $pivot->getAttribute('active') : null;
        }

        return $out;
    }

    private function firstTagName(int $postId): ?string
    {
        $post = Post::find($postId);
        if (! $post instanceof Post) {
            return null;
        }

        $tag = $post->tags()->first();

        return $tag instanceof Tag ? $tag->name : null;
    }

    private function castPivotActive(int $postId): mixed
    {
        $post = Post::find($postId);
        if (! $post instanceof Post) {
            return null;
        }

        $tag = $post->castTags()->first();
        $pivot = $tag?->getAttribute('pivot');

        return $pivot instanceof Model ? $pivot->getAttribute('active') : null;
    }

    #[Test]
    public function sync_caches_pivot_active_in_database_representation_not_php_bool(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tag = Tag::create(['name' => 'red']);

        // sync() is given a PHP bool; the DB stores and returns an int.
        $post->tags()->sync([$tag->id => ['active' => false, 'priority' => 2, 'role' => 'x']]);

        $engine = $this->pivotActiveByTag($post->id);
        $bypass = IdentityMap::disabled(fn (): mixed => $this->pivotActiveByTag($post->id));

        $this->assertSame($bypass, $engine, 'sync-cached pivot must match SQL byte-for-byte');
        $this->assertSame([$tag->id => 0], $engine);
    }

    #[Test]
    public function sync_through_a_cast_pivot_caches_the_cast_representation(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tag = Tag::create(['name' => 'red']);

        // castTags uses CastTagging (active => boolean), so a fresh read returns a
        // real bool; the sync-cached edge must carry that same representation.
        $post->castTags()->sync([$tag->id => ['active' => 0, 'priority' => 2, 'role' => 'x']]);

        $engine = $this->castPivotActive($post->id);
        $bypass = IdentityMap::disabled(fn (): mixed => $this->castPivotActive($post->id));

        $this->assertSame($bypass, $engine, 'cast-pivot sync must match a cast read byte-for-byte');
        $this->assertFalse($engine);
    }

    #[Test]
    public function belongs_to_many_from_coverage_never_serves_a_partial_related_model(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tag = Tag::create(['name' => 'green']);

        resolve(IdentityMapStore::class)->flush();

        // A column-subset read records a *partial* Tag entry (id only, no name).
        Tag::select('id')->get();

        // sync proves pivot coverage referencing that partial tag.
        $post->tags()->sync([$tag->id => ['active' => true, 'priority' => 1, 'role' => 'y']]);

        $engineName = $this->firstTagName($post->id);
        $bypassName = IdentityMap::disabled(fn (): ?string => $this->firstTagName($post->id));

        $this->assertSame($bypassName, $engineName, 'a coverage-served related model must not be partial');
        $this->assertSame('green', $engineName);
    }
}
