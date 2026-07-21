<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Comment;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: morph relations under edits (#108).
 *
 * RelationGraph (#98) only covered hasMany + belongsToMany. This one exercises
 * the polymorphic pair — Comment.commentable (MorphTo) against User.comments and
 * Post.comments (MorphMany) — while comments are created on either parent type,
 * updated, deleted, and *reparented* between a User and a Post. After every step
 * three oracles run: each parent's comment set through MemoryMorphMany, and each
 * comment's resolved owner through MemoryMorphTo, both against a map-disabled
 * read. A reparent the graph fails to propagate — a comment still listed under
 * its old owner, or a morphTo still pointing at it — surfaces immediately.
 */
final class MorphRelationJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COMMENT_COLUMNS = ['id', 'commentable_type', 'commentable_id', 'body', 'likes'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('load user comments')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickUser($ctx)?->load('comments');
                }),

            Step::make('load post comments')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->load('comments');
                }),

            Step::make('resolve commentable')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $comment = $this->pickComment($ctx);
                    if ($comment instanceof Comment) {
                        $comment->load('commentable');
                    }
                }),

            Step::make('comment on user')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if ($user instanceof User) {
                        $user->comments()->create($this->commentAttributes($ctx));
                    }
                }),

            Step::make('comment on post')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if ($post instanceof Post) {
                        $post->comments()->create($this->commentAttributes($ctx));
                    }
                }),

            Step::make('update comment')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $comment = $this->pickComment($ctx);
                    if ($comment instanceof Comment) {
                        $comment->body = 'edited-'.$ctx->randomInt(1, 1_000_000);
                        $comment->likes = $ctx->randomInt(0, 50);
                        $comment->save();
                    }
                }),

            Step::make('reparent comment')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $comment = $this->pickComment($ctx);
                    $parent = $this->pickAnyParent($ctx);
                    if ($comment instanceof Comment && $parent instanceof Model) {
                        $comment->commentable()->associate($parent);
                        $comment->save();
                    }
                }),

            Step::make('delete comment')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickComment($ctx)?->delete();
                }),

            Step::make('delete post owner')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->delete();
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(Comment::class, self::COMMENT_COLUMNS),
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

    private function pickAnyParent(Context $ctx): ?Model
    {
        return $ctx->randomInt(0, 1) === 1 ? $this->pickUser($ctx) : $this->pickPost($ctx);
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
