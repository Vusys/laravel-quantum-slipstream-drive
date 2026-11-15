<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A row just inserted carries only the columns it was given. The database's
 * defaults (and nulls) are only known once the row is read back, so a model
 * fresh from create() must not be served as if it were the whole row.
 */
final class CreatedModelDefaultsTest extends TestCase
{
    #[Test]
    public function a_column_filled_by_its_database_default_is_read_back(): void
    {
        $id = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test'])->id;

        $found = User::query()->find($id);

        $this->assertInstanceOf(User::class, $found);
        $this->assertTrue($found->active, 'users.active defaults to true in the database.');
        $this->assertArrayHasKey('bio', $found->getAttributes(), 'A nullable column the insert left out is part of the row.');
    }

    #[Test]
    public function once_read_back_the_row_is_served_from_memory(): void
    {
        $id = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test'])->id;
        User::query()->find($id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::query()->find($id);
        $this->assertSame(0, $queries);
    }
}
