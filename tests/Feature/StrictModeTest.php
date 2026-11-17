<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Apps that call Model::shouldBeStrict() throw on reading an attribute a query
 * didn't select. The identity map handles partial rows (value(), pluck(),
 * select()) without its primary key, so it must never ask one for its key.
 */
final class StrictModeTest extends TestCase
{
    private int $alice;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 3]);
        Post::query()->create(['user_id' => $user->id, 'title' => 'Hello', 'published' => true]);
        $this->alice = $user->id;
        resolve(IdentityMapStore::class)->flush();

        Model::preventAccessingMissingAttributes();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);
        parent::tearDown();
    }

    #[Test]
    public function value_on_a_partial_row_works(): void
    {
        $this->assertSame('Alice', User::query()->whereKey($this->alice)->value('name'));
    }

    #[Test]
    public function value_after_the_model_is_cached_works(): void
    {
        User::query()->find($this->alice);

        $this->assertSame('Alice', User::query()->whereKey($this->alice)->value('name'));
    }

    #[Test]
    public function a_select_without_the_key_works(): void
    {
        $this->assertSame(['Alice'], User::query()->select('name')->get()->pluck('name')->all());
        $this->assertSame(['Alice'], User::query()->select('name')->whereKey([$this->alice])->get()->pluck('name')->all());
    }

    #[Test]
    public function pluck_works(): void
    {
        $this->assertSame(['Alice'], User::query()->pluck('name')->all());
        $this->assertSame([3], User::query()->where('name', 'Alice')->pluck('score')->all());
    }

    #[Test]
    public function a_relation_read_of_a_partial_row_works(): void
    {
        $user = User::query()->findOrFail($this->alice);

        $this->assertSame(['Hello'], $user->posts()->select('title')->get()->pluck('title')->all());
    }
}
