<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: soft-delete + scope interleaving (#99).
 *
 * Deletes, restores, and force-deletes rows in every shuffled order while reading
 * through differing scope fingerprints — the default (trashed excluded),
 * withTrashed, onlyTrashed, and predicate-narrowed variants. Each scope has its
 * own invariant asserting the engine's read matches ground truth, so a delete
 * that a covered default-scope collection fails to drop, or a restore that a
 * withTrashed region fails to surface, is caught whatever order it lands in.
 */
final class SoftDeleteScopeJourney extends Journey
{
    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('read default scope')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::query()->get()),

            Step::make('read with-trashed scope')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::withTrashed()->get()),

            Step::make('read only-trashed scope')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::onlyTrashed()->get()),

            Step::make('read active predicate scope')
                ->repeatable(max: 4)
                ->act(fn (Context $ctx): mixed => User::where('active', $ctx->randomInt(0, 1) === 1)->get()),

            Step::make('create user')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => "created-{$seq}",
                        'email' => "created-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),

            Step::make('toggle active')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->find($this->pickLiveId($ctx));
                    if ($user instanceof User) {
                        $user->active = ! $user->active;
                        $user->save();
                    }
                }),

            Step::make('soft delete')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $user = $this->find($this->pickLiveId($ctx));
                    if ($user instanceof User) {
                        $user->delete();
                    }
                }),

            Step::make('restore')
                ->repeatable(max: 5)
                ->when(fn (): bool => User::onlyTrashed()->exists())
                ->act(function (Context $ctx): void {
                    $id = $this->pickTrashedId($ctx);
                    if ($id !== null) {
                        User::onlyTrashed()->whereKey($id)->restore();
                    }
                }),

            Step::make('force delete')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->find($this->pickAnyId($ctx));
                    if ($user instanceof User) {
                        $user->forceDelete();
                    }
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::queryMatchesBypass(
                'default scope',
                fn (): mixed => User::query()->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'with trashed',
                fn (): mixed => User::withTrashed()->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'only trashed',
                fn (): mixed => User::onlyTrashed()->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'active = true',
                fn (): mixed => User::where('active', true)->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'with trashed + active = false',
                fn (): mixed => User::withTrashed()->where('active', false)->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function find(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }

        $user = User::withTrashed()->whereKey($id)->first();

        return $user instanceof User ? $user : null;
    }

    private function pickLiveId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::query()->pluck('id')->all());
    }

    private function pickTrashedId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::onlyTrashed()->pluck('id')->all());
    }

    private function pickAnyId(Context $ctx): ?int
    {
        return $this->pickId($ctx, User::withTrashed()->pluck('id')->all());
    }

    /**
     * @param  array<array-key, mixed>  $ids
     */
    private function pickId(Context $ctx, array $ids): ?int
    {
        $ids = array_values($ids);

        if ($ids === []) {
            return null;
        }

        $picked = $ctx->pick($ids);

        return is_numeric($picked) ? (int) $picked : null;
    }
}
