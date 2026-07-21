<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Image;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Profile;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: hasOne / morphOne under edits (#108).
 *
 * The singular analogue of the morph-relation journey, over the two relation
 * kinds HasIdentityMap deliberately does not memory-serve: User hasOne Profile
 * and Post morphOne Image. The single child is created, updated and deleted while
 * the relation is read in every shuffled order, and after each step the child
 * tables and each singular relation are compared to a map-disabled read — the
 * guard that a pass-through relation still tracks ground truth and never leaves a
 * stale or partial parent behind as it interleaves with the memory-served world.
 */
final class HasOneMorphOneJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array PROFILE_COLUMNS = ['id', 'user_id', 'headline', 'public'];

    /** @var non-empty-list<string> */
    private const array IMAGE_COLUMNS = ['id', 'imageable_type', 'imageable_id', 'url', 'primary'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('read profile')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickUser($ctx)?->load('profile');
                }),

            Step::make('read image')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pickPost($ctx)?->load('image');
                }),

            Step::make('create profile')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->pickUser($ctx);
                    if ($user instanceof User && ! $user->profile()->exists()) {
                        $user->profile()->create([
                            'headline' => 'headline-'.$ctx->randomInt(1, 1_000_000),
                            'public' => $ctx->randomInt(0, 1) === 1,
                        ]);
                    }
                }),

            Step::make('update profile')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $profile = $this->pickProfile($ctx);
                    if ($profile instanceof Profile) {
                        $profile->headline = 'edited-'.$ctx->randomInt(1, 1_000_000);
                        $profile->public = $ctx->randomInt(0, 1) === 1;
                        $profile->save();
                    }
                }),

            Step::make('delete profile')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $this->pickProfile($ctx)?->delete();
                }),

            Step::make('create image')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $post = $this->pickPost($ctx);
                    if ($post instanceof Post) {
                        $post->image()->create([
                            'url' => 'img-'.$ctx->randomInt(1, 1_000_000).'.png',
                            'primary' => $ctx->randomInt(0, 1) === 1,
                        ]);
                    }
                }),

            Step::make('update image')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $image = $this->pickImage($ctx);
                    if ($image instanceof Image) {
                        $image->url = 'edited-'.$ctx->randomInt(1, 1_000_000).'.png';
                        $image->primary = $ctx->randomInt(0, 1) === 1;
                        $image->save();
                    }
                }),

            Step::make('delete image')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $this->pickImage($ctx)?->delete();
                }),

            Step::make('create user')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('users'));
                    $ctx->push('users', $seq);

                    User::create([
                        'name' => "user-{$seq}",
                        'email' => "hasone-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(Profile::class, self::PROFILE_COLUMNS),
            IdentityMapInvariants::readsMatchBypass(Image::class, self::IMAGE_COLUMNS),
            IdentityMapInvariants::relationMatchesBypass(
                'user.profile',
                User::class,
                fn (Model $parent): mixed => $parent instanceof User ? $parent->profile()->get() : [],
                self::PROFILE_COLUMNS,
            ),
            IdentityMapInvariants::relationMatchesBypass(
                'post.image',
                Post::class,
                fn (Model $parent): mixed => $parent instanceof Post ? $parent->image()->get() : [],
                self::IMAGE_COLUMNS,
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

    private function pickProfile(Context $ctx): ?Profile
    {
        $id = $this->pickId($ctx, Profile::query()->pluck('id')->all());
        $profile = $id === null ? null : Profile::find($id);

        return $profile instanceof Profile ? $profile : null;
    }

    private function pickImage(Context $ctx): ?Image
    {
        $id = $this->pickId($ctx, Image::query()->pluck('id')->all());
        $image = $id === null ? null : Image::find($id);

        return $image instanceof Image ? $image : null;
    }
}
