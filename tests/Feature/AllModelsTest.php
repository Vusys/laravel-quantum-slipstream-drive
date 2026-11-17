<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\AllModels;
use Vusys\QuantumSlipstreamDrive\Query\IdentityMapBuilder;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\OwnBuilderCountry;
use Vusys\QuantumSlipstreamDrive\Tests\Models\PlainCountry;
use Vusys\QuantumSlipstreamDrive\Tests\Models\PlainUser;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class AllModelsTest extends TestCase
{
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('quantum-slipstream-drive.all_models.enabled', true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        AllModels::uninstall();
    }

    private function countSql(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    private function plainUser(string $name = 'Alice', ?int $countryId = null): PlainUser
    {
        $id = PlainUser::query()->insertGetId([
            'name' => $name,
            'email' => strtolower($name).'@example.com',
            'country_id' => $countryId,
        ]);
        resolve(IdentityMapStore::class)->flush();

        $user = PlainUser::query()->find($id);
        $this->assertInstanceOf(PlainUser::class, $user);

        return $user;
    }

    #[Test]
    public function a_model_without_the_trait_gets_the_identity_map_builder(): void
    {
        $this->assertInstanceOf(IdentityMapBuilder::class, PlainUser::query());
    }

    #[Test]
    public function a_repeat_find_on_a_model_without_the_trait_issues_no_sql(): void
    {
        $alice = $this->plainUser();

        $again = null;
        $sql = $this->countSql(function () use ($alice, &$again): void {
            $again = PlainUser::query()->find($alice->id);
        });

        $this->assertSame(0, $sql);
        $this->assertSame($alice, $again);
    }

    #[Test]
    public function a_where_key_first_is_served_too(): void
    {
        $alice = $this->plainUser();

        $sql = $this->countSql(function () use ($alice): void {
            $this->assertSame($alice, PlainUser::query()->where('id', $alice->id)->first());
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function a_save_keeps_the_map_in_step(): void
    {
        $alice = $this->plainUser();
        $alice->update(['score' => 7]);

        $this->assertSame(7, PlainUser::query()->where('score', 7)->first()?->score);
        $this->assertNull(PlainUser::query()->whereNull('score')->first());
    }

    #[Test]
    public function a_raw_write_invalidates_the_cached_model(): void
    {
        $alice = $this->plainUser();

        DB::table('users')->where('id', $alice->id)->update(['name' => 'Alicia']);

        $this->assertSame('Alicia', PlainUser::query()->find($alice->id)?->name);
    }

    #[Test]
    public function a_delete_is_seen_by_the_next_find(): void
    {
        $alice = $this->plainUser();
        $alice->forceDelete();

        $this->assertNull(PlainUser::query()->find($alice->id));
    }

    #[Test]
    public function a_soft_delete_and_restore_are_seen_by_the_next_find(): void
    {
        $alice = $this->plainUser();

        $alice->delete();
        $this->assertNull(PlainUser::query()->find($alice->id));

        $alice->restore();
        $restored = PlainUser::query()->find($alice->id);
        $this->assertInstanceOf(PlainUser::class, $restored);
    }

    #[Test]
    public function a_lazy_belongs_to_shared_by_two_models_loads_once(): void
    {
        $countryId = PlainCountry::query()->insertGetId(['name' => 'Iceland']);
        $alice = $this->plainUser('Alice', $countryId);
        $bob = $this->plainUser('Bob', $countryId);

        $this->assertSame('Iceland', $alice->country?->name);

        $sql = $this->countSql(function () use ($bob): void {
            $this->assertSame('Iceland', $bob->country?->name);
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function a_model_with_its_own_builder_is_left_alone(): void
    {
        $this->assertNotInstanceOf(IdentityMapBuilder::class, OwnBuilderCountry::query());

        $id = OwnBuilderCountry::query()->insertGetId(['name' => 'Iceland']);
        OwnBuilderCountry::query()->find($id);

        $sql = $this->countSql(function () use ($id): void {
            OwnBuilderCountry::query()->find($id);
        });

        $this->assertSame(1, $sql);
    }

    #[Test]
    public function trait_models_work_as_before(): void
    {
        $alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        resolve(IdentityMapStore::class)->flush();
        User::query()->find($alice->id);

        $sql = $this->countSql(function () use ($alice): void {
            User::query()->find($alice->id);
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function uninstall_puts_the_default_builder_back(): void
    {
        AllModels::uninstall();

        $this->assertSame(Builder::class, PlainUser::query()::class);
    }

    #[Test]
    public function a_default_builder_someone_else_replaced_is_kept(): void
    {
        AllModels::uninstall();
        $this->setDefaultBuilder(OtherBuilder::class);

        try {
            AllModels::install(resolve('events'));

            $this->assertSame(OtherBuilder::class, PlainUser::query()::class);
        } finally {
            $this->setDefaultBuilder(Builder::class);
        }
    }

    /** @param class-string<Builder<Model>> $class */
    private function setDefaultBuilder(string $class): void
    {
        \Closure::bind(static function () use ($class): void {
            Model::$builder = $class;
        }, null, Model::class)();
    }
}

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class OtherBuilder extends Builder {}
