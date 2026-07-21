<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: coverage invalidation ordering (#97).
 *
 * Records coverage regions (predicate-scoped collections), then creates, updates,
 * and deletes rows that should invalidate them — in every shuffled order. The
 * region invariants re-run each covered predicate after every step and assert the
 * engine never serves a stale collection: a create that should enter a region, an
 * update that should leave it, or a delete that should drop from it must all be
 * reflected regardless of when they happen relative to the coverage recording.
 */
final class CoverageInvalidationJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('record active region')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::where('active', true)->get()),

            Step::make('record score region')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::where('score', '>', 50)->get()),

            Step::make('create row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => "created-{$seq}",
                        'email' => "created-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                    ]);
                }),

            Step::make('update row')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickLiveId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if (! $user instanceof User) {
                        return;
                    }

                    $user->active = $ctx->randomInt(0, 1) === 1;
                    $user->score = $ctx->randomInt(0, 100);
                    $user->save();
                }),

            Step::make('mass update')
                ->repeatable(max: 5)
                ->act(fn (Context $ctx): mixed => User::where('active', $ctx->randomInt(0, 1) === 1)
                    ->update(['score' => $ctx->randomInt(0, 100)])),

            Step::make('soft delete row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickLiveId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->delete();
                    }
                }),

            Step::make('restore row')
                ->repeatable(max: 5)
                ->when(fn (): bool => User::onlyTrashed()->exists())
                ->act(function (Context $ctx): void {
                    $id = $this->pickTrashedId($ctx);
                    if ($id === null) {
                        return;
                    }

                    User::onlyTrashed()->whereKey($id)->restore();
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(User::class, self::COLUMNS),
            IdentityMapInvariants::queryMatchesBypass(
                'active = true',
                fn (): mixed => User::where('active', true)->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'active = false',
                fn (): mixed => User::where('active', false)->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'score > 50',
                fn (): mixed => User::where('score', '>', 50)->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function pickLiveId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }

    private function pickTrashedId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::onlyTrashed()->pluck('id')->all());
    }
}
