<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Store;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Vusys\QuantumSlipstreamDrive\QuantumSlipstreamDriveServiceProvider;
use Vusys\QuantumSlipstreamDrive\Query\ModelMetadata;
use Vusys\QuantumSlipstreamDrive\Query\ScopeFingerprinter;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

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
        config(['quantum-slipstream-drive.store_caps.max_entries' => 2]);
        app()->forgetInstance(IdentityMapStore::class);

        $store = resolve(IdentityMapStore::class);

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 3, 'fp');

        $this->assertSame(0, $store->debugStats()['absent'], 'config value flows through capValue() into the constructor');
    }

    #[Test]
    public function promoting_an_absent_key_to_an_entry_at_full_budget_does_not_flush(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $keeper = User::create(['name' => 'Keeper', 'email' => 'keeper@example.com']);
        $promote = User::create(['name' => 'Promote', 'email' => 'promote@example.com']);

        // Fill the budget: one live entry + one absent marker for $promote's key.
        $store->remember($keeper);
        $store->recordAbsent(
            ModelMetadata::connection($promote),
            User::class,
            ModelMetadata::table($promote),
            $promote->getKeyName(),
            $promote->id,
            ScopeFingerprinter::fromModel($promote),
        );
        $this->assertSame(1, $store->debugStats()['entries']);
        $this->assertSame(1, $store->debugStats()['absent']);

        // Remembering $promote converts absent → entry: net-zero, so no flush.
        $store->remember($promote);

        $this->assertSame(2, $store->debugStats()['entries'], 'promotion is not growth and must not flush');
        $this->assertSame(0, $store->debugStats()['absent'], 'the absent marker is consumed by the promotion');
    }

    #[Test]
    public function the_unique_key_index_flushes_on_its_own_cap(): void
    {
        // Each remembered user contributes at least one unique-key fingerprint
        // (the email unique index), so five users would push the index past a
        // cap of 3 if it were unbounded.
        $store = new IdentityMapStore(null, maxEntries: null, maxUniqueKeys: 3);

        foreach (range(1, 5) as $i) {
            $store->remember(User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]));
        }

        $this->assertLessThanOrEqual(3, $store->debugStats()['unique_index'], 'the unique-key index must never exceed its own cap');
        $this->assertSame(5, $store->debugStats()['entries'], 'flushing the unique index leaves the entry store untouched');
    }

    #[Test]
    public function a_malformed_cap_value_falls_back_to_the_default_rather_than_disabling(): void
    {
        $provider = new QuantumSlipstreamDriveServiceProvider(app());
        $capValue = new ReflectionMethod($provider, 'capValue');

        config(['quantum-slipstream-drive.store_caps.probe' => 'not-a-number']);
        $this->assertSame(100000, $capValue->invoke($provider, 'quantum-slipstream-drive.store_caps.probe', 100000), 'a typo must not silently disable the cap');

        config(['quantum-slipstream-drive.store_caps.probe' => '0']);
        $this->assertNull($capValue->invoke($provider, 'quantum-slipstream-drive.store_caps.probe', 100000), 'a literal 0 disables the cap on purpose');

        config(['quantum-slipstream-drive.store_caps.probe' => '250']);
        $this->assertSame(250, $capValue->invoke($provider, 'quantum-slipstream-drive.store_caps.probe', 100000), 'a numeric string is parsed');

        config(['quantum-slipstream-drive.store_caps.probe' => 5000]);
        $this->assertSame(5000, $capValue->invoke($provider, 'quantum-slipstream-drive.store_caps.probe', 100000), 'an int passes through');
    }
}
