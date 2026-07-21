<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * The trivial journey that proves the scaffold runs green (#95): create a row,
 * read it back through the engine, and mutate it — with the universal
 * identity-map-consistency invariant checked after every step.
 */
final class PlaceholderJourney extends Journey
{
    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('create user')
                ->act(function (Context $ctx): void {
                    $user = User::create([
                        'name' => 'Placeholder',
                        'email' => 'placeholder-'.$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => true,
                        'score' => $ctx->randomInt(0, 100),
                    ]);
                    $ctx->remember('user_id', $user->id);
                })
                ->assert(function (Context $ctx): void {
                    $found = User::find($ctx->integer('user_id'));
                    Assert::assertInstanceOf(User::class, $found);
                }),

            Step::make('toggle active')
                ->after('create user')
                ->repeatable(max: 3)
                ->act(function (Context $ctx): void {
                    $user = User::findOrFail($ctx->integer('user_id'));
                    $user->active = ! $user->active;
                    $user->save();
                })
                ->assert(function (Context $ctx): void {
                    $found = User::find($ctx->integer('user_id'));
                    Assert::assertInstanceOf(User::class, $found);
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
}
