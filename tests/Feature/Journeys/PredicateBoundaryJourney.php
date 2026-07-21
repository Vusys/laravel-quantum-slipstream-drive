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
 * Journey: predicate-boundary membership under edits (#108).
 *
 * Coverage (#97) recorded regions with equality / comparison predicates only.
 * This one records Between / In / Like / Or / Null / NotBetween regions and then
 * deliberately moves rows *across* their boundaries — a score nudged from 49 to
 * 51 for a `> 50` half of an Or, a rename that enters or leaves `LIKE 'a%'`, a
 * bio nulled or filled for an `IS NULL` region. After every step each covered
 * predicate is re-run through the engine and compared to a map-disabled read, so
 * a SubsetChecker that keeps a row in a region it just left (or drops one it just
 * entered) is caught whatever order the edits land in.
 */
final class PredicateBoundaryJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active', 'score', 'bio'];

    /** @var non-empty-list<int> */
    private const array BOUNDARY_SCORES = [0, 25, 39, 40, 41, 49, 50, 51, 59, 60, 61, 75, 80, 81, 100];

    /** @var non-empty-list<string> */
    private const array NAMES = ['apple', 'avocado', 'apricot', 'banana', 'cherry', 'date'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('record between region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::whereBetween('score', [40, 60])->get()),

            Step::make('record in region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::whereIn('score', [0, 25, 50, 75, 100])->get()),

            Step::make('record like region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::where('name', 'like', 'a%')->get()),

            Step::make('record null-bio region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::whereNull('bio')->get()),

            Step::make('record or region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => User::where('active', true)->orWhere('score', '>', 80)->get()),

            Step::make('nudge score across boundary')
                ->repeatable(max: 8)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->score = $ctx->pick(self::BOUNDARY_SCORES);
                        $user->save();
                    }
                }),

            Step::make('rename across like boundary')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->name = $ctx->pick(self::NAMES);
                        $user->save();
                    }
                }),

            Step::make('toggle null bio')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->bio = $ctx->randomInt(0, 1) === 1 ? null : 'bio-'.$ctx->randomInt(1, 1_000_000);
                        $user->save();
                    }
                }),

            Step::make('toggle active')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->active = ! $user->active;
                        $user->save();
                    }
                }),

            Step::make('null score')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $user = $this->pick($ctx);
                    if ($user instanceof User) {
                        $user->score = null;
                        $user->save();
                    }
                }),

            Step::make('create row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    User::create([
                        'name' => $ctx->pick(self::NAMES).'-'.$seq,
                        'email' => "boundary-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                        'score' => $ctx->pick(self::BOUNDARY_SCORES),
                        'bio' => $ctx->randomInt(0, 1) === 1 ? null : 'seed-bio',
                    ]);
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
                'score between 40 and 60',
                fn (): mixed => User::whereBetween('score', [40, 60])->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'score not between 40 and 60',
                fn (): mixed => User::whereNotBetween('score', [40, 60])->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'score in set',
                fn (): mixed => User::whereIn('score', [0, 25, 50, 75, 100])->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'name like a%',
                fn (): mixed => User::where('name', 'like', 'a%')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'bio is null',
                fn (): mixed => User::whereNull('bio')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'active or score > 80',
                fn (): mixed => User::where('active', true)->orWhere('score', '>', 80)->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function pick(Context $ctx): ?User
    {
        $id = $this->pickId($ctx, User::query()->pluck('id')->all());
        $user = $id === null ? null : User::find($id);

        return $user instanceof User ? $user : null;
    }
}
