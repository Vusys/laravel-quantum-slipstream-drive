<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\AttributeFact;
use Vusys\QueryRicerExtreme\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class IdentityMapTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    #[Test]
    public function service_provider_loads(): void
    {
        $this->assertNotNull($this->app);
        $this->assertInstanceOf(IdentityMapStore::class, resolve(IdentityMapStore::class));
    }

    #[Test]
    public function find_returns_same_instance(): void
    {
        $userA = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $userB = User::find($userA->id);
        $userC = User::find($userA->id);

        $this->assertSame($userB, $userC);
    }

    #[Test]
    public function find_returns_same_instance_as_created(): void
    {
        $created = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $found = User::find($created->id);

        $this->assertSame($created, $found);
    }

    #[Test]
    public function second_find_issues_no_query(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);
        User::find($user->id);

        $this->assertSame(0, $queryCount, 'Second find should issue no SQL');
    }

    #[Test]
    public function where_key_first_returns_same_instance(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $fromWhereKey = User::query()->whereKey($user->id)->first();

        $this->assertSame($user, $fromWhereKey);
    }

    #[Test]
    public function where_key_first_issues_no_query_after_find(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->whereKey($user->id)->first();

        $this->assertSame(0, $queryCount, 'whereKey()->first() should not issue SQL when model is mapped');
    }

    #[Test]
    public function find_returns_null_for_nonexistent_id(): void
    {
        $result = User::find(9999);

        $this->assertNull($result);
    }

    #[Test]
    public function find_nonexistent_id_is_tracked_as_absent(): void
    {
        User::find(9999);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find(9999);

        $this->assertNull($result);
        $this->assertSame(0, $queryCount, 'Second find for absent key should issue no SQL');
    }

    #[Test]
    public function where_key_first_tracks_absent_key(): void
    {
        User::query()->whereKey(9999)->first();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find(9999);

        $this->assertNull($result);
        $this->assertSame(0, $queryCount, 'find() after whereKey miss should use absent tracking');
    }

    #[Test]
    public function flush_clears_all_entries(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user = User::find(1);
        $this->assertInstanceOf(User::class, $user);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after flush should issue SQL');
    }

    #[Test]
    public function flush_for_model_class_only_clears_that_class(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->flush(User::class);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after model-class flush should issue SQL');
    }

    #[Test]
    public function forget_removes_specific_model(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->forget($user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after forget should issue SQL');
    }

    #[Test]
    public function without_identity_map_bypasses_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->withoutIdentityMap()->find($user->id);

        $this->assertSame(1, $queryCount, 'withoutIdentityMap() find should always issue SQL');
    }

    #[Test]
    public function without_identity_map_does_not_affect_subsequent_finds(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        User::query()->withoutIdentityMap()->find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(0, $queryCount, 'withoutIdentityMap() should not disable the store globally');
    }

    #[Test]
    public function disabled_scope_bypasses_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->store->disabled(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertSame(1, $queryCount, 'disabled() scope should always issue SQL');
    }

    #[Test]
    public function disabled_scope_restores_store_after_callback(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->disabled(function (): void {});

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(0, $queryCount, 'Store should be re-enabled after disabled() callback completes');
    }

    #[Test]
    public function saved_model_updates_entry(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $found = User::find($user->id);
        $this->assertInstanceOf(User::class, $found);

        $found->name = 'Alicia';
        $found->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $again = User::find($user->id);
        $this->assertInstanceOf(User::class, $again);

        $this->assertSame(0, $queryCount, 'Find after save should still use map');
        $this->assertSame('Alicia', $again->name);
    }

    #[Test]
    public function soft_deleted_model_returns_null_from_default_scope(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $user->delete();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($user->id);

        $this->assertNull($result);
    }

    #[Test]
    public function restored_model_is_served_from_map_without_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user->delete();
        $user->restore();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($user->id);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(0, $queryCount, 'Restored model should be served from map without SQL');
    }

    #[Test]
    public function force_deleted_model_is_not_served_from_map(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $id = $user->id;
        User::find($id);

        $user->forceDelete();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($id);

        $this->assertNull($result);
        $this->assertSame(1, $queryCount, 'Force-deleted model should not be served from map');
    }

    #[Test]
    public function explain_returns_explanation_for_memory_hit(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $explanations = $this->store->explain(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('return_model_from_memory', $explanations[0]->type->value);
        $this->assertFalse($explanations[0]->sqlExecuted);
    }

    #[Test]
    public function explain_returns_explanation_for_sql_execution(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->store->flush();

        $explanations = $this->store->explain(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('execute_normally', $explanations[0]->type->value);
        $this->assertTrue($explanations[0]->sqlExecuted);
    }

    #[Test]
    public function explain_restores_capturing_state_after_callback(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $outer = $this->store->explain(function () use ($user): void {
            // Inner explain consumes its own events and restores captured to empty.
            $inner = $this->store->explain(function () use ($user): void {
                User::find($user->id);
            });
            $this->assertCount(1, $inner);
            // This find is captured by the outer explain (inner already finished).
            User::find($user->id);
        });

        $this->assertCount(1, $outer);
        $this->assertFalse($this->store->isCapturing(), 'Capturing state should be false after explain() returns');
    }

    #[Test]
    public function merge_from_saved_resets_provenance_and_dirty_flag(): void
    {
        $knowledge = new AttributeKnowledge;
        $stale = new AttributeFact(
            column: 'name',
            originalValue: 'Old',
            currentValue: 'Dirty',
            isDirty: true,
            confidence: FactConfidence::Assumed,
            source: FactSource::AssignedInMemory,
        );
        $knowledge->set('name', $stale);

        $user = User::create(['name' => 'Saved', 'email' => 'saved@example.com']);
        $knowledge->mergeFromSaved($user);

        $fact = $knowledge->get('name');
        $this->assertNotNull($fact);
        $this->assertFalse($fact->isDirty);
        $this->assertSame(FactConfidence::Certain, $fact->confidence);
        $this->assertSame(FactSource::HydratedFromDatabase, $fact->source);
        $this->assertSame('Saved', $fact->currentValue);
    }

    #[Test]
    public function store_is_singleton(): void
    {
        $storeA = resolve(IdentityMapStore::class);
        $storeB = resolve(IdentityMapStore::class);

        $this->assertSame($storeA, $storeB);
    }
}
