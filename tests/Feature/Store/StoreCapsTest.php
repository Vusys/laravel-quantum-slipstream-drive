<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Store;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Vusys\QuantumSlipstreamDrive\Coverage\ColumnSet;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageEntry;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Predicate\ComparisonNode;
use Vusys\QuantumSlipstreamDrive\QuantumSlipstreamDriveServiceProvider;
use Vusys\QuantumSlipstreamDrive\Query\ModelMetadata;
use Vusys\QuantumSlipstreamDrive\Query\ScopeFingerprinter;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * IdentityMapStore is unbounded by default; under a configured cap a breach
 * sheds the least-recently-touched slice of $entries + $absent (never the whole
 * store) and then stores the new key. Eviction resolves the CoverageRegistry
 * from the container to prune regions referencing evicted rows, so these run
 * booted rather than as pure unit tests.
 */
final class StoreCapsTest extends TestCase
{
    #[Test]
    public function remembered_entries_accumulate_up_to_the_cap_then_evict_the_oldest(): void
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
        $this->assertSame(2, $store->debugStats()['entries'], 'the breach evicts the coldest entry, then stores the new one');
        $this->assertNull($store->findEntry($a), 'the least-recently-touched entry is the one evicted');
        $this->assertNotNull($store->findEntry($b));
        $this->assertNotNull($store->findEntry($c));
    }

    #[Test]
    public function a_recently_touched_entry_survives_the_cap_breach(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $a = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $b = User::create(['name' => 'B', 'email' => 'b@example.com']);
        $c = User::create(['name' => 'C', 'email' => 'c@example.com']);

        $store->remember($a);
        $store->remember($b);

        // Touching A makes B the least recently used.
        $store->findEntry($a);

        $store->remember($c);

        $this->assertNotNull($store->findEntry($a), 'the hot entry survives');
        $this->assertNull($store->findEntry($b), 'the cold entry is evicted');
    }

    #[Test]
    public function absent_markers_count_toward_the_same_cap(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');
        $this->assertSame(2, $store->debugStats()['absent']);

        $store->recordAbsent('default', User::class, 'users', 'id', 3, 'fp');
        $this->assertSame(2, $store->debugStats()['absent'], 'absent markers trip the same cap; the coldest is evicted');
        $this->assertFalse($store->isAbsent('default', User::class, 'users', 'id', 1, 'fp'));
        $this->assertTrue($store->isAbsent('default', User::class, 'users', 'id', 2, 'fp'));
        $this->assertTrue($store->isAbsent('default', User::class, 'users', 'id', 3, 'fp'));
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

        // The combined budget is now full; the next new key trips the cap and
        // evicts the coldest key regardless of which map holds it — here A's
        // live entry, remembered before the absence was recorded.
        $store->recordAbsent('default', User::class, 'users', 'id', 1000, 'fp');
        $this->assertSame(0, $store->debugStats()['entries']);
        $this->assertSame(2, $store->debugStats()['absent']);
        $this->assertNull($store->findEntry($a));
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

        $this->assertSame(2, $store->debugStats()['absent'], 'config value flows through capValue() into the constructor');
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
        $store = new IdentityMapStore(null, maxUniqueKeys: 3);

        foreach (range(1, 5) as $i) {
            $store->remember(User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]));
        }

        $this->assertLessThanOrEqual(3, $store->debugStats()['unique_index'], 'the unique-key index must never exceed its own cap');
        $this->assertSame(5, $store->debugStats()['entries'], 'flushing the unique index leaves the entry store untouched');
    }

    #[Test]
    public function sustained_writes_far_past_the_cap_never_exceed_the_configured_limit(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 5);

        $peak = 0;
        foreach (range(1, 500) as $i) {
            $store->recordAbsent('default', User::class, 'users', 'id', $i, 'fp');
            $stats = $store->debugStats();
            $entries = $stats['entries'];
            $absent = $stats['absent'];

            if (is_int($entries) && is_int($absent)) {
                $peak = max($peak, $entries + $absent);
            }
        }

        $this->assertLessThanOrEqual(5, $peak, 'the combined budget is never breached under sustained load');
    }

    #[Test]
    public function an_evicted_absence_marker_clears_cleanly_and_never_masks_a_real_row(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        // Overflow the cap so the coldest marker is evicted.
        $store->recordAbsent('default', User::class, 'users', 'id', 1, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 2, 'fp');
        $store->recordAbsent('default', User::class, 'users', 'id', 3, 'fp');

        $this->assertFalse(
            $store->isAbsent('default', User::class, 'users', 'id', 1, 'fp'),
            'the evicted absence must not linger — a row inserted for id=1 must not be hidden by a stale marker',
        );
    }

    #[Test]
    public function sustained_churn_evicts_untouched_entries_rather_than_serving_them_stale(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 3);

        $target = User::create(['name' => 'Target', 'email' => 'target@example.com']);
        $store->remember($target, true);
        $this->assertNotNull($store->findEntry($target), 'entry is live before the cap trips');

        // Overflow the cap repeatedly without ever touching $target again.
        foreach (range(1, 5) as $i) {
            $store->remember(User::create(['name' => "Filler{$i}", 'email' => "filler{$i}@example.com"]), true);
        }

        $this->assertNull(
            $store->findEntry($target),
            'the untouched entry becomes the coldest and is evicted under churn',
        );
        $this->assertLessThanOrEqual(3, $store->debugStats()['entries']);
    }

    #[Test]
    public function evicting_an_entry_prunes_coverage_regions_that_reference_it(): void
    {
        $store = new IdentityMapStore(null, maxEntries: 2);

        // Created up front: a create fires model events that flush class
        // coverage through the lifecycle hooks, which would mask the pruning
        // under test.
        $a = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $b = User::create(['name' => 'B', 'email' => 'b@example.com']);
        $c = User::create(['name' => 'C', 'email' => 'c@example.com']);

        $store->remember($a);
        $store->remember($b);

        $registry = resolve(CoverageRegistry::class);
        $registry->flush();
        $registry->record($this->coverageOver($a));
        $registry->record($this->coverageOver($b));

        // Breaching the cap evicts A's entry; the region built on it could
        // never serve again and must be pruned with it, while B's survives.
        $store->remember($c);

        $this->assertNull($store->findEntry($a));
        $this->assertSame(1, $registry->entryCount(), 'only the region referencing the evicted row is dropped');
        $this->assertNotNull($registry->findCovering(
            User::class,
            ModelMetadata::connection($b),
            ModelMetadata::table($b),
            ScopeFingerprinter::fromModel($b),
            new ComparisonNode('id', '=', $b->id),
        ));
    }

    private function coverageOver(User $user): CoverageEntry
    {
        return new CoverageEntry(
            modelClass: User::class,
            connection: ModelMetadata::connection($user),
            table: ModelMetadata::table($user),
            scopeFingerprint: ScopeFingerprinter::fromModel($user),
            region: new ComparisonNode('id', '=', $user->id),
            columns: new ColumnSet(['*']),
            primaryKeys: [$user->id],
            complete: true,
            version: 1,
        );
    }

    #[Test]
    public function unique_index_stays_within_cap_across_repeated_flushes(): void
    {
        $store = new IdentityMapStore(null, maxUniqueKeys: 3);

        $peak = 0;
        foreach (range(1, 100) as $i) {
            $store->remember(User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]), true);
            $peak = max($peak, $store->debugStats()['unique_index']);
        }

        $this->assertLessThanOrEqual(3, $peak, 'the unique-key index never exceeds its cap no matter how many flush cycles pass');
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
