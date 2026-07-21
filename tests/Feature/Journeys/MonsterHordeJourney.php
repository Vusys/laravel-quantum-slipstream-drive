<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Comment;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: cross-entity "monster horde" (#108).
 *
 * Every per-model journey mutates one entity's world in isolation. This one
 * mutates Users, Posts, Tags and Comments in the same shuffled trail, so the
 * graph-edge interactions no single-model journey can reach are exercised at
 * once: a user soft-deleted while it still has posts and comments, a post deleted
 * out from under its tags and comment thread, a tag detached as its pivot row is
 * cascaded away, a comment reparented across the very models being edited. After
 * every step the whole battery of oracles runs — each table's rows, every
 * hasMany / belongsToMany / morph relation, and the morphTo inverse — each
 * against a map-disabled read, so any cross-entity staleness surfaces immediately.
 */
final class MonsterHordeJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array USER_COLUMNS = ['id', 'name', 'active', 'score'];

    /** @var non-empty-list<string> */
    private const array POST_COLUMNS = ['id', 'user_id', 'title', 'published'];

    /** @var non-empty-list<string> */
    private const array TAG_COLUMNS = ['id', 'name'];

    /** @var non-empty-list<string> */
    private const array COMMENT_COLUMNS = ['id', 'commentable_type', 'commentable_id', 'body', 'likes'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('create user')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('users'));
                    $ctx->push('users', $seq);

                    User::create([
                        'name' => "user-{$seq}",
                        'email' => "horde-user-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                    ]);
                }),

            Step::make('update user')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if ($user instanceof User) {
                        $user->active = $ctx->randomInt(0, 1) === 1;
                        $user->score = $ctx->randomInt(0, 100);
                        $user->save();
                    }
                }),

            Step::make('soft delete user')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $this->pickUser($ctx)?->delete();
                }),

            Step::make('create post')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if (! $user instanceof User) {
                        return;
                    }

                    $seq = count($ctx->list('posts'));
                    $ctx->push('posts', $seq);

                    $user->posts()->create([
                        'title' => "post-{$seq}",
                        'published' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),

            Step::make('update post')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if ($post instanceof Post) {
                        $post->published = $ctx->randomInt(0, 1) === 1;
                        $post->title = 'edited-'.$ctx->randomInt(1, 1_000_000);
                        $post->save();
                    }
                }),

            Step::make('delete post')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->delete();
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

            Step::make('sync post tags')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if (! $post instanceof Post) {
                        return;
                    }

                    $subset = [];
                    foreach (Tag::query()->pluck('id')->all() as $tagId) {
                        if (! is_int($tagId)) {
                            continue;
                        }

                        $roll = $ctx->randomInt(0, 3);
                        if ($roll === 0) {
                            continue;
                        }

                        $subset[$tagId] = ['active' => $roll === 1, 'priority' => $ctx->randomInt(0, 9)];
                    }

                    $post->tags()->sync($subset);
                }),

            Step::make('comment on user')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if ($user instanceof User) {
                        $user->comments()->create($this->commentAttributes($ctx));
                    }
                }),

            Step::make('comment on post')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if ($post instanceof Post) {
                        $post->comments()->create($this->commentAttributes($ctx));
                    }
                }),

            Step::make('update comment')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $comment = $this->pickComment($ctx);
                    if ($comment instanceof Comment) {
                        $comment->likes = $ctx->randomInt(0, 50);
                        $comment->save();
                    }
                }),

            Step::make('reparent comment')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $comment = $this->pickComment($ctx);
                    $parent = $ctx->randomInt(0, 1) === 1 ? $this->pickUser($ctx) : $this->pickPost($ctx);
                    if ($comment instanceof Comment && $parent instanceof Model) {
                        $comment->commentable()->associate($parent);
                        $comment->save();
                    }
                }),

            Step::make('delete comment')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $this->pickComment($ctx)?->delete();
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(User::class, self::USER_COLUMNS),
            IdentityMapInvariants::readsMatchBypass(Post::class, self::POST_COLUMNS),
            IdentityMapInvariants::readsMatchBypass(Tag::class, self::TAG_COLUMNS),
            IdentityMapInvariants::readsMatchBypass(Comment::class, self::COMMENT_COLUMNS),
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
            IdentityMapInvariants::relationMatchesBypass(
                'user.comments',
                User::class,
                fn (Model $parent): mixed => $parent instanceof User ? $parent->comments()->get() : [],
                self::COMMENT_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'post.comments',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->comments()->get() : [],
                self::COMMENT_COLUMNS,
            ),
            IdentityMapInvariants::morphParentMatchesBypass(
                'comment.commentable',
                Comment::class,
                fn (Model $child): ?Model => $child instanceof Comment ? $child->commentable : null,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function commentAttributes(Context $ctx): array
    {
        $seq = count($ctx->list('comments'));
        $ctx->push('comments', $seq);

        return ['body' => "comment-{$seq}", 'likes' => $ctx->randomInt(0, 50)];
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

    private function pickComment(Context $ctx): ?Comment
    {
        $id = $this->pickId($ctx, Comment::query()->pluck('id')->all());
        $comment = $id === null ? null : Comment::find($id);

        return $comment instanceof Comment ? $comment : null;
    }
}
