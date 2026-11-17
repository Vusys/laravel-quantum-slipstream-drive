<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

/**
 * Mixed into the per-driver connection subclasses: an identical SELECT run again before anything
 * could have changed its rows is answered from {@see SelectMemo}. Everything else this connection
 * runs, and every transaction event, empties the memo first. With `query_memo.enabled` off, each
 * method is the stock one.
 */
trait MemoisesSelects
{
    /** Never reused, unlike an object id: a rebuilt connection must share nothing with this one. */
    private ?string $memoIdentity = null;

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<array-key, mixed>  $fetchUsing
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (! self::memoising()) {
            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        }

        // `useWriteConnectionWhenReading()` sends this read to the writer without saying so here.
        $this->memoIdentity ??= bin2hex(random_bytes(8));
        $key = SelectMemo::keyFor($this, $this->memoIdentity, $query, $bindings, $useReadPdo && ! $this->readOnWriteConnection, $fetchUsing);

        if ($key === null) {
            // `insert ... returning`, a locking read or a procedure call can all arrive here.
            SelectMemo::flush();

            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        }

        $remembered = SelectMemo::get($key);

        if ($remembered !== null) {
            return $remembered;
        }

        $generation = SelectMemo::generation();
        $rows = parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        SelectMemo::remember($key, $rows, $generation);

        return $rows;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<array-key, mixed>  $fetchUsing
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        self::forget();

        return parent::selectResultSets($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<array-key, mixed>  $fetchUsing
     * @return \Generator<int, \stdClass>
     */
    #[\Override]
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        self::forget();
        $cursor = parent::cursor($query, $bindings, $useReadPdo, $fetchUsing);

        // The statement runs at the first advance, not here, and it may be a write.
        return (static function () use ($cursor): \Generator {
            self::forget();

            yield from $cursor;
        })();
    }

    /**
     * A new PDO may reach another server: nothing remembered through the old one applies.
     *
     * @param  \PDO|\Closure|null  $pdo
     * @return $this
     */
    #[\Override]
    public function setPdo($pdo)
    {
        $this->memoIdentity = null;
        self::forget();

        return parent::setPdo($pdo);
    }

    /**
     * @param  \PDO|\Closure|null  $pdo
     * @return $this
     */
    #[\Override]
    public function setReadPdo($pdo)
    {
        $this->memoIdentity = null;
        self::forget();

        return parent::setReadPdo($pdo);
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @return bool
     */
    #[\Override]
    public function statement($query, $bindings = [])
    {
        self::forget();

        return parent::statement($query, $bindings);
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @return int
     */
    #[\Override]
    public function affectingStatement($query, $bindings = [])
    {
        self::forget();

        return parent::affectingStatement($query, $bindings);
    }

    #[\Override]
    public function unprepared($query)
    {
        self::forget();

        return parent::unprepared($query);
    }

    /**
     * Begin, commit and roll back, including savepoints: a rollback undoes writes whose
     * results may already have been remembered.
     *
     * @param  string  $event
     * @return array<array-key, mixed>|null
     */
    #[\Override]
    protected function fireConnectionEvent($event)
    {
        self::forget();

        return parent::fireConnectionEvent($event);
    }

    private static function memoising(): bool
    {
        return config('quantum-slipstream-drive.query_memo.enabled') === true;
    }

    private static function forget(): void
    {
        if (self::memoising()) {
            SelectMemo::flush();
        }
    }
}
