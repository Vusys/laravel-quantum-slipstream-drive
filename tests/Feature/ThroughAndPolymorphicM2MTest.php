<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Country;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Video;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Fallback-safety contracts for the relation kinds the engine does not model:
 * hasManyThrough and the polymorphic many-to-many pair (morphToMany /
 * morphedByMany). The engine must decline to serve these from memory and pass
 * through to SQL without corrupting state — reads must match a bypassed read, and
 * the polymorphic pivot (taggables) must never collide with the existing
 * belongsToMany pivot (post_tag) that shares the same Tag/Post models.
 */
final class ThroughAndPolymorphicM2MTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    /**
     * @param  \Closure(): mixed  $query
     */
    private function assertMatchesBypass(\Closure $query, string $message): void
    {
        $engine = $query();
        $bypass = IdentityMap::disabled($query);

        $this->assertSame($bypass, $engine, $message);
    }

    /** @return list<list<mixed>> */
    private function countryPostTitles(): array
    {
        $out = [];
        foreach (Country::query()->orderBy('id')->get() as $country) {
            $out[] = array_values($country->posts()->orderBy('posts.id')->pluck('posts.title')->all());
        }

        return $out;
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<mixed>
     */
    private function relatedIds(string $model, int $key, string $relation, string $column): array
    {
        $instance = $model::query()->whereKey($key)->first();

        if (! $instance instanceof Model) {
            return [];
        }

        return array_values($instance->{$relation}()->orderBy($column)->pluck($column)->all());
    }

    #[Test]
    public function has_many_through_read_matches_a_bypassed_read(): void
    {
        $spain = Country::create(['name' => 'Spain']);
        $france = Country::create(['name' => 'France']);

        $ada = User::create(['country_id' => $spain->id, 'name' => 'Ada', 'email' => 'ada@example.com']);
        $boole = User::create(['country_id' => $france->id, 'name' => 'Boole', 'email' => 'boole@example.com']);

        Post::create(['user_id' => $ada->id, 'title' => 'A1']);
        Post::create(['user_id' => $ada->id, 'title' => 'A2']);
        Post::create(['user_id' => $boole->id, 'title' => 'B1']);

        // Warm the intermediate + far models so a naive engine might try to serve.
        User::query()->get();
        Post::query()->get();

        $this->assertMatchesBypass(
            fn (): array => $this->countryPostTitles(),
            'hasManyThrough must pass through to SQL and match a bypassed read',
        );
    }

    #[Test]
    public function has_many_through_read_does_not_poison_intermediate_models(): void
    {
        $spain = Country::create(['name' => 'Spain']);
        $ada = User::create(['country_id' => $spain->id, 'name' => 'Ada', 'email' => 'ada@example.com']);
        Post::create(['user_id' => $ada->id, 'title' => 'A1']);

        User::query()->get();
        Post::query()->get();

        // Read the through relation, then re-read the intermediate/far tables.
        $this->countryPostTitles();

        $this->assertMatchesBypass(
            fn (): array => User::query()->orderBy('id')->get()->map(fn (User $u): array => [$u->id, $u->name])->all(),
            'through read must not poison the User identity map',
        );
        $this->assertMatchesBypass(
            fn (): array => Post::query()->orderBy('id')->get()->map(fn (Post $p): array => [$p->id, $p->title])->all(),
            'through read must not poison the Post identity map',
        );
    }

    #[Test]
    public function morph_to_many_reads_match_a_bypassed_read(): void
    {
        $post = Post::create(['user_id' => User::create(['name' => 'U', 'email' => 'u@example.com'])->id, 'title' => 'P']);
        $video = Video::create(['title' => 'V', 'published' => true]);
        $red = Tag::create(['name' => 'red']);
        $blue = Tag::create(['name' => 'blue']);

        $post->topics()->attach([$red->id, $blue->id]);
        $video->tags()->attach([$red->id]);

        $post->load('topics');
        $video->load('tags');
        $red->load(['taggedPosts', 'taggedVideos']);

        $this->assertMatchesBypass(
            fn (): array => $this->relatedIds(Post::class, $post->id, 'topics', 'tags.id'),
            'morphToMany (post topics) must match SQL',
        );
        $this->assertMatchesBypass(
            fn (): array => $this->relatedIds(Video::class, $video->id, 'tags', 'tags.id'),
            'morphToMany (video tags) must match SQL',
        );
        $this->assertMatchesBypass(
            fn (): array => $this->relatedIds(Tag::class, $red->id, 'taggedPosts', 'posts.id'),
            'morphedByMany (tag posts) must match SQL',
        );
    }

    #[Test]
    public function polymorphic_pivot_does_not_collide_with_belongs_to_many_pivot(): void
    {
        $post = Post::create(['user_id' => User::create(['name' => 'U', 'email' => 'u@example.com'])->id, 'title' => 'P']);
        $red = Tag::create(['name' => 'red']);
        $blue = Tag::create(['name' => 'blue']);

        // Same Post/Tag models, two different pivot tables.
        $post->tags()->sync([$red->id => ['active' => true, 'priority' => 1, 'role' => 'r']]); // post_tag
        $post->topics()->attach([$blue->id]); // taggables

        // Warm both — the belongsToMany pivot coverage must not answer for the
        // morphToMany relation or vice versa.
        $post->load('tags');
        $post->load('topics');

        $this->assertMatchesBypass(
            fn (): array => $this->relatedIds(Post::class, $post->id, 'tags', 'tags.id'),
            'belongsToMany (post_tag) must stay independent of the polymorphic pivot',
        );
        $this->assertMatchesBypass(
            fn (): array => $this->relatedIds(Post::class, $post->id, 'topics', 'tags.id'),
            'morphToMany (taggables) must stay independent of the belongsToMany pivot',
        );
    }
}
