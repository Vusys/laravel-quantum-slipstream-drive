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
 * Journey: ordering under edits (#108).
 *
 * The one place where "byte-for-byte identical rows" must also mean identical
 * order. String and nullable columns are re-ordered — asc, desc, nulls-first via
 * a nullable score, multi-column — while the very values being ordered are
 * mutated (renames spanning case and an accented character, score nulled and
 * refilled). Each ordered read runs through the engine and against a map-disabled
 * read *position by position*, so a memory sort that disagrees with the driver's
 * collation, or a stale row left in its old slot after a rename, is caught. This
 * is the regression guard for the Postgres locale-collation fix: where the engine
 * cannot prove its sort matches the driver's, it must defer to SQL.
 */
final class OrderingJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

    /** @var non-empty-list<string> */
    private const array NAMES = ['apple', 'Apple', 'banana', 'Banana', 'cherry', 'Zebra', 'ábc', 'aardvark'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        User::find($id);
                    }
                }),

            Step::make('read ordered by name')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::orderBy('name')->get()),

            Step::make('read ordered by score')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::orderBy('score')->get()),

            Step::make('rename across collation')
                ->repeatable(max: 8)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->name = $ctx->pick(self::NAMES).'-'.$ctx->randomInt(1, 9);
                        $user->save();
                    }
                }),

            Step::make('nudge score with nulls')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->score = $ctx->randomInt(0, 1) === 1 ? null : $ctx->randomInt(0, 100);
                        $user->save();
                    }
                }),

            Step::make('toggle active')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->active = ! $user->active;
                        $user->save();
                    }
                }),

            Step::make('create row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => $ctx->pick(self::NAMES).'-'.$seq,
                        'email' => "order-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 1) === 1 ? null : $ctx->randomInt(0, 100),
                    ]);
                }),

            Step::make('delete row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $this->pick($ctx)?->delete();
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::orderedQueryMatchesBypass(
                'order by name asc',
                fn (): mixed => User::orderBy('name')->orderBy('id')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::orderedQueryMatchesBypass(
                'order by name desc',
                fn (): mixed => User::orderBy('name', 'desc')->orderBy('id')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::orderedQueryMatchesBypass(
                'order by score asc (nulls)',
                fn (): mixed => User::orderBy('score')->orderBy('id')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::orderedQueryMatchesBypass(
                'order by score desc (nulls)',
                fn (): mixed => User::orderBy('score', 'desc')->orderBy('id')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::orderedQueryMatchesBypass(
                'order by active then name',
                fn (): mixed => User::orderBy('active')->orderBy('name')->orderBy('id')->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function pick(Context $ctx): ?User
    {
        $id = $this->pickUserId($ctx);
        $user = $id === null ? null : User::find($id);

        return $user instanceof User ? $user : null;
    }

    private function pickUserId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }
}
