<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Gadget;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: accessor-shadowed column under edits (#108).
 *
 * Gadget's `code` accessor upper-cases the stored (lower-case) value, and `label`
 * is an appended computed attribute. Two properties must hold together as the
 * column churns: an engine read must apply the accessor identically to a bypassed
 * read (the identity oracle over code/label), and a predicate over `code` must
 * match against the *stored* lower-case value, not the accessor's upper-case one
 * (the id-set oracle). A memory-served where('code', 'g1') that compared the
 * accessor output would silently diverge from SQL — this trail forces that split
 * after every edit.
 */
final class AccessorJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'code', 'qty', 'label'];

    /** @var non-empty-list<string> */
    private const array CODES = ['g1', 'g2', 'g3', 'widget', 'sprocket'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $id = $this->pickGadgetId($ctx);
                    if ($id !== null) {
                        Gadget::find($id);
                    }
                }),

            Step::make('record code region')
                ->repeatable(max: 4)
                ->act(fn (Context $ctx): mixed => Gadget::where('code', $ctx->pick(self::CODES))->get()),

            Step::make('record qty region')
                ->repeatable(max: 4)
                ->act(fn (): mixed => Gadget::where('qty', '>', 5)->get()),

            Step::make('mutate code')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $gadget = $this->pick($ctx);
                    if ($gadget instanceof Gadget) {
                        // code is an accessor-shadowed column (read-only Attribute),
                        // so write the raw value through setAttribute.
                        $gadget->setAttribute('code', $ctx->pick(self::CODES));
                        $gadget->save();
                    }
                }),

            Step::make('mutate qty')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $gadget = $this->pick($ctx);
                    if ($gadget instanceof Gadget) {
                        $gadget->qty = $ctx->randomInt(0, 20);
                        $gadget->save();
                    }
                }),

            Step::make('create gadget')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    Gadget::create([
                        'code' => $ctx->pick(self::CODES),
                        'qty' => $ctx->randomInt(0, 20),
                    ]);
                }),

            Step::make('delete gadget')
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
            IdentityMapInvariants::readsMatchBypass(Gadget::class, self::COLUMNS),
            IdentityMapInvariants::queryMatchesBypass(
                'code = g1 (stored, not accessor)',
                fn (): mixed => Gadget::where('code', 'g1')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'code = widget',
                fn (): mixed => Gadget::where('code', 'widget')->get()->all(),
                self::COLUMNS,
            ),
            IdentityMapInvariants::queryMatchesBypass(
                'qty > 5',
                fn (): mixed => Gadget::where('qty', '>', 5)->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function pick(Context $ctx): ?Gadget
    {
        $id = $this->pickGadgetId($ctx);
        $gadget = $id === null ? null : Gadget::find($id);

        return $gadget instanceof Gadget ? $gadget : null;
    }

    private function pickGadgetId(Context $ctx): ?int
    {
        return $this->pickId($ctx, Gadget::query()->pluck('id')->all());
    }
}
