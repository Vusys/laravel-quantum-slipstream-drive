<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class RawReadServingDisabledTest extends TestCase
{
    #[Test]
    public function raw_read_of_a_warmed_row_still_issues_sql_when_disabled(): void
    {
        $store = resolve(IdentityMapStore::class);
        $store->flush();

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $store->flush();

        // Warm the identity map through Eloquent — with the feature enabled this
        // snapshot would let the raw read below issue zero SQL.
        User::find($alice->id);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        DB::table('users')->find($alice->id);

        $this->assertSame(1, $count, 'with raw_reads disabled a raw read must always issue SQL');
    }
}
