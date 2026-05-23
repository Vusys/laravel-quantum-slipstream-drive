<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\Models\UuidUser;
use Vusys\QueryRicerExtreme\Tests\TestCase;

#[Group('comprehensive')]
final class PkTypeTest extends TestCase
{
    /** @return array<string, array{class-string<Model>}> */
    public static function pkModelProvider(): array
    {
        return [
            'integer pk (User)' => [User::class],
            'uuid pk (UuidUser)' => [UuidUser::class],
        ];
    }

    /**
     * Creates a model of the given class with a unique email.
     * For UUID models the caller must supply the UUID id.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $overrides
     */
    private function createModel(string $modelClass, array $overrides = []): Model
    {
        $email = 'test-'.Str::random(8).'@example.com';
        $attrs = array_merge(['name' => 'Test', 'email' => $email], $overrides);

        if ($modelClass === UuidUser::class && ! isset($attrs['id'])) {
            $attrs['id'] = (string) Str::uuid();
        }

        return $modelClass::create($attrs);
    }

    /**
     * Returns a key that is guaranteed not to exist in the DB for this model class.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function missingKey(string $modelClass): int|string
    {
        return $modelClass === UuidUser::class ? (string) Str::uuid() : 999_999_999;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('pkModelProvider')]
    public function test_find_returns_same_instance_on_second_call(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        resolve(IdentityMapStore::class)->flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $first = $modelClass::find($model->getKey());
        $second = $modelClass::find($model->getKey());

        $this->assertSame(1, $queries);
        $this->assertSame($first, $second);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('pkModelProvider')]
    public function test_key_set_partial_hit_issues_only_one_sql(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        resolve(IdentityMapStore::class)->flush();

        // Warm exactly one key
        $modelClass::find($model->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        // Querying cached key + unknown key → one SQL for the unknown key only
        $results = $modelClass::whereKey([$model->getKey(), $this->missingKey($modelClass)])->get();

        $this->assertSame(1, $queries);
        $this->assertCount(1, $results);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('pkModelProvider')]
    public function test_absence_tracking_prevents_second_sql(string $modelClass): void
    {
        resolve(IdentityMapStore::class)->flush();

        $unknownKey = $this->missingKey($modelClass);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $first = $modelClass::find($unknownKey);
        $second = $modelClass::find($unknownKey);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $queries);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('pkModelProvider')]
    public function test_soft_delete_causes_subsequent_finds_to_skip_sql(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $key = $model->getKey();

        resolve(IdentityMapStore::class)->flush();
        $modelClass::find($key); // warm cache

        $model->delete();

        // First find after soft-delete: entry is marked SoftDeleted, falls through to SQL,
        // records absent, returns null.
        $afterDelete = $modelClass::find($key);
        $this->assertNull($afterDelete);

        // Second find: served from absence record, no SQL.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::find($key);
        $this->assertNull($result);
        $this->assertSame(0, $queries);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('pkModelProvider')]
    public function test_force_delete_clears_entry_so_next_find_queries_db(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $key = $model->getKey();

        resolve(IdentityMapStore::class)->flush();
        $modelClass::find($key); // warm cache

        $model->forceDelete();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::find($key);

        $this->assertNull($result);
        // Force-delete doesn't record absent, so we go to SQL once
        $this->assertSame(1, $queries);
    }
}
