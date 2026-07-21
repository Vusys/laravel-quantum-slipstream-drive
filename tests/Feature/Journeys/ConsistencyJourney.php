<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: load → mutate → reload consistency (#96).
 *
 * Interleaves warmed finds, Eloquent saves, predicate-scoped mass updates, and
 * raw DB::table() writes (update / insert / delete) in every shuffled order. The
 * shared invariant runs after every step: whatever the engine serves for the
 * whole users table must equal a map-disabled read of it. A raw write that fails
 * to invalidate a cached row, or a mass update that touches the wrong entry,
 * surfaces as the two reads diverging.
 */
final class ConsistencyJourney extends Journey
{
    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx);
                    if ($id !== null) {
                        User::find($id);
                    }
                }),

            Step::make('save mutation')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx);
                    if ($id === null) {
                        return;
                    }

                    $user = User::find($id);
                    if (! $user instanceof User) {
                        return;
                    }

                    $user->score = $ctx->randomInt(0, 100);
                    $user->active = $ctx->randomInt(0, 1) === 1;
                    $user->save();
                }),

            Step::make('mass update')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    User::where('active', $ctx->randomInt(0, 1) === 1)
                        ->update(['score' => $ctx->randomInt(0, 100)]);
                }),

            Step::make('raw update')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx);
                    if ($id === null) {
                        return;
                    }

                    DB::table('users')->where('id', $id)->update(['score' => $ctx->randomInt(0, 100)]);
                }),

            Step::make('raw insert')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('inserted'));
                    $ctx->push('inserted', $seq);

                    DB::table('users')->insert([
                        'name' => "raw-insert-{$seq}",
                        'email' => "raw-insert-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->randomInt(0, 100),
                    ]);
                }),

            Step::make('raw delete')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx);
                    if ($id === null) {
                        return;
                    }

                    DB::table('users')->where('id', $id)->delete();
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

    private function pickId(Context $ctx): ?int
    {
        /** @var list<mixed> $ids */
        $ids = User::query()->pluck('id')->all();

        if ($ids === []) {
            return null;
        }

        $picked = $ctx->pick($ids);

        return is_numeric($picked) ? (int) $picked : null;
    }
}
