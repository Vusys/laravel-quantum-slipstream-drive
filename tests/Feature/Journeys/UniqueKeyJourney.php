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
 * Journey: unique-key resolution under edits (#108).
 *
 * With email and name configured as unique keys, firstWhere('email', ...) is
 * answered from UniqueKeyIndex instead of SQL. This trail resolves rows by those
 * unique columns while renaming the very columns the index is keyed on, deleting
 * and restoring rows, and creating new ones. After every step each configured
 * lookup is resolved through the engine and against a map-disabled read: a value
 * renamed away that the index still maps to its old row, or a resolution that
 * misses a freshly-created row, surfaces as the two diverging. The whole-table
 * oracle runs alongside so a wrong row served by a unique hit is caught too.
 */
final class UniqueKeyJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'email', 'active'];

    /** @var non-empty-list<string> */
    private const array LOOKUP_EMAILS = [
        'ada@example.com',
        'boole@example.com',
        'curie@example.com',
        'ghost@example.com',
    ];

    /** @var non-empty-list<string> */
    private const array LOOKUP_NAMES = ['Ada', 'Boole', 'Curie', 'Nobody'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('resolve by email')
                ->repeatable(max: 6)
                ->act(fn (Context $ctx): mixed => User::where('email', $ctx->pick(self::LOOKUP_EMAILS))->first()),

            Step::make('resolve by name')
                ->repeatable(max: 6)
                ->act(fn (Context $ctx): mixed => User::where('name', $ctx->pick(self::LOOKUP_NAMES))->first()),

            Step::make('rename email')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->email = 'renamed-'.$ctx->randomInt(1, 1_000_000).'@example.com';
                        $user->save();
                    }
                }),

            Step::make('rename name')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->name = 'Renamed-'.$ctx->randomInt(1, 1_000_000);
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

            Step::make('delete row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->pick($ctx)?->delete();
                }),

            Step::make('restore row')
                ->repeatable(max: 5)
                ->when(fn (): bool => User::onlyTrashed()->exists())
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx, User::onlyTrashed()->pluck('id')->all());
                    if ($id !== null) {
                        User::onlyTrashed()->whereKey($id)->restore();
                    }
                }),

            Step::make('force delete row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx, User::withTrashed()->pluck('id')->all());
                    if ($id !== null) {
                        User::withTrashed()->whereKey($id)->forceDelete();
                    }
                }),

            Step::make('recreate a lookup email')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $email = $ctx->pick(self::LOOKUP_EMAILS);

                    // A soft-deleted row still occupies the unique email index, so
                    // only recreate when the value is free across *all* scopes —
                    // otherwise the INSERT trips the DB unique constraint (a
                    // Laravel-wide soft-delete quirk unrelated to the engine).
                    if (User::withTrashed()->where('email', $email)->exists()) {
                        return;
                    }

                    User::create([
                        'name' => 'Recreated-'.$ctx->randomInt(1, 1_000_000),
                        'email' => $email,
                        'active' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        $invariants = [
            IdentityMapInvariants::readsMatchBypass(User::class, self::COLUMNS),
        ];

        foreach (self::LOOKUP_EMAILS as $email) {
            $invariants[] = IdentityMapInvariants::lookupMatchesBypass(
                "email={$email}",
                fn (): mixed => User::where('email', $email)->first()?->getKey(),
            );
        }

        foreach (self::LOOKUP_NAMES as $name) {
            $invariants[] = IdentityMapInvariants::lookupMatchesBypass(
                "name={$name}",
                fn (): mixed => User::where('name', $name)->first()?->getKey(),
            );
        }

        return $invariants;
    }

    private function pick(Context $ctx): ?User
    {
        $id = $this->pickId($ctx, User::query()->pluck('id')->all());
        $user = $id === null ? null : User::find($id);

        return $user instanceof User ? $user : null;
    }
}
