<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Support\TrailRollback;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: nested-transaction / savepoint consistency (#108).
 *
 * The trail already runs inside one wrapping transaction, so every step here
 * opens a *nested* level (a savepoint) via DB::transaction() closures — the one
 * path the other journeys never reach, since their outer wrapper hides all of
 * the TransactionJournal's begin/commit/rollback machinery. Rows are warmed into
 * the identity map, mutated one or more levels deep, and then the level is either
 * committed (its snapshot merges into the parent) or rolled back (its snapshot
 * must restore the cached row). After every step the shared oracle asserts an
 * engine read of the whole table equals a map-disabled read: a savepoint rollback
 * that fails to restore a cached row, or a nested commit that loses a mutation,
 * surfaces as the two reads diverging.
 */
final class NestedTransactionJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

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

            Step::make('committed nested save')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    DB::transaction(function () use ($ctx): void {
                        $this->mutate($ctx);
                    });
                }),

            Step::make('rolled-back nested save')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    try {
                        DB::transaction(function () use ($ctx): never {
                            $this->mutate($ctx);

                            throw new TrailRollback;
                        });
                    } catch (TrailRollback) {
                        // Intended: the savepoint rolled back, the engine must
                        // have restored the pre-mutation snapshot.
                    }
                }),

            Step::make('rolled-back nested create')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    try {
                        DB::transaction(function () use ($ctx): never {
                            $seq = count($ctx->list('created'));
                            $ctx->push('created', $seq);

                            User::create([
                                'name' => "nested-{$seq}",
                                'email' => "nested-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                                'active' => $ctx->randomInt(0, 1) === 1,
                                'score' => $ctx->randomInt(0, 100),
                            ]);

                            throw new TrailRollback;
                        });
                    } catch (TrailRollback) {
                        // The created row must vanish from the engine's view too.
                    }
                }),

            Step::make('rolled-back nested raw update')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickUserId($ctx);
                    if ($id === null) {
                        return;
                    }

                    try {
                        DB::transaction(function () use ($ctx, $id): never {
                            DB::table('users')->where('id', $id)->update(['score' => $ctx->randomInt(0, 100)]);

                            throw new TrailRollback;
                        });
                    } catch (TrailRollback) {
                        // A raw write undone by savepoint rollback must not leave
                        // a stale invalidation behind.
                    }
                }),

            Step::make('inner commit, outer rollback')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    try {
                        DB::transaction(function () use ($ctx): never {
                            $this->mutate($ctx);

                            DB::transaction(function () use ($ctx): void {
                                $this->mutate($ctx);
                            });

                            throw new TrailRollback;
                        });
                    } catch (TrailRollback) {
                        // The inner commit merged its snapshot up; the outer
                        // rollback must restore both levels' mutations.
                    }
                }),

            Step::make('inner rollback, outer commit')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    DB::transaction(function () use ($ctx): void {
                        $this->mutate($ctx);

                        try {
                            DB::transaction(function () use ($ctx): never {
                                $this->mutate($ctx);

                                throw new TrailRollback;
                            });
                        } catch (TrailRollback) {
                            // Inner mutation restored; the outer mutation persists
                            // and merges into the wrapping trail on commit.
                        }
                    });
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

    private function mutate(Context $ctx): void
    {
        $id = $this->pickUserId($ctx);
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
    }

    private function pickUserId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }
}
