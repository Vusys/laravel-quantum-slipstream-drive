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
            $this->assertNull(SelectMemo::keyFor(DB::connection(), 'id', $sql, [], true, []), $sql);
        }

        $this->assertNotNull(SelectMemo::keyFor(DB::connection(), 'id', 'select * from "users" where "score" > ?', [1], true, []));

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
    public function a_write_made_while_a_select_returns_does_not_leave_its_rows_remembered(): void
    {
        $written = false;
        DB::listen(function ($query) use (&$written): void {
            if (! $written && str_starts_with($query->sql, 'select')) {
                $written = true;
                DB::table('users')->where('name', 'Alice')->update(['score' => 7]);
            }
        });

        DB::table('users')->where('name', 'Alice')->value('score');

        $this->assertSame(7, DB::table('users')->where('name', 'Alice')->value('score'));
    }

    #[Test]
    public function a_cursor_forgets_when_it_starts_running(): void
    {
        $cursor = DB::cursor('select * from users');
        DB::table('users')->get();
        $this->assertSame(1, SelectMemo::size());

        iterator_to_array($cursor);

        $this->assertSame(0, SelectMemo::size());
    }

    #[Test]
    public function more_that_must_always_run(): void
    {
        foreach ([
            'select * from users for no key update', 'select * from users for key share', "select set_config('search_path', 'b', false)",
            'select julianday()', "select strftime('%s')", 'select date()',
        ] as $sql) {
            $this->assertNull(SelectMemo::keyFor(DB::connection(), 'id', $sql, [], true, []), $sql);
        }

        $this->assertNull(SelectMemo::keyFor(DB::connection(), 'id', 'select datetime(?)', ['now'], true, []));
        $this->assertNull(SelectMemo::keyFor(DB::connection(), 'id', 'select * from users where name = ?', [fopen('php://memory', 'r')], true, []));
        $this->assertNotNull(SelectMemo::keyFor(DB::connection(), 'id', "select strftime('%Y', created_at) from users", [], true, []));
    }

    #[Test]
    public function a_read_sent_to_the_writer_by_the_connection_is_not_answered_from_memory(): void
    {
        DB::table('users')->get();

        [$count] = $this->counted(function (): void {
            DB::connection()->useWriteConnectionWhenReading();
            DB::table('users')->get();
            DB::connection()->useWriteConnectionWhenReading(false);
        });

        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_new_pdo_shares_nothing_with_the_old_one(): void
    {
        DB::table('users')->get();

        DB::connection()->setReadPdo(DB::connection()->getPdo());

        [$count] = $this->counted(fn (): mixed => DB::table('users')->get());

        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_result_holding_a_stream_is_not_remembered(): void
    {
        SelectMemo::flush();
        SelectMemo::remember('k', [(object) ['payload' => fopen('php://memory', 'r')]], SelectMemo::generation());

        $this->assertSame(0, SelectMemo::size());
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
