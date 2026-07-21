<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\PicksIds;
use Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants\IdentityMapInvariants;
use Vusys\QuantumSlipstreamDrive\Tests\Models\UuidUser;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: non-incrementing (UUID) primary keys (#108).
 *
 * A Consistency-style trail (#96) re-run over UuidUser — string primary keys
 * instead of auto-increment ints. The identity map keys every entry by
 * connection + table + primary key and indexes uniques through UniqueKeyIndex;
 * a keying bug that only bites string PKs (an int cast that mangles the key, a
 * case-folded lookup, a UUID that round-trips differently through the store than
 * through SQL) would never show up on the int-keyed journeys. Warmed finds,
 * saves, mass and raw writes, soft-deletes and restores interleave in every
 * shuffled order, and after each step an engine read of the whole table must
 * equal a map-disabled read.
 */
final class UuidKeyJourney extends Journey
{
    use PicksIds;

    /** @var non-empty-list<string> */
    private const array COLUMNS = ['id', 'name', 'active'];

    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('warm find')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $key = $this->pickUuid($ctx);
                    if ($key !== null) {
                        UuidUser::find($key);
                    }
                }),

            Step::make('save mutation')
                ->repeatable(max: 6)
                ->act(function (Context $ctx): void {
                    $user = $this->find($this->pickUuid($ctx));
                    if ($user instanceof UuidUser) {
                        $user->active = $ctx->randomInt(0, 1) === 1;
                        $user->name = 'renamed-'.$ctx->randomInt(1, 1_000_000);
                        $user->save();
                    }
                }),

            Step::make('mass update')
                ->repeatable(max: 5)
                ->act(fn (Context $ctx): mixed => UuidUser::where('active', $ctx->randomInt(0, 1) === 1)
                    ->update(['name' => 'mass-'.$ctx->randomInt(1, 1_000_000)])),

            Step::make('raw update by key')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $key = $this->pickUuid($ctx);
                    if ($key !== null) {
                        DB::table('uuid_users')->where('id', $key)->update(['name' => 'raw-'.$ctx->randomInt(1, 1_000_000)]);
                    }
                }),

            Step::make('create row')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $seq = count($ctx->list('created'));
                    $ctx->push('created', $seq);

                    UuidUser::create([
                        'id' => $this->uuid($ctx),
                        'name' => "created-{$seq}",
                        'email' => "uuid-{$seq}-".$ctx->randomInt(1, 1_000_000).'@example.com',
                        'active' => $ctx->randomInt(0, 1) === 1,
                    ]);
                }),

            Step::make('soft delete')
                ->repeatable(max: 5)
                ->act(function (Context $ctx): void {
                    $this->find($this->pickUuid($ctx))?->delete();
                }),

            Step::make('restore')
                ->repeatable(max: 5)
                ->when(fn (): bool => UuidUser::onlyTrashed()->exists())
                ->act(function (Context $ctx): void {
                    $key = $this->pickKey($ctx, UuidUser::onlyTrashed()->pluck('id')->all());
                    if ($key !== null) {
                        UuidUser::onlyTrashed()->whereKey($key)->restore();
                    }
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            IdentityMapInvariants::readsMatchBypass(UuidUser::class, self::COLUMNS),
            IdentityMapInvariants::queryMatchesBypass(
                'active = true',
                fn (): mixed => UuidUser::where('active', true)->get()->all(),
                self::COLUMNS,
            ),
        ];
    }

    private function find(?string $key): ?UuidUser
    {
        if ($key === null) {
            return null;
        }

        $user = UuidUser::withTrashed()->whereKey($key)->first();

        return $user instanceof UuidUser ? $user : null;
    }

    private function pickUuid(Context $ctx): ?string
    {
        return $this->pickKey($ctx, UuidUser::query()->pluck('id')->all());
    }

    /**
     * A version-4-shaped UUID drawn entirely from the seeded context, so a
     * created row's key replays verbatim (Str::uuid() would not).
     */
    private function uuid(Context $ctx): string
    {
        $bytes = [];
        for ($i = 0; $i < 16; $i++) {
            $bytes[$i] = $ctx->randomInt(0, 255);
        }

        $bytes[6] = ($bytes[6] & 0x0F) | 0x40;
        $bytes[8] = ($bytes[8] & 0x3F) | 0x80;

        $hex = '';
        foreach ($bytes as $byte) {
            $hex .= sprintf('%02x', $byte);
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
