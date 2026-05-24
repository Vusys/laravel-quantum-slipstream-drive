<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Store\JournalEntry;
use Vusys\QueryRicerExtreme\Store\TransactionJournal;

final class TransactionJournalTest extends TestCase
{
    #[Test]
    public function is_inactive_before_begin(): void
    {
        $journal = new TransactionJournal;

        $this->assertFalse($journal->isActive());
        $this->assertSame(0, $journal->depth());
    }

    #[Test]
    public function begin_then_rollback_returns_snapshots_for_that_level(): void
    {
        $journal = new TransactionJournal;

        $journal->begin();
        $this->assertTrue($journal->isActive());
        $this->assertSame(1, $journal->depth());

        $entry = $this->makeEntry('users|1');
        $journal->snapshot($entry);

        $restored = $journal->rollback();

        $this->assertCount(1, $restored);
        $this->assertSame($entry, $restored[0]);
        $this->assertFalse($journal->isActive());
    }

    #[Test]
    public function snapshot_is_idempotent_per_key_at_each_level(): void
    {
        $journal = new TransactionJournal;
        $journal->begin();

        $first = $this->makeEntry('users|1');
        $second = $this->makeEntry('users|1');

        $journal->snapshot($first);
        $journal->snapshot($second);

        $restored = $journal->rollback();

        $this->assertCount(1, $restored);
        $this->assertSame($first, $restored[0], 'second snapshot for same key must be ignored');
    }

    #[Test]
    public function snapshot_outside_any_transaction_is_a_noop(): void
    {
        $journal = new TransactionJournal;

        $journal->snapshot($this->makeEntry('users|1'));

        $this->assertFalse($journal->isActive());
        $this->assertSame([], $journal->rollback());
    }

    #[Test]
    public function commit_at_outermost_level_discards_journal_entries(): void
    {
        $journal = new TransactionJournal;
        $journal->begin();
        $journal->snapshot($this->makeEntry('users|1'));

        $journal->commit();

        $this->assertFalse($journal->isActive());
        $this->assertSame([], $journal->rollback());
    }

    #[Test]
    public function rollback_at_inner_level_returns_inner_snapshots_only(): void
    {
        $journal = new TransactionJournal;

        $journal->begin();
        $outer = $this->makeEntry('users|outer');
        $journal->snapshot($outer);

        $journal->begin();
        $inner = $this->makeEntry('users|inner');
        $journal->snapshot($inner);

        $restored = $journal->rollback();

        $this->assertCount(1, $restored);
        $this->assertSame($inner, $restored[0]);
        $this->assertTrue($journal->isActive(), 'outer transaction must still be open');
        $this->assertSame(1, $journal->depth());
    }

    #[Test]
    public function commit_inner_promotes_snapshots_to_parent_level(): void
    {
        $journal = new TransactionJournal;

        $journal->begin();
        $outer = $this->makeEntry('users|outer');
        $journal->snapshot($outer);

        $journal->begin();
        $inner = $this->makeEntry('users|inner');
        $journal->snapshot($inner);

        $journal->commit();

        $this->assertSame(1, $journal->depth());

        $restored = $journal->rollback();

        $keys = array_map(static fn (JournalEntry $e): string => $e->entryKey, $restored);

        $this->assertContains('users|outer', $keys);
        $this->assertContains('users|inner', $keys);
        $this->assertCount(2, $restored);
    }

    #[Test]
    public function commit_inner_does_not_overwrite_existing_parent_snapshot_for_same_key(): void
    {
        $journal = new TransactionJournal;

        $journal->begin();
        $outerKey = $this->makeEntry('users|same');
        $journal->snapshot($outerKey);

        $journal->begin();
        $innerKey = $this->makeEntry('users|same');
        $journal->snapshot($innerKey);

        $journal->commit();

        $restored = $journal->rollback();

        $this->assertCount(1, $restored);
        $this->assertSame($outerKey, $restored[0], 'parent snapshot wins on commit-merge');
    }

    #[Test]
    public function flush_clears_all_levels(): void
    {
        $journal = new TransactionJournal;
        $journal->begin();
        $journal->begin();
        $journal->snapshot($this->makeEntry('users|1'));

        $journal->flush();

        $this->assertFalse($journal->isActive());
        $this->assertSame(0, $journal->depth());
    }

    #[Test]
    public function commit_outside_any_transaction_is_a_noop(): void
    {
        $journal = new TransactionJournal;

        $journal->commit();

        $this->assertFalse($journal->isActive());
    }

    private function makeEntry(string $key): JournalEntry
    {
        return new JournalEntry(
            entryKey: $key,
            before: null,
            wasAbsent: false,
            modelOriginal: null,
        );
    }
}
