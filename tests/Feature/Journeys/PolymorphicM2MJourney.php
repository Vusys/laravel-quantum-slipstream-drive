<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Video;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: polymorphic many-to-many under edits (#108).
 *
 * Extends the full-sync journey to the polymorphic pivot: Posts and Videos share
 * a `taggables` table via morphToMany, and Tags reach both back via morphedByMany.
 * attach / detach / sync interleave with tag and owner writes — and, critically,
 * the belongsToMany `post_tag` pivot is mutated in the same trail, so a
 * PivotCoverage that leaks between the two pivots sharing the Post/Tag models is
 * caught. Every owner's polymorphic tag set and every tag's owners are compared to
 * a map-disabled read after each step.
 */
final class PolymorphicM2MJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array TAG_COLUMNS = ['id', 'name'];

    /** @var non-empty-list<string> */
    private const array POST_COLUMNS = ['id', 'title'];

    /** @var non-empty-list<string> */
    private const array VIDEO_COLUMNS = ['id', 'title'];

    /** @var non-empty-list<string> */
    private const array PIVOT_COLUMNS = ['active', 'priority', 'role'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('load post topics')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->load('topics');
                }),

            Step::make('load video tags')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickVideo($ctx)?->load('tags');
                }),

            Step::make('attach topic to post')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($post instanceof Post && $tagId !== null) {
                        $post->topics()->syncWithoutDetaching([$tagId]);
                    }
                }),

            Step::make('sync post topics')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $subset = [];
                    foreach (Tag::query()->pluck('id')->all() as $tagId) {
                        if (is_int($tagId) && $ctx->randomInt(0, 1) === 1) {
                            $subset[] = $tagId;
                        }
                    }

                    $post->topics()->sync($subset);
                }),

            Step::make('detach topic from post')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $tagId = $this->pickId($ctx, $post->topics()->pluck('tags.id')->all());
                    if ($tagId !== null) {
                        $post->topics()->detach($tagId);
                    }
                }),

            Step::make('attach tag to video')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $video = $this->pickVideo($ctx);
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($video instanceof Video && $tagId !== null) {
                        $video->tags()->syncWithoutDetaching([$tagId]);
                    }
                }),

            Step::make('detach tag from video')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $video = $this->pickVideo($ctx);
                    if (! $video instanceof Video) {
                        return;
                    }

                    $tagId = $this->pickId($ctx, $video->tags()->pluck('tags.id')->all());
                    if ($tagId !== null) {
                        $video->tags()->detach($tagId);
                    }
                }),

            Step::make('mutate belongs-to-many pivot')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    $tagId = $this->pickId($ctx, Tag::query()->pluck('id')->all());
                    if ($post instanceof Post && $tagId !== null) {
                        $post->tags()->syncWithoutDetaching([$tagId => ['priority' => $ctx->randomInt(0, 9)]]);
                    }
                }),

            Step::make('create tag')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('tags'));
                    $ctx->push('tags', $seq);

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

            Step::make('create video')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('videos'));
                    $ctx->push('videos', $seq);

                    Video::create(['title' => "video-{$seq}", 'published' => $ctx->randomInt(0, 1) === 1]);
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::relationMatchesBypass(
                'post.topics',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->topics()->get() : [],
                self::TAG_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'video.tags',
                Video::class,
                fn (Model $parent): mixed => $parent instanceof Video ? $parent->tags()->get() : [],
                self::TAG_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'tag.taggedPosts',
                Tag::class,
                fn (Model $parent): mixed => $parent instanceof Tag ? $parent->taggedPosts()->get() : [],
                self::POST_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'tag.taggedVideos',
                Tag::class,
                fn (Model $parent): mixed => $parent instanceof Tag ? $parent->taggedVideos()->get() : [],
                self::VIDEO_COLUMNS,
            ),
            // The belongsToMany pivot on the same Post/Tag models must stay correct,
            // including its pivot attributes — the 'mutate belongs-to-many pivot'
            // step exists precisely to catch stale/wrong active/priority/role values.
            IdentityMapInvariants::relationPivotMatchesBypass(
                'post.tags (belongsToMany)',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->tags()->get() : [],
                self::TAG_COLUMNS,
                self::PIVOT_COLUMNS,
            ),
        ];
    }

    private function pickPost(Context $ctx): ?Post
    {
        $id = $this->pickId($ctx, Post::query()->pluck('id')->all());
        $post = $id === null ? null : Post::find($id);

        return $post instanceof Post ? $post : null;
    }

    private function pickVideo(Context $ctx): ?Video
    {
        $id = $this->pickId($ctx, Video::query()->pluck('id')->all());
        $video = $id === null ? null : Video::find($id);

        return $video instanceof Video ? $video : null;
    }
}
