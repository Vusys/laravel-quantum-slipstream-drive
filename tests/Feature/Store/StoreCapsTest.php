<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Store;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * IdentityMapStore is unbounded by default; under a configured cap it must flush
 * itself in full once $entries + $absent reach the limit, never partially evict.
 * flush() reaches into the container (SchemaDiscovery / ScopeFingerprinter), so
 * these run booted rather than as pure unit tests.
 */
final class StoreCapsTest extends TestCase
{
    #[Test]
    public function remembered_entries_accumulate_up_to_the_cap_then_flush(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $a = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $b = User::create(['name' => 'B', 'email' => 'b@example.com']);
        $c = User::create(['name' => 'C', 'email' => 'c@example.com']);

        $store->remember($a);
        $this->assertSame(1, $store->debugStats()['entries']);

        $store->remember($b);
        $this->assertSame(2, $store->debugStats()['entries']);

        $store->remember($c);
        $this->assertSame(0, $store->debugStats()['entries'], 'store flushes when the cap is reached');
    }

    #[Test]
    public function absent_markers_count_toward_the_same_cap(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');
        $this->assertSame(2, $store->debugStats()['absent']);

        $store->recordAbsent('default', User::class, 'users', 'id', 3, 'fp');
        $this->assertSame(0, $store->debugStats()['absent'], 'absent markers trip the same cap');
    }

    #[Test]
    public function re_recording_a_known_absent_marker_at_the_cap_does_not_flush(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');

        $this->assertSame(2, $store->debugStats()['absent'], 're-recording a known marker is not growth');
    }

    #[Test]
    public function entries_and_absent_share_one_combined_budget(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $a = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $store->remember($a);
        $store->recordAbsent('default', User::class, 'users', 'id', 999, 'fp');
        $this->assertSame(1, $store->debugStats()['entries']);
        $this->assertSame(1, $store->debugStats()['absent']);

        // The combined budget is now full; the next new key trips the cap.
        $store->recordAbsent('default', User::class, 'users', 'id', 1000, 'fp');
        $this->assertSame(0, $store->debugStats()['entries']);
        $this->assertSame(0, $store->debugStats()['absent']);
    }

    #[Test]
    public function the_default_singleton_is_unbounded(): void
    {
        $store = resolve(IdentityMapStore::class);

        foreach (range(1, 200) as $i) {
            $store->recordAbsent('default', User::class, 'users', 'id', $i, 'fp');
        }

        $this->assertSame(200, $store->debugStats()['absent'], 'the generous default cap is nowhere near 200');
    }

    #[Test]
    public function configured_cap_is_wired_through_the_service_provider(): void
    {
        config(['query-ricer-extreme.store_caps.max_entries' => 2]);
        app()->forgetInstance(IdentityMapStore::class);

        $store = resolve(IdentityMapStore::class);

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 3, 'fp');

        $this->assertSame(0, $store->debugStats()['absent'], 'config value flows through capValue() into the constructor');
    }
}
