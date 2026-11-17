<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A key-set read whose select leaves the primary key out returns rows with no
 * key. Key-set rewriting merges rows by key and records unreturned keys as
 * absent, so it used to return nothing and then report the rows as missing.
 */
final class KeySetProjectionTest extends TestCase
{
    /** @var list<int> */
    private array $ids;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ids = [
            User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com'])->id,
            User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com'])->id,
        ];
    }

    #[Test]
    public function a_key_set_select_without_the_key_returns_its_rows(): void
    {
        $this->assertSame(['Alice'], User::query()->select('name')->whereKey([$this->ids[0]])->get()->pluck('name')->all());

        resolve(IdentityMapStore::class)->flush();

        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], User::query()->select('name')->whereKey($this->ids)->get()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], User::query()->findMany($this->ids, ['name'])->pluck('name')->all());
    }

    #[Test]
    public function a_key_set_select_without_the_key_does_not_mark_the_rows_absent(): void
    {
        resolve(IdentityMapStore::class)->flush();

        User::query()->select('name')->whereKey($this->ids)->get();

        $this->assertSame('Alice', User::query()->find($this->ids[0])?->name);
        $this->assertCount(2, User::query()->findMany($this->ids));
    }

    #[Test]
    public function a_qualified_key_in_the_select_still_uses_the_map(): void
    {
        User::query()->findMany($this->ids);

        $names = User::query()->select(['users.id', 'users.name'])->whereKey($this->ids)->get()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], $names);
    }
}
