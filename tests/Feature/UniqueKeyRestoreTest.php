<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Regression tests for unique-key staleness across soft-delete lifecycle
 * transitions, surfaced by the unique-key resolution journey (#108). All three
 * are cases where a write must invalidate a previously recorded unique-key
 * absence or a stale soft-deleted entry the positive index still points at.
 */
final class UniqueKeyRestoreTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();

        config(['quantum-slipstream-drive.models' => [
            User::class => ['unique' => [['email']]],
        ]]);
    }

    private function resolveIdByEmail(string $email): mixed
    {
        $user = User::where('email', $email)->first();

        return $user instanceof User ? $user->getKey() : null;
    }

    private function resolveIdByEmailWithTrashed(string $email): mixed
    {
        $user = User::withTrashed()->where('email', $email)->first();

        return $user instanceof User ? $user->getKey() : null;
    }

    #[Test]
    public function builder_restore_clears_a_recorded_unique_absence(): void
    {
        $user = User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);
        $user->delete();

        // Look up while soft-deleted: records unique-key absence for the email.
        $this->assertNull(User::where('email', 'curie@example.com')->first());

        User::onlyTrashed()->whereKey($user->id)->restore();

        $engine = $this->resolveIdByEmail('curie@example.com');
        $bypass = IdentityMap::disabled(fn (): mixed => $this->resolveIdByEmail('curie@example.com'));

        $this->assertSame($bypass, $engine, 'restore must clear the stale unique-key absence');
        $this->assertSame($user->id, $engine);
    }

    #[Test]
    public function builder_restore_refreshes_a_stale_soft_deleted_index_entry(): void
    {
        $user = User::create(['name' => 'Curie', 'email' => 'curie@example.com', 'active' => true]);
        User::find($user->id); // warm a live entry the unique index points at

        $user->delete(); // soft-delete: entry becomes non-live but stays indexed

        User::onlyTrashed()->whereKey($user->id)->restore();

        $engine = $this->resolveIdByEmail('curie@example.com');
        $bypass = IdentityMap::disabled(fn (): mixed => $this->resolveIdByEmail('curie@example.com'));

        $this->assertSame($bypass, $engine, 'restore must not leave a soft-deleted entry behind the unique index');
        $this->assertSame($user->id, $engine);
    }

    #[Test]
    public function create_clears_absence_recorded_under_a_different_scope(): void
    {
        // Record absence under the withTrashed scope (a different scope fingerprint).
        $this->assertNull($this->resolveIdByEmailWithTrashed('ghost@example.com'));

        $ghost = User::create(['name' => 'Ghost', 'email' => 'ghost@example.com', 'active' => true]);

        $engine = $this->resolveIdByEmailWithTrashed('ghost@example.com');
        $bypass = IdentityMap::disabled(fn (): mixed => $this->resolveIdByEmailWithTrashed('ghost@example.com'));

        $this->assertSame($bypass, $engine, 'create must clear cross-scope unique absence for the value');
        $this->assertSame($ghost->id, $engine);
    }
}
