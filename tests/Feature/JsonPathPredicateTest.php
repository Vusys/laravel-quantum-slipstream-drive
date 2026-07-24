<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\CastSample;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class JsonPathPredicateTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    private function seedSamples(): void
    {
        CastSample::create(['name' => 'pro-gold', 'payload' => ['plan' => 'pro', 'tier' => 'gold']]);
        CastSample::create(['name' => 'pro-silver', 'payload' => ['plan' => 'pro', 'tier' => 'silver']]);
        CastSample::create(['name' => 'free', 'payload' => ['plan' => 'free', 'tier' => 'none']]);
        CastSample::create(['name' => 'no-plan', 'payload' => ['other' => 'x']]);
        $this->store->flush();
        $this->graph->flush();
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

    #[Test]
    public function json_path_equality_is_served_from_coverage_and_matches_ground_truth(): void
    {
        $this->seedSamples();

        // First read records coverage over the JSON-path predicate.
        $first = CastSample::where('payload->plan', 'pro')->get()->pluck('name')->sort()->values()->all();
        $this->assertSame(['pro-gold', 'pro-silver'], $first);

        // Second identical read is served entirely from memory: the recorded
        // coverage region is a JSON-path predicate the evaluator now resolves.
        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = CastSample::where('payload->plan', 'pro')->get()->pluck('name')->sort()->values()->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => CastSample::where('payload->plan', 'pro')->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame(0, $sql, 'a repeated JSON-path equality read must be served from memory');
        $this->assertSame($oracle, $names);
        $this->assertSame(['pro-gold', 'pro-silver'], $names);
    }

    #[Test]
    public function nested_json_path_equality_matches_ground_truth(): void
    {
        CastSample::create(['name' => 'a', 'payload' => ['billing' => ['plan' => 'pro']]]);
        CastSample::create(['name' => 'b', 'payload' => ['billing' => ['plan' => 'free']]]);
        $this->store->flush();
        $this->graph->flush();

        CastSample::where('payload->billing->plan', 'pro')->get(); // record coverage

        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = CastSample::where('payload->billing->plan', 'pro')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => CastSample::where('payload->billing->plan', 'pro')->get()->pluck('name')->all()
        );

        $this->assertSame(0, $sql, 'a nested JSON-path equality read must be served from memory');
        $this->assertSame($oracle, $names);
        $this->assertSame(['a'], $names);
    }

    #[Test]
    public function json_path_inequality_matches_ground_truth(): void
    {
        $this->seedSamples();

        CastSample::where('payload->plan', '!=', 'pro')->get(); // record coverage

        $slipstream = CastSample::where('payload->plan', '!=', 'pro')->get()->pluck('name')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn () => CastSample::where('payload->plan', '!=', 'pro')->get()->pluck('name')->sort()->values()->all()
        );

        // != excludes rows where the extracted value is NULL (missing path) under
        // SQL three-valued logic — the oracle is the source of truth here.
        $this->assertSame($oracle, $slipstream);
    }

    #[Test]
    public function numeric_json_path_falls_through_to_sql_and_matches_ground_truth(): void
    {
        CastSample::create(['name' => 'a', 'payload' => ['age' => 25]]);
        CastSample::create(['name' => 'b', 'payload' => ['age' => 30]]);
        $this->store->flush();
        $this->graph->flush();

        CastSample::where('payload->age', 25)->get(); // record coverage (if any)

        // A numeric JSON comparison is not resolved in memory (per-driver typing),
        // so it must fall through to SQL and still match ground truth.
        $names = null;
        $sql = $this->countSql(function () use (&$names): void {
            $names = CastSample::where('payload->age', 25)->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => CastSample::where('payload->age', 25)->get()->pluck('name')->all()
        );

        $this->assertGreaterThan(0, $sql, 'a numeric JSON-path read must defer to SQL');
        $this->assertSame($oracle, $names);
    }

    #[Test]
    public function invalidation_after_write_reflects_new_rows(): void
    {
        $this->seedSamples();

        CastSample::where('payload->plan', 'pro')->get(); // coverage

        CastSample::create(['name' => 'pro-bronze', 'payload' => ['plan' => 'pro', 'tier' => 'bronze']]);

        $names = CastSample::where('payload->plan', 'pro')->get()->pluck('name')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn () => CastSample::where('payload->plan', 'pro')->get()->pluck('name')->sort()->values()->all()
        );

        $this->assertSame($oracle, $names);
        $this->assertContains('pro-bronze', $names);
    }
}
