<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * Under Model::preventLazyLoading(), Laravel flags only models hydrated in a
 * result of more than one row. A cached instance first read in a list keeps
 * that flag, so serving it on its own must clear it, as a one-row read would.
 */
final class LazyLoadingGuardTest extends TestCase
{
    /** @var list<int> */
    private array $postIds;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->postIds = [
            Post::query()->create(['user_id' => $user->id, 'title' => 'One', 'published' => true])->id,
            Post::query()->create(['user_id' => $user->id, 'title' => 'Two', 'published' => true])->id,
        ];

        Model::preventLazyLoading();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    #[Test]
    public function a_model_first_read_in_a_list_can_lazy_load_when_found_alone(): void
    {
        $this->assertCount(2, Post::query()->whereKey($this->postIds)->get());

        $post = Post::query()->find($this->postIds[0]);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertSame('Alice', $post->user?->name);
    }

    #[Test]
    public function a_model_first_read_in_a_list_can_lazy_load_when_fetched_with_first(): void
    {
        Post::query()->get();

        $post = Post::query()->where('id', $this->postIds[1])->first();
        $this->assertInstanceOf(Post::class, $post);

        $this->assertSame('Alice', $post->user?->name);
    }

    #[Test]
    public function a_list_read_still_prevents_lazy_loading(): void
    {
        $posts = Post::query()->whereKey($this->postIds)->get();

        $this->expectException(LazyLoadingViolationException::class);
        $posts->firstOrFail()->user;
    }
}
