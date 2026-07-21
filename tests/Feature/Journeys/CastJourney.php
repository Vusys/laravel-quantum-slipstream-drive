<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\CastSample;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Enums\SampleStatus;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: cast attributes under edits (#108).
 *
 * Every cast type the plain journeys never touch — datetime, immutable_datetime,
 * json/array, backed enum, decimal:2, encrypted — is mutated and re-read while
 * covered regions record the model. The identity oracle normalizes each cast to
 * a scalar and asserts a memory-served row equals a bypassed read across the cast
 * boundary; the id-set oracles re-run predicates over cast columns (enum, decimal,
 * datetime) so a covered region that the engine should have invalidated after a
 * cast mutation — or wrongly served after failing to prove the predicate — is
 * caught whatever order the edits land in.
 */
final class CastJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'happened_at', 'archived_at', 'payload', 'status', 'amount', 'secret'];

    /** @var non-empty-list<string> */
    private const array DATES = [
        '2026-01-01 10:00:00',
        '2026-03-15 12:30:00',
        '2026-06-30 23:59:59',
        '2026-09-09 09:09:09',
    ];

    /** @var non-empty-list<string> */
    private const array AMOUNTS = ['10.00', '19.99', '20.00', '20.01', '100.50'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickId($ctx, CastSample::query()->pluck('id')->all());
                    if ($id !== null) {
                        CastSample::find($id);
                    }
                }),

            Step::make('record status region')
                ->repeatable(max: 4)
                ->act(fn (Context $ctx): mixed => CastSample::where('status', $ctx->pick(SampleStatus::cases()))->get()),

            Step::make('record amount region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => CastSample::where('amount', '>', '20.00')->get()),

            Step::make('mutate datetime')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $sample = $this->pick($ctx);
                    if ($sample instanceof CastSample) {
                        $sample->happened_at = Date::parse($ctx->pick(self::DATES));
                        $sample->archived_at = $ctx->randomInt(0, 1) === 1 ? null : CarbonImmutable::parse($ctx->pick(self::DATES));
                        $sample->save();
                    }
                }),

            Step::make('mutate payload')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $sample = $this->pick($ctx);
                    if ($sample instanceof CastSample) {
                        $sample->payload = [
                            'tier' => $ctx->pick(['gold', 'silver', 'bronze']),
                            'n' => $ctx->randomInt(0, 9),
                        ];
                        $sample->save();
                    }
                }),

            Step::make('mutate enum status')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $sample = $this->pick($ctx);
                    if ($sample instanceof CastSample) {
                        $sample->status = $ctx->pick(SampleStatus::cases());
                        $sample->save();
                    }
                }),

            Step::make('mutate decimal amount')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $sample = $this->pick($ctx);
                    if ($sample instanceof CastSample) {
                        $sample->amount = $ctx->pick(self::AMOUNTS);
                        $sample->save();
                    }
                }),

            Step::make('mutate encrypted secret')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $sample = $this->pick($ctx);
                    if ($sample instanceof CastSample) {
                        $sample->secret = 'secret-'.$ctx->randomInt(1, 1_000_000);
                        $sample->save();
                    }
                }),

            Step::make('create row')
                ->repeatable(max: 4)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    CastSample::create([
                        'name' => "created-{$seq}",
                        'happened_at' => Date::parse($ctx->pick(self::DATES)),
                        'archived_at' => $ctx->randomInt(0, 1) === 1 ? null : Date::parse($ctx->pick(self::DATES)),
                        'payload' => ['tier' => $ctx->pick(['gold', 'silver']), 'n' => $seq],
                        'status' => $ctx->pick(SampleStatus::cases()),
                        'amount' => $ctx->pick(self::AMOUNTS),
                        'secret' => "seed-secret-{$seq}",
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
            IdentityMapInvariants::castReadsMatchBypass(CastSample::class, self::COLUMNS),
            IdentityMapInvariants::queryMatchesBypass(
                'status = published',
                fn (): mixed => CastSample::where('status', SampleStatus::Published)->get()->all(),
                ['id'],
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'amount > 20.00',
                fn (): mixed => CastSample::where('amount', '>', '20.00')->get()->all(),
                ['id'],
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'happened_at > 2026-04-01',
                fn (): mixed => CastSample::where('happened_at', '>', Date::parse('2026-04-01 00:00:00'))->get()->all(),
                ['id'],
            ),
        ];
    }

    private function pick(Context $ctx): ?CastSample
    {
        $id = $this->pickId($ctx, CastSample::query()->pluck('id')->all());
        $sample = $id === null ? null : CastSample::find($id);

        return $sample instanceof CastSample ? $sample : null;
    }
}
