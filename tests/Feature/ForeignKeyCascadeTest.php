<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Country;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Label;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Profile;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A delete changes other tables' rows through foreign-key ON DELETE actions,
 * and no model event reports it. The test schema has `users.country_id ... ON
 * DELETE SET NULL` and `profiles.user_id ... ON DELETE CASCADE`.
 */
final class ForeignKeyCascadeTest extends TestCase
{
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('fk_children');
        Schema::dropIfExists('fk_parents');
        Schema::dropIfExists('fk_grands');

        Schema::create('fk_grands', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('fk_parents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fk_grand_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('fk_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fk_parent_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The test SQLite connection doesn't enforce foreign keys by default.
        Schema::enableForeignKeyConstraints();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Schema::dropIfExists('fk_children');
        Schema::dropIfExists('fk_parents');
        Schema::dropIfExists('fk_grands');

        parent::tearDown();
    }

    private function cachedUserIn(Country $country): User
    {
        $user = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user->country_id = $country->id;
        $user->save();

        return $this->readBack($user);
    }

    #[Test]
    public function a_model_delete_that_nulls_a_child_key_is_seen(): void
    {
        $country = $this->readBack(Country::query()->create(['name' => 'Iceland']));
        $user = $this->cachedUserIn($country);

        $country->delete();

        $this->assertNull(User::query()->find($user->id)?->country_id);
    }

    #[Test]
    public function a_mass_delete_that_nulls_a_child_key_is_seen(): void
    {
        $country = $this->readBack(Country::query()->create(['name' => 'Iceland']));
        $user = $this->cachedUserIn($country);

        Country::query()->whereKey($country->id)->delete();

        $this->assertNull(User::query()->find($user->id)?->country_id);
    }

    #[Test]
    public function a_raw_delete_that_nulls_a_child_key_is_seen(): void
    {
        $country = $this->readBack(Country::query()->create(['name' => 'Iceland']));
        $user = $this->cachedUserIn($country);

        DB::table('countries')->where('id', $country->id)->delete();

        $this->assertNull(User::query()->find($user->id)?->country_id);
    }

    #[Test]
    public function a_cascaded_child_row_is_gone(): void
    {
        $user = $this->readBack(User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']));
        $profile = $this->readBack(Profile::query()->create(['user_id' => $user->id, 'headline' => 'Hi']));

        $user->forceDelete();

        $this->assertNull(Profile::query()->find($profile->id));
        $this->assertSame(0, Profile::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_cascade_two_tables_away_is_seen(): void
    {
        $grand = FkGrand::query()->create();
        $parent = FkParent::query()->create(['fk_grand_id' => $grand->id]);
        $child = $this->readBack(FkChild::query()->create(['fk_parent_id' => $parent->id]));

        $grand->delete();

        $this->assertNull(FkChild::query()->find($child->id)?->fk_parent_id);
    }

    #[Test]
    public function a_delete_no_foreign_key_acts_on_keeps_the_cache(): void
    {
        $user = $this->readBack(User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']));
        Label::query()->create(['name' => 'php'])->delete();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->assertSame($user, User::query()->find($user->id));
        $this->assertSame(0, $count);
    }
}

/** @property int $id */
final class FkGrand extends Model
{
    use HasIdentityMap;

    public $timestamps = false;

    protected $table = 'fk_grands';
}

/**
 * @property int $id
 * @property int $fk_grand_id
 */
final class FkParent extends Model
{
    use HasIdentityMap;

    public $timestamps = false;

    protected $table = 'fk_parents';

    /** @var list<string> */
    protected $fillable = ['fk_grand_id'];
}

/**
 * @property int $id
 * @property int|null $fk_parent_id
 */
final class FkChild extends Model
{
    use HasIdentityMap;

    public $timestamps = false;

    protected $table = 'fk_children';

    /** @var list<string> */
    protected $fillable = ['fk_parent_id'];
}
