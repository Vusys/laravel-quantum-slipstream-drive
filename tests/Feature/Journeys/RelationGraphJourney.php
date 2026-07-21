<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: relation graph under edits (#98).
 *
 * Loads relations — including predicate-scoped eager loads (B3) — then creates,
 * updates, and deletes related models and rewires the pivot in every shuffled
 * order. The relation invariants re-read each parent's children after every step
 * and assert the engine never hands back a deleted or stale child: a post removed
 * from a user, a tag detached from a post, or a freshly created child must all be
 * reflected no matter when the load happened relative to the edit.
 */
final class RelationGraphJourney extends Journey
{
    /** @var non-empty-list<string> */
    private const array POST_COLUMNS = ['id', 'user_id', 'title', 'published'];

    /** @var non-empty-list<string> */
    private const array TAG_COLUMNS = ['id', 'name'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('load posts')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    $user?->load('posts');
                }),

            Step::make('load published posts')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    $user?->load(['posts' => fn ($query) => $query->where('published', true)]);
                }),

            Step::make('load tags')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    $post?->load('tags');
                }),

            Step::make('create post')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if (! $user instanceof User) {
                        return;
                    }

                    $seq = count($ctx->list('posts_created'));
                    $ctx->push('posts_created', $seq);

                    $user->posts()->create([
                        'title' => "created-post-{$seq}",
                        'published' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),

            Step::make('update post')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $post->published = $ctx->randomInt(0, 1) === 1;
                    $post->title = 'updated-'.$ctx->randomInt(1, 1_000_000);
                    $post->save();
                }),

            Step::make('delete post')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if ($post instanceof Post) {
                        $post->delete();
                    }
                }),

            Step::make('attach tag')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($post instanceof Post && $tagId !== null) {
                        $post->tags()->syncWithoutDetaching([$tagId]);
                    }
                }),

            Step::make('detach tag')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $tagId = $this->pickId($ctx, $post->tags()->pluck('tags.id')->all());
                    if ($tagId !== null) {
                        $post->tags()->detach($tagId);
                    }
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(Post::class, self::POST_COLUMNS),
            IdentityMapInvariants::relationMatchesBypass(
                'user.posts',
                User::class,
                fn (Model $parent): mixed => $parent instanceof User ? $parent->posts()->get() : [],
                self::POST_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'post.tags',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->tags()->get() : [],
                self::TAG_COLUMNS,
            ),
        ];
    }

    private function pickUser(Context $ctx): ?User
    {
        $id = $this->pickId($ctx, User::query()->pluck('id')->all());
        $user = $id === null ? null : User::find($id);

        return $user instanceof User ? $user : null;
    }

    private function pickPost(Context $ctx): ?Post
    {
        $id = $this->pickId($ctx, Post::query()->pluck('id')->all());
        $post = $id === null ? null : Post::find($id);

        return $post instanceof Post ? $post : null;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     */
    private function pickId(Context $ctx, array $ids): ?int
    {
        $ids = array_values($ids);

        if ($ids === []) {
            return null;
        }

        $picked = $ctx->pick($ids);

        return is_numeric($picked) ? (int) $picked : null;
    }
}
