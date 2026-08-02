<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

abstract class PerformanceTestCase extends TestCase
{
    protected function bench(string $label, callable $fn): void
    {
        $this->expectNotToPerformAssertions();

        $this->flushEngineState();
        [$elapsed, $queries] = $this->measure($fn, $label);

        $this->emitBenchEnd($label, $elapsed, $queries);
    }

    /**
     * A/B variant of bench(): times the same workload twice — once with the
     * engine disabled via IdentityMapStore::disabled() (the vanilla-Eloquent
     * control arm, emitted as "<label>-baseline") and once with it active
     * (emitted as "<label>", so single-arm history in Bencher carries over).
     * A "::bench-pair::" line reports the speedup ratio for perf-to-bmf.php.
     */
    protected function benchPair(string $label, \Closure $fn): void
    {
        $this->expectNotToPerformAssertions();

        $store = resolve(IdentityMapStore::class);

        $offArm = static function () use ($store, $fn): void {
            $store->disabled(static function () use ($fn): bool {
                $fn();

                return true;
            });
        };

        // Untimed engine-off pass so both timed arms run against warm
        // database and runtime caches regardless of arm order.
        $this->flushEngineState();
        $offArm();

        $this->flushEngineState();
        [$offElapsed, $offQueries] = $this->measure($offArm);

        $this->flushEngineState();
        [$onElapsed, $onQueries] = $this->measure($fn, $label);

        $this->emitBenchEnd($label, $onElapsed, $onQueries);
        $this->emitBenchEnd($label.'-baseline', $offElapsed, $offQueries);

        $speedup = $onElapsed > 0.0 ? $offElapsed / $onElapsed : 0.0;

        fwrite(STDERR, sprintf(
            "::bench-pair::  %s  %-60s  %.2fx speedup  %d -> %d queries\n",
            $label,
            $label,
            $speedup,
            $offQueries,
            $onQueries,
        ));
    }

    private function flushEngineState(): void
    {
        resolve(IdentityMapStore::class)->flush();
        resolve(CoverageRegistry::class)->flush();
        resolve(IdentityGraph::class)->flush();
    }

    /** @return array{float, int} */
    private function measure(callable $fn, ?string $profileLabel = null): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $profiler = null;

        if ($profileLabel !== null && getenv('PROFILE') === '1' && extension_loaded('excimer')) {
            $periodEnv = getenv('EXCIMER_PERIOD');
            $period = is_string($periodEnv) && is_numeric($periodEnv) ? (float) $periodEnv : 0.0001;

            $profiler = new \ExcimerProfiler;
            $profiler->setPeriod($period);
            $profiler->setEventType(EXCIMER_REAL);
            $profiler->start();
        }

        $start = hrtime(true);
        try {
            $fn();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            if ($profiler instanceof \ExcimerProfiler) {
                $profiler->stop();
                $this->writeProfileArtifacts($profileLabel, $profiler->getLog());
            }
        }

        return [$elapsed, $queries];
    }

    private function emitBenchEnd(string $label, float $elapsed, int $queries): void
    {
        $queryWord = $queries === 1 ? 'query' : 'queries';

        fwrite(STDERR, sprintf(
            "::bench-end::   %s  %-60s  %.3f ms  %d %s\n",
            $label,
            $label,
            $elapsed,
            $queries,
            $queryWord,
        ));
    }

    private function writeProfileArtifacts(string $label, \ExcimerLog $log): void
    {
        $dir = getenv('PROFILE_DIR') ?: 'build/profiles';

        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return;
        }

        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $label) ?? $label;

        // speedscope.app accepts Brendan Gregg's collapsed format natively, so we don't
        // need to also emit the proprietary speedscope JSON to get an interactive
        // flamegraph in the browser.
        $collapsed = $log->formatCollapsed();
        file_put_contents($dir.'/'.$safe.'.collapsed.txt', $collapsed);

        $top = $log->aggregateByFunction();
        uasort($top, static function (array $a, array $b): int {
            $aIncl = is_int($a['inclusive'] ?? null) ? $a['inclusive'] : 0;
            $bIncl = is_int($b['inclusive'] ?? null) ? $b['inclusive'] : 0;

            return $bIncl <=> $aIncl;
        });

        $lines = [
            sprintf('Profile: %s', $label),
            sprintf('Samples: %d', count($log)),
            '',
            sprintf('%-8s  %-8s  %s', 'incl', 'self', 'function'),
            str_repeat('-', 80),
        ];

        $shown = 0;
        foreach ($top as $fn => $stats) {
            if ($shown++ >= 40) {
                break;
            }
            $incl = is_int($stats['inclusive'] ?? null) ? $stats['inclusive'] : 0;
            $self = is_int($stats['self'] ?? null) ? $stats['self'] : 0;
            $lines[] = sprintf('%-8d  %-8d  %s', $incl, $self, $fn);
        }

        file_put_contents($dir.'/'.$safe.'.topN.txt', implode("\n", $lines)."\n");
    }
}
