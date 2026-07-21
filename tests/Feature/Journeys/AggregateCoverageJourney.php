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
 * Journey: aggregates from coverage under edits (#108).
 *
 * When a predicate's rows are fully covered, count / sum / min / max / exists are
 * answered from memory instead of SQL. This trail records those regions and then
 * churns them — creating rows that enter, updating scores that shift a sum or
 * max, deleting rows that drop from a count — while re-running each aggregate
 * through the engine and against a map-disabled read after every step. A covered
 * region whose aggregate the engine forgets to recompute (a stale sum after a
 * score change, a count that still includes a deleted row) is caught immediately.
 */
final class AggregateCoverageJourney extends Journey
{
    use PicksIds;

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('record active region')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::where('active', true)->get()),

            Step::make('record inactive region')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::where('active', false)->get()),

            Step::make('record high-score region')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::where('score', '>', 50)->get()),

            Step::make('record whole table')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::query()->get()),

            Step::make('create row')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => "created-{$seq}",
                        'email' => "agg-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                    ]);
                }),

            Step::make('update score')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->active = $ctx->randomInt(0, 1) === 1;
                        $user->score = $ctx->randomInt(0, 100);
                        $user->save();
                    }
                }),

            Step::make('null a score')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->score = null;
                        $user->save();
                    }
                }),

            Step::make('delete row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->delete();
                    }
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::aggregateMatchesBypass(
                'count active',
                fn (): int => User::where('active', true)->count(),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'sum score of active',
                fn (): mixed => User::where('active', true)->sum('score'),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'max score of active',
                fn (): mixed => User::where('active', true)->max('score'),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'min score of high-score region',
                fn (): mixed => User::where('score', '>', 50)->min('score'),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'exists inactive',
                fn (): bool => User::where('active', false)->exists(),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'count high-score region',
                fn (): int => User::where('score', '>', 50)->count(),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'sum score whole table',
                fn (): mixed => User::query()->sum('score'),
            ),
            IdentityMapInvariants::aggregateMatchesBypass(
                'count whole table',
                fn (): int => User::query()->count(),
            ),
        ];
    }

    private function pickUserId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }
}
