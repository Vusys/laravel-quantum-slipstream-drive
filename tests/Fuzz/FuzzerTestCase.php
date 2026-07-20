<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Fuzz;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

abstract class FuzzerTestCase extends TestCase
{
    private const string DEFAULT_SEEDS = '1,42,1337';

    private const int DEFAULT_STEPS = 20;

    /**
     * Iterate over configured seeds and steps, seeding mt_rand before each seed run.
     * On assertion failure, re-throws with seed + step context for reproducibility:
     * reproduce with FUZZER_SEEDS=<seed> FUZZER_STEPS=<step+1> composer fuzz
     *
     * @param  Closure(int $seed, int $step): void  $fn
     */
    protected function eachSeed(Closure $fn): void
    {
        $seeds = $this->resolveSeeds();
        $steps = (int) (getenv('FUZZER_STEPS') ?: self::DEFAULT_STEPS);

        foreach ($seeds as $seed) {
            mt_srand($seed);

            for ($step = 0; $step < $steps; $step++) {
                try {
                    $fn($seed, $step);
                } catch (AssertionFailedError $e) {
                    throw new AssertionFailedError(
                        sprintf('[seed=%d step=%d] %s', $seed, $step, $e->getMessage()),
                        $e->getCode(),
                        $e,
                    );
                }
            }
        }
    }

    /** @return list<int> */
    private function resolveSeeds(): array
    {
        $env = getenv('FUZZER_SEEDS') ?: self::DEFAULT_SEEDS;
        $count = (int) (getenv('FUZZER_SEED_COUNT') ?: 3);

        if ($env === 'random') {
            $seeds = [];
            for ($i = 0; $i < $count; $i++) {
                $seeds[] = random_int(1, PHP_INT_MAX);
            }

            return $seeds;
        }

        return array_map(intval(...), explode(',', $env));
    }
}
