<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\TouchingPost;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A model's own save() runs its UPDATE through the identity-map builder, which
 * mirrors the write onto the cached instance. That's the instance being saved,
 * so the mirror must leave Eloquent's dirty state alone until save() finishes:
 * touchOwners() and getChanges() both read it.
 */
final class SaveOfCachedModelTest extends TestCase
{
    private User $user;

    private TouchingPost $post;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $id = TouchingPost::query()->create(['user_id' => $this->user->id, 'title' => 'Hello', 'published' => false])->id;

        $post = TouchingPost::query()->find($id);
        $this->assertInstanceOf(TouchingPost::class, $post);
        $this->post = $post;
    }

    #[Test]
    public function saving_a_cached_model_touches_its_owners(): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->post->update(['title' => 'Edited']);

        $this->assertNotEmpty(array_filter($sql, fn (string $s): bool => str_starts_with($s, 'update "users"') || str_starts_with($s, 'update `users`')));
    }

    #[Test]
    public function saving_a_cached_model_reports_its_changes(): void
    {
        $this->post->update(['title' => 'Edited']);

        $this->assertTrue($this->post->wasChanged('title'));
        $this->assertArrayHasKey('title', $this->post->getChanges());
        $this->assertFalse($this->post->isDirty());
    }

    #[Test]
    public function a_mass_update_keeps_another_pending_edit_on_the_cached_instance(): void
    {
        $this->post->title = 'Unsaved';

        TouchingPost::query()->whereKey($this->post->id)->update(['view_count' => 5]);

        $this->assertTrue($this->post->isDirty('title'));

        $this->post->save();

        $this->assertSame('Unsaved', TouchingPost::query()->withoutIdentityMap()->find($this->post->id)?->title);
    }
}
