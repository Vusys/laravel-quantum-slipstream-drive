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

        $key = SelectMemo::keyFor($this, $query, $bindings, $useReadPdo, $fetchUsing);

        if ($key === null) {
            // `insert ... returning`, a locking read or a procedure call can all arrive here.
            SelectMemo::flush();

            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        }

        $remembered = SelectMemo::get($key);

        if ($remembered !== null) {
            return $remembered;
        }

        $rows = parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        SelectMemo::remember($key, $rows);

        return $rows;
    }

    #[\Override]
    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        self::forget();

        return parent::selectResultSets($query, $bindings, $useReadPdo, $fetchUsing);
    }

    #[\Override]
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        self::forget();

        return parent::cursor($query, $bindings, $useReadPdo, $fetchUsing);
    }

    #[\Override]
    public function statement($query, $bindings = [])
    {
        self::forget();

        return parent::statement($query, $bindings);
    }

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
