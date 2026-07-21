<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: full sync() + pivot-attribute edits (#108).
 *
 * RelationGraph (#98) only ever used syncWithoutDetaching / detach. This trail
 * adds the detaching operations the graph never saw — full sync() (which removes
 * absent tags and, before the fix in this PR, cached the caller's PHP-typed pivot
 * values instead of the database representation), updateExistingPivot, and pivot
 * writes carried on attach — all interleaved with reads through the same pivot
 * relation. The relation+pivot oracle re-reads each post's tags after every step
 * and compares the full tag set and every pivot attribute against a map-disabled
 * read, so a PivotCoverage entry that outlives a sync-detach, or serves a stale /
 * mis-typed pivot value or a partial related row, is caught whatever order the
 * edits land in.
 */
final class SyncPivotJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array POST_COLUMNS = ['id', 'user_id', 'title', 'published'];

    /** @var non-empty-list<string> */
    private const array TAG_COLUMNS = ['id', 'name'];

    /** @var non-empty-list<string> */
    private const array PIVOT_COLUMNS = ['active', 'priority', 'role'];

    /** @var non-empty-list<string> */
    private const array ROLES = ['primary', 'secondary', 'tertiary'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('load tags')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->load('tags');
                }),

            Step::make('full sync detaching')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $subset = [];
                    foreach (Tag::query()->pluck('id')->all() as $tagId) {
                        if (is_int($tagId) && $ctx->randomInt(0, 1) === 1) {
                            $subset[$tagId] = $this->pivotData($ctx);
                        }
                    }

                    $post->tags()->sync($subset);
                }),

            Step::make('attach with pivot')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($post instanceof Post && $tagId !== null) {
                        $post->tags()->syncWithoutDetaching([$tagId => $this->pivotData($ctx)]);
                    }
                }),

            Step::make('update existing pivot')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $tagId = $this->pickId($ctx, $post->tags()->pluck('tags.id')->all());
                    if ($tagId !== null) {
                        $post->tags()->updateExistingPivot($tagId, $this->pivotData($ctx));
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

            Step::make('create tag')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('tags_created'));
                    $ctx->push('tags_created', $seq);

                    Tag::create(['name' => "tag-{$seq}", 'priority' => $ctx->randomInt(0, 5)]);
                }),

            Step::make('delete tag')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($tagId !== null) {
                        Tag::whereKey($tagId)->delete();
                    }
                }),

            Step::make('create post')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $userId = $this->pickId($ctx, User::query()->pluck('id')->all());
                    if ($userId === null) {
                        return;
                    }

                    $seq = count($ctx->list('posts_created'));
                    $ctx->push('posts_created', $seq);

                    Post::create([
                        'user_id' => $userId,
                        'title' => "created-post-{$seq}",
                        'published' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(Post::class, self::POST_COLUMNS),
            IdentityMapInvariants::relationPivotMatchesBypass(
                'post.tags',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->tags()->get() : [],
                self::TAG_COLUMNS,
                self::PIVOT_COLUMNS,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function pivotData(Context $ctx): array
    {
        return [
            'active' => $ctx->randomInt(0, 1) === 1,
            'priority' => $ctx->randomInt(0, 9),
            'role' => $ctx->pick(self::ROLES),
        ];
    }

    private function pickPost(Context $ctx): ?Post
    {
        $id = $this->pickId($ctx, Post::query()->pluck('id')->all());
        $post = $id === null ? null : Post::find($id);

        return $post instanceof Post ? $post : null;
    }
}
