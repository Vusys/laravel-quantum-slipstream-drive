<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Store;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\UniqueKeyIndex;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class UniqueKeyIndexRegistrationTest extends TestCase
{
    private UniqueKeyIndex $index;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new UniqueKeyIndex;
        config(['query-ricer-extreme.models' => []]);
    }

    #[Test]
    public function register_adds_a_runtime_index(): void
    {
        $this->index->register(User::class, ['email']);

        $this->assertSame([['email']], $this->index->uniqueIndexesForModelClass(User::class));
    }

    #[Test]
    public function register_deduplicates_identical_column_sets(): void
    {
        $this->index->register(User::class, ['email']);
        $this->index->register(User::class, ['email']);
        $this->index->register(User::class, ['email']);

        $this->assertSame([['email']], $this->index->uniqueIndexesForModelClass(User::class));
    }

    #[Test]
    public function register_deduplicates_against_config_declared_indexes(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['email']]],
        ]]);

        $this->index->register(User::class, ['email']);

        $this->assertSame([['email']], $this->index->uniqueIndexesForModelClass(User::class));
    }

    #[Test]
    public function register_treats_column_order_as_irrelevant_for_dedup(): void
    {
        $this->index->register(User::class, ['tenant_id', 'slug']);
        $this->index->register(User::class, ['slug', 'tenant_id']);

        $result = $this->index->uniqueIndexesForModelClass(User::class);

        $this->assertCount(1, $result, 'Reversed column order must be deduplicated to a single index');
    }

    #[Test]
    public function register_keeps_distinct_column_sets(): void
    {
        $this->index->register(User::class, ['email']);
        $this->index->register(User::class, ['tenant_id', 'slug']);
        $this->index->register(User::class, ['handle']);

        $result = $this->index->uniqueIndexesForModelClass(User::class);

        $this->assertCount(3, $result);
        $this->assertContains(['email'], $result);
        $this->assertContains(['tenant_id', 'slug'], $result);
        $this->assertContains(['handle'], $result);
    }

    #[Test]
    public function register_preserves_config_declared_first(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['email']]],
        ]]);

        $this->index->register(User::class, ['handle']);

        $this->assertSame(
            [['email'], ['handle']],
            $this->index->uniqueIndexesForModelClass(User::class),
            'Config-declared indexes must precede registered ones',
        );
    }

    #[Test]
    public function register_ignores_empty_column_list(): void
    {
        $this->index->register(User::class, []);

        $this->assertSame([], $this->index->uniqueIndexesForModelClass(User::class));
    }

    #[Test]
    public function flush_clears_registered_indexes(): void
    {
        $this->index->register(User::class, ['email']);
        $this->index->markDiscovered(User::class);

        $this->index->flush();

        $this->assertSame([], $this->index->uniqueIndexesForModelClass(User::class));
        $this->assertFalse($this->index->hasDiscovered(User::class));
    }

    #[Test]
    public function per_class_flush_clears_only_that_class(): void
    {
        $this->index->register(User::class, ['email']);
        $this->index->register(\stdClass::class, ['ref']);
        $this->index->markDiscovered(User::class);
        $this->index->markDiscovered(\stdClass::class);

        $this->index->flush(User::class);

        $this->assertSame([], $this->index->uniqueIndexesForModelClass(User::class));
        $this->assertFalse($this->index->hasDiscovered(User::class));
        $this->assertSame([['ref']], $this->index->uniqueIndexesForModelClass(\stdClass::class));
        $this->assertTrue($this->index->hasDiscovered(\stdClass::class));
    }

    #[Test]
    public function discovery_marker_round_trips(): void
    {
        $this->assertFalse($this->index->hasDiscovered(User::class));

        $this->index->markDiscovered(User::class);

        $this->assertTrue($this->index->hasDiscovered(User::class));
    }
}
