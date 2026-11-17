<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Query\SelectMemo;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class QueryMemoTest extends TestCase
{
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('quantum-slipstream-drive.query_memo.enabled', true);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 1],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false, 'score' => 2],
        ]);
    }

    /**
     * @param  \Closure(): mixed  $callback
     * @return array{int, mixed}
     */
    private function counted(\Closure $callback): array
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $result = $callback();

        return [$count, $result];
    }

    #[Test]
    public function an_identical_select_runs_once_and_returns_fresh_copies(): void
    {
        [$count, $name] = $this->counted(function (): mixed {
            $first = DB::table('users')->where('active', true)->first();
            $this->assertInstanceOf(\stdClass::class, $first);
            $first->name = 'changed by the caller';

            $second = DB::table('users')->where('active', true)->first();
            $this->assertInstanceOf(\stdClass::class, $second);

            return $second->name;
        });

        $this->assertSame(1, $count);
        $this->assertSame('Alice', $name);
    }

    #[Test]
    public function a_constrained_eloquent_query_is_remembered_too(): void
    {
        [$count, $names] = $this->counted(fn (): array => [
            User::query()->where('score', '>', 1)->pluck('name')->all(),
            User::query()->where('score', '>', 1)->pluck('name')->all(),
        ]);

        $this->assertSame(1, $count);
        $this->assertSame([['Bob'], ['Bob']], $names);
    }

    #[Test]
    public function different_bindings_are_different_selects(): void
    {
        [$count] = $this->counted(fn (): array => [
            DB::table('users')->where('score', 1)->get(),
            DB::table('users')->where('score', 2)->get(),
        ]);

        $this->assertSame(2, $count);
    }

    #[Test]
    public function any_write_forgets_everything(): void
    {
        [$count, $score] = $this->counted(function (): mixed {
            DB::table('users')->where('name', 'Alice')->value('score');
            DB::table('users')->where('name', 'Alice')->update(['score' => 9]);

            return DB::table('users')->where('name', 'Alice')->value('score');
        });

        $this->assertSame(3, $count);
        $this->assertSame(9, $score);
    }

    #[Test]
    public function a_rolled_back_savepoint_is_not_served_from_memory(): void
    {
        DB::transaction(function (): void {
            try {
                DB::transaction(function (): never {
                    DB::table('users')->where('name', 'Alice')->update(['score' => 9]);
                    $this->assertSame(9, DB::table('users')->where('name', 'Alice')->value('score'));

                    throw new \RuntimeException('roll back');
                });
            } catch (\RuntimeException) {
            }

            $this->assertSame(1, DB::table('users')->where('name', 'Alice')->value('score'));
        });
    }

    #[Test]
    public function a_select_that_reads_the_clock_or_a_random_number_always_runs(): void
    {
        // Every driver's spelling, classified without running it.
        foreach ([
            "select datetime('now') as t", 'select random() as r', 'select rand()', 'select now()', 'select uuid()',
            'select * from users where score > abs(random()) % 2', "select nextval('users_id_seq')", 'select last_insert_id()',
            'select * from users for update', 'select * from users for share', 'select * from users lock in share mode',
            'select get_lock(?, 1)', 'select pg_try_advisory_lock(1)', 'select sleep(1)', 'select @n := 1',
        ] as $sql) {
            $this->assertNull(SelectMemo::keyFor(DB::connection(), $sql, [], true, []), $sql);
        }

        $this->assertNotNull(SelectMemo::keyFor(DB::connection(), 'select * from "users" where "score" > ?', [1], true, []));

        // And one that every driver runs.
        [$count] = $this->counted(fn (): array => [DB::select('select current_timestamp as t'), DB::select('select current_timestamp as t')]);

        $this->assertSame(2, $count);
    }

    #[Test]
    public function an_entry_expires_after_the_ttl(): void
    {
        config()->set('quantum-slipstream-drive.query_memo.ttl_ms', 1);

        [$count] = $this->counted(function (): void {
            DB::table('users')->get();
            Sleep::usleep(3000);
            DB::table('users')->get();
        });

        $this->assertSame(2, $count);
    }

    #[Test]
    public function a_pretended_select_does_not_poison_the_memo(): void
    {
        DB::pretend(fn (): mixed => DB::table('users')->get());

        $this->assertCount(2, DB::table('users')->get());
    }

    #[Test]
    public function the_ways_of_asking_for_the_database_answer_run_sql(): void
    {
        $alice = User::query()->where('name', 'Alice')->firstOrFail();

        $this->assertSame(1, User::query()->where('name', 'Alice')->value('score'));

        // Written past the connection, as another process would: nothing here sees it.
        DB::getPdo()->exec("update users set score = 9 where name = 'Alice'");
        $this->assertSame(1, User::query()->where('name', 'Alice')->value('score'), 'the memo still answers a plain read');

        [$count, $scores] = $this->counted(fn (): array => [
            $alice->refresh()->score,
            $alice->fresh()?->score,
            User::query()->where('name', 'Alice')->withoutIdentityMap()->value('score'),
            DB::table('users')->useWritePdo()->where('name', 'Alice')->value('score'),
        ]);

        $this->assertSame(4, $count);
        $this->assertSame([9, 9, 9, 9], $scores);

        [$count] = $this->counted(function (): void {
            IdentityMap::flush();
            User::query()->where('name', 'Alice')->value('score');
        });

        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_large_result_is_not_remembered(): void
    {
        config()->set('quantum-slipstream-drive.query_memo.max_rows', 1);
        SelectMemo::flush();

        DB::table('users')->get();

        $this->assertSame(0, SelectMemo::size());
    }
}
