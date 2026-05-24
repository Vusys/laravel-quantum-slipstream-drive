<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\RelationCoverage;
use Vusys\QueryRicerExtreme\Graph\RelationEdge;

final class IdentityGraphTest extends TestCase
{
    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        $this->graph = new IdentityGraph;
    }

    private function userIdentity(int $id = 1): ModelIdentity
    {
        return new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $id,
            scopeFingerprint: 'default',
        );
    }

    private function postIdentity(int $id = 10): ModelIdentity
    {
        return new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Post',
            table: 'posts',
            primaryKeyName: 'id',
            primaryKeyValue: $id,
            scopeFingerprint: 'default',
        );
    }

    private function makeEdge(ModelIdentity $from, ModelIdentity $to, string $name = 'posts'): RelationEdge
    {
        return new RelationEdge(
            from: $from,
            relationName: $name,
            kind: RelationKind::HasMany,
            to: $to,
            source: EdgeSource::LoadedRelation,
            confidence: EdgeConfidence::Certain,
            version: 1,
        );
    }

    /**
     * @param  list<int|string>  $childPrimaryKeys
     */
    private function makeCoverage(
        ModelIdentity $parent,
        string $relationName = 'posts',
        string $relatedModelClass = 'App\\Models\\Post',
        array $childPrimaryKeys = [],
    ): RelationCoverage {
        return new RelationCoverage(
            parent: $parent,
            relationName: $relationName,
            relatedModelClass: $relatedModelClass,
            complete: true,
            columns: new ColumnSet(['*']),
            childPrimaryKeys: $childPrimaryKeys,
        );
    }

    #[Test]
    public function add_and_query_edges_round_trip(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $edge = $this->makeEdge($user, $post);

        $this->graph->addEdge($edge);

        $this->assertSame([$edge], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(1, $this->graph->edgeCount());
    }

    #[Test]
    public function adding_same_edge_twice_updates_in_place(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $first = $this->makeEdge($user, $post);
        $second = $this->makeEdge($user, $post);
        $second->version = 7;

        $this->graph->addEdge($first);
        $this->graph->addEdge($second);

        $edges = $this->graph->edgesFrom($user, 'posts');
        $this->assertCount(1, $edges);
        $this->assertSame($second, $edges[0]);
        $this->assertSame(1, $this->graph->edgeCount());
    }

    #[Test]
    public function edges_from_returns_empty_for_unknown_parent(): void
    {
        $this->assertSame([], $this->graph->edgesFrom($this->userIdentity(), 'posts'));
    }

    #[Test]
    public function add_and_query_coverage_round_trip(): void
    {
        $user = $this->userIdentity();
        $coverage = $this->makeCoverage($user, childPrimaryKeys: [10, 11]);

        $this->graph->addCoverage($coverage);

        $this->assertSame($coverage, $this->graph->coverageFor($user, 'posts'));
        $this->assertSame(1, $this->graph->coverageCount());
    }

    #[Test]
    public function coverage_lookup_returns_null_when_relation_name_differs(): void
    {
        $user = $this->userIdentity();
        $this->graph->addCoverage($this->makeCoverage($user, relationName: 'posts'));

        $this->assertNull($this->graph->coverageFor($user, 'comments'));
    }

    #[Test]
    public function invalidate_model_removes_outgoing_edges_and_coverage(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->invalidateModel($user);

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_removes_incoming_edges_only_for_that_model(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $post = $this->postIdentity(10);

        $this->graph->addEdge($this->makeEdge($userA, $post));
        $this->graph->addEdge($this->makeEdge($userB, $post));

        $this->graph->invalidateModel($post);

        $this->assertSame([], $this->graph->edgesFrom($userA, 'posts'));
        $this->assertSame([], $this->graph->edgesFrom($userB, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_leaves_unrelated_data_intact(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);

        $this->graph->addEdge($this->makeEdge($userA, $postA));
        $edgeB = $this->makeEdge($userB, $postB);
        $this->graph->addEdge($edgeB);
        $coverageB = $this->makeCoverage($userB, childPrimaryKeys: [20]);
        $this->graph->addCoverage($coverageB);

        $this->graph->invalidateModel($userA);

        $this->assertSame([$edgeB], $this->graph->edgesFrom($userB, 'posts'));
        $this->assertSame($coverageB, $this->graph->coverageFor($userB, 'posts'));
    }

    #[Test]
    public function invalidate_model_class_removes_edges_and_coverage_on_either_side(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();

        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_class_removes_outgoing_edges_when_from_class_matches(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function flush_clears_everything(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->flush();

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
        $this->assertSame(0, $this->graph->coverageCount());
    }

    #[Test]
    public function exceeding_max_edges_flushes_graph(): void
    {
        $graph = new IdentityGraph(maxEdges: 1);
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);

        $graph->addEdge($this->makeEdge($userA, $postA));
        $graph->addEdge($this->makeEdge($userB, $postB));

        $this->assertSame(0, $graph->edgeCount());
    }

    #[Test]
    public function exceeding_max_coverage_flushes_graph(): void
    {
        $graph = new IdentityGraph(maxCoverage: 1);
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);

        $graph->addCoverage($this->makeCoverage($userA, childPrimaryKeys: [10]));
        $graph->addCoverage($this->makeCoverage($userB, childPrimaryKeys: [20]));

        $this->assertSame(0, $graph->coverageCount());
    }

    #[Test]
    public function model_identity_key_includes_all_components(): void
    {
        $identity = new ModelIdentity(
            connection: 'pg',
            modelClass: 'A',
            table: 't',
            primaryKeyName: 'pk',
            primaryKeyValue: 42,
            scopeFingerprint: 'fp',
        );

        $this->assertSame('pg|A|t|pk|42|fp', $identity->key());
    }
}
