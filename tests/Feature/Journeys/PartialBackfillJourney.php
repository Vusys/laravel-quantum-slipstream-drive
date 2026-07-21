<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: partial-select / backfill under edits (#108).
 *
 * With partial_models = backfill_missing_columns, a read of a column subset caches
 * a *partial* identity entry, and a later full-column read forces ColumnBackfiller
 * to fill the gaps. This trail interleaves partial reads (id+name, id+score+active,
 * a single row's id+bio), full-column reads, and writes in every shuffled order.
 * After each step a full-table read through the engine must equal a map-disabled
 * read: a backfill that pulls a stale column, or a partial entry that a write
 * failed to invalidate before it was upgraded, shows up as the two diverging.
 */
final class PartialBackfillJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score', 'bio'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('partial read id+name')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::select('id', 'name')->get()),

            Step::make('partial read id+score+active')
                ->repeatable(max: 5)
                ->act(fn (): mixed => User::select('id', 'score', 'active')->get()),

            Step::make('partial read single row id+bio')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        User::select('id', 'bio')->whereKey($id)->first();
                    }
                }),

            Step::make('full find')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        User::find($id);
                    }
                }),

            Step::make('full table read')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::query()->get()),

            Step::make('save mutation')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if ($user instanceof User) {
                        $user->score = $ctx->randomInt(0, 100);
                        $user->active = $ctx->randomInt(0, 1) === 1;
                        $user->bio = $ctx->randomInt(0, 1) === 1 ? null : 'bio-'.$ctx->randomInt(1, 1_000_000);
                        $user->save();
                    }
                }),

            Step::make('mass update')
                ->repeatable(max: 5)
                ->act(fn (Context $ctx): mixed => User::where('active', $ctx->randomInt(0, 1) === 1)
                    ->update(['score' => $ctx->randomInt(0, 100)])),

            Step::make('raw update')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id !== null) {
                        DB::table('users')->where('id', $id)->update(['bio' => 'raw-'.$ctx->randomInt(1, 1_000_000)]);
                    }
                }),

            Step::make('create row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => "created-{$seq}",
                        'email' => "partial-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                        'bio' => $ctx->randomInt(0, 1) === 1 ? null : 'seed-bio',
                    ]);
                }),

            Step::make('delete row')
                ->repeatable(max: 4)
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
            IdentityMapInvariants::readsMatchBypass(User::class, self::COLUMNS),
        ];
    }

    private function pickUserId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }
}
