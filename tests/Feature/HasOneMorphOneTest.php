<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Image;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Profile;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * HasIdentityMap overrides only 5 relation factories; hasOne and morphOne fall
 * through to Laravel's DB-backed relations. That is a deliberate pass-through —
 * but the engine still rewrites has()/whereHas over HasOne/MorphOne, so these
 * tests pin the contract: a hasOne/morphOne read and a has()-filter over one must
 * match a map-disabled read exactly, and exercising them must not poison the
 * identity map for the parent it fell through on.
 */
final class HasOneMorphOneTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function seedProfiles(): void
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        $boole = User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);
        User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);

        Profile::create(['user_id' => $ada->id, 'headline' => 'first', 'public' => true]);
        Profile::create(['user_id' => $boole->id, 'headline' => 'second', 'public' => false]);
        // Curie intentionally has no profile.

        $post = Post::create(['user_id' => $ada->id, 'title' => 'P', 'published' => true]);
        $other = Post::create(['user_id' => $boole->id, 'title' => 'Q', 'published' => false]);

        Image::create(['imageable_type' => $post->getMorphClass(), 'imageable_id' => $post->id, 'url' => 'p.png', 'primary' => true]);
        Image::create(['imageable_type' => $other->getMorphClass(), 'imageable_id' => $other->id, 'url' => 'q.png', 'primary' => false]);
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

    #[Test]
    public function has_one_read_matches_a_bypassed_read(): void
    {
        $this->seedProfiles();

        $this->assertMatchesBypass(
            fn (): array => User::query()->orderBy('id')->get()
                ->map(fn (User $u): ?string => $u->profile?->headline)->all(),
            'hasOne read must match SQL',
        );
    }

    #[Test]
    public function morph_one_read_matches_a_bypassed_read(): void
    {
        $this->seedProfiles();

        $this->assertMatchesBypass(
            fn (): array => Post::query()->orderBy('id')->get()
                ->map(fn (Post $p): ?string => $p->image?->url)->all(),
            'morphOne read must match SQL',
        );
    }

    #[Test]
    public function where_has_over_has_one_matches_a_bypassed_read(): void
    {
        $this->seedProfiles();

        // Warm the parents so the engine has something to (wrongly) serve from.
        User::query()->get();

        $this->assertMatchesBypass(
            fn (): array => User::whereHas('profile', fn ($q) => $q->where('public', true))
                ->orderBy('id')->pluck('id')->all(),
            'whereHas over hasOne must match SQL',
        );
    }

    #[Test]
    public function where_has_over_morph_one_matches_a_bypassed_read(): void
    {
        $this->seedProfiles();

        Post::query()->get();

        $this->assertMatchesBypass(
            fn (): array => Post::whereHas('image', fn ($q) => $q->where('primary', true))
                ->orderBy('id')->pluck('id')->all(),
            'whereHas over morphOne must match SQL',
        );
    }

    #[Test]
    public function doesnt_have_over_has_one_matches_a_bypassed_read(): void
    {
        $this->seedProfiles();

        $this->assertMatchesBypass(
            fn (): array => User::doesntHave('profile')->orderBy('id')->pluck('id')->all(),
            'doesntHave over hasOne must match SQL (Curie has no profile)',
        );
    }

    #[Test]
    public function reading_a_has_one_does_not_poison_the_parent_in_the_map(): void
    {
        $this->seedProfiles();

        // Warm the parent, read its pass-through hasOne, then a full-table read of
        // the parent must still equal a bypassed read (the child read must not have
        // written a partial or stale parent entry).
        $ada = User::query()->firstOrFail();
        $this->assertNotNull($ada->profile);

        $this->assertMatchesBypass(
            fn (): array => User::query()->orderBy('id')->get()
                ->map(fn (User $u): array => [$u->id, $u->name, $u->active])->all(),
            'a pass-through hasOne read must not poison the parent identity map',
        );
    }
}
