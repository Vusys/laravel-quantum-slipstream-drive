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
 * Journey: atomic-op / upsert consistency (#108).
 *
 * Consistency (#96) covers save, mass update, and raw writes — but never the
 * write paths where the engine cannot see the pre-image: increment / decrement
 * (column = column + n, evaluated in SQL), upsert, insertOrIgnore, and the
 * find-then-write helpers updateOrCreate / firstOrCreate. Each is interleaved
 * with warmed finds and covered-region reads, so a cached row the increment
 * silently moved, or a stale entry that makes firstOrCreate resurrect a deleted
 * row, shows up as the engine read diverging from a map-disabled read after the
 * step that caused it.
 */
final class AtomicOpJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

    /** @var non-empty-list<string> */
    private const array EMAILS = [
        'pool-a@example.com',
        'pool-b@example.com',
        'pool-c@example.com',
        'pool-d@example.com',
    ];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        User::find($id);
                    }
                }),

            Step::make('warm active region')
                ->repeatable(max: 4)
                ->act(fn (Context $ctx): mixed => User::where('active', $ctx->randomInt(0, 1) === 1)->get()),

            Step::make('increment score by id')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        User::whereKey($id)->increment('score', $ctx->randomInt(1, 10));
                    }
                }),

            Step::make('decrement score by predicate')
                ->repeatable(max: 6)
                ->act(fn (Context $ctx): mixed => User::where('active', $ctx->randomInt(0, 1) === 1)
                    ->decrement('score', $ctx->randomInt(1, 10))),

            Step::make('upsert pool')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $email = $ctx->pick(self::EMAILS);

                    User::upsert(
                        [[
                            'name' => 'upsert-'.$ctx->randomInt(1, 1_000_000),
                            'email' => $email,
                            'active' => $ctx->randomInt(0, 1) === 1,
                            'score' => $ctx->randomInt(0, 100),
                        ]],
                        ['email'],
                        ['name', 'active', 'score'],
                    );
                }),

            Step::make('insert or ignore pool')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $email = $ctx->pick(self::EMAILS);

                    User::insertOrIgnore([[
                        'name' => 'ignore-'.$ctx->randomInt(1, 1_000_000),
                        'email' => $email,
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                    ]]);
                }),

            Step::make('update or create pool')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $email = $ctx->pick(self::EMAILS);

                    User::updateOrCreate(
                        ['email' => $email],
                        [
                            'name' => 'uoc-'.$ctx->randomInt(1, 1_000_000),
                            'active' => $ctx->randomInt(0, 1) === 1,
                            'score' => $ctx->randomInt(0, 100),
                        ],
                    );
                }),

            Step::make('first or create pool')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $email = $ctx->pick(self::EMAILS);

                    User::firstOrCreate(
                        ['email' => $email],
                        [
                            'name' => 'foc-'.$ctx->randomInt(1, 1_000_000),
                            'active' => $ctx->randomInt(0, 1) === 1,
                            'score' => $ctx->randomInt(0, 100),
                        ],
                    );
                }),

            Step::make('save mutation')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->score = $ctx->randomInt(0, 100);
                        $user->save();
                    }
                }),

            Step::make('delete non-pool row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    // Deleting a pool-email row would soft-delete it while its
                    // unique email lingers, so the next updateOrCreate/firstOrCreate
                    // on that email would hit a DB unique violation — a Laravel-wide
                    // soft-delete quirk unrelated to the engine. Keep the pool rows
                    // alive and only churn the seeded, non-pool users.
                    $id = $this->pickId($ctx, User::query()->whereNotIn('email', self::EMAILS)->pluck('id')->all());
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
            IdentityMapInvariants::readsMatchBypass(User::class, self::COLUMNS),
        ];
    }

    private function pickUserId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }
}
