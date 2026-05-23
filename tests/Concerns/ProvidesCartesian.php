<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Concerns;

trait ProvidesCartesian
{
    /**
     * Produce the cartesian product of the given sets as a flat list of argument arrays.
     *
     * @param  list<mixed>  ...$sets
     * @return list<list<mixed>>
     */
    public static function cartesian(array ...$sets): array
    {
        $result = [[]];

        foreach ($sets as $set) {
            $merged = [];

            foreach ($result as $prefix) {
                foreach ($set as $item) {
                    $merged[] = array_merge($prefix, [$item]);
                }
            }

            $result = $merged;
        }

        return $result;
    }
}
