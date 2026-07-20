<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Coverage\ColumnSet;
use Vusys\QuantumSlipstreamDrive\Enums\RelationKind;
use Vusys\QuantumSlipstreamDrive\Graph\EdgeConfidence;
use Vusys\QuantumSlipstreamDrive\Graph\EdgeSource;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Graph\ModelIdentity;
use Vusys\QuantumSlipstreamDrive\Graph\PivotCoverage;
use Vusys\QuantumSlipstreamDrive\Graph\PivotEdge;
use Vusys\QuantumSlipstreamDrive\Graph\RelationCoverage;
use Vusys\QuantumSlipstreamDrive\Graph\RelationEdge;

final class PivotEdgeTest extends TestCase
{
    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        $this->graph = new IdentityGraph;
    }

    private function postIdentity(int $id = 1): ModelIdentity
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

    private function tagIdentity(int $id = 10): ModelIdentity
    {
        return new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Tag',
            table: 'tags',
            primaryKeyName: 'id',
            primaryKeyValue: $id,
            scopeFingerprint: 'default',
        );
    }

    /** @param array<string, mixed> $attrs */
    private function makePivotEdge(
        ModelIdentity $parent,
        ModelIdentity $related,
        string $relationName = 'tags',
        array $attrs = ['active' => true],
    ): PivotEdge {
        return new PivotEdge(
            parent: $parent,
            relationName: $relationName,
            related: $related,
            pivotTable: 'post_tag',
            pivotAttributes: $attrs,
            source: EdgeSource::Pivot,
            confidence: EdgeConfidence::Certain,
            version: 1,
        );
    }

    private function makePivotCoverage(
        ModelIdentity $parent,
        string $relationName = 'tags',
        string $relatedModelClass = 'App\\Models\\Tag',
        bool $complete = true,
    ): PivotCoverage {
        return new PivotCoverage(
            parent: $parent,
            relationName: $relationName,
            relatedModelClass: $relatedModelClass,
            pivotTable: 'post_tag',
            complete: $complete,
            knownPivotColumns: ['active'],
        );
    }

    #[Test]
    public function add_and_query_pivot_edges_round_trip(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $edge = $this->makePivotEdge($post, $tag);

        $this->graph->addPivotEdge($edge);

        $this->assertSame([$edge], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(1, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function adding_same_pivot_edge_twice_updates_in_place(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $first = $this->makePivotEdge($post, $tag, attrs: ['active' => true]);
        $second = $this->makePivotEdge($post, $tag, attrs: ['active' => false]);
        $second->version = 2;

        $this->graph->addPivotEdge($first);
        $this->graph->addPivotEdge($second);

        $edges = $this->graph->pivotEdgesFrom($post, 'tags');
        $this->assertCount(1, $edges);
        $this->assertSame($second, $edges[0]);
        $this->assertSame(1, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function pivot_edges_from_returns_empty_for_unknown_parent(): void
    {
        $this->assertSame([], $this->graph->pivotEdgesFrom($this->postIdentity(), 'tags'));
    }

    #[Test]
    public function pivot_coverage_round_trip(): void
    {
        $post = $this->postIdentity();
        $coverage = $this->makePivotCoverage($post);

        $this->graph->addPivotCoverage($coverage);

        $this->assertSame($coverage, $this->graph->pivotCoverageFor($post, 'tags'));
        $this->assertSame(1, $this->graph->pivotCoverageCount());
    }

    #[Test]
    public function remove_pivot_edge_removes_only_matching_related(): void
    {
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $edgeA = $this->makePivotEdge($post, $tagA);
        $edgeB = $this->makePivotEdge($post, $tagB);
        $this->graph->addPivotEdge($edgeA);
        $this->graph->addPivotEdge($edgeB);

        $this->graph->removePivotEdge($post, 'tags', $tagA);

        $this->assertSame([$edgeB], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(1, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function remove_pivot_edge_on_unknown_parent_is_noop(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();

        $this->graph->removePivotEdge($post, 'tags', $tag);

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function remove_pivot_edge_clears_empty_bucket(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));

        $this->graph->removePivotEdge($post, 'tags', $tag);

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function clear_pivot_edges_for_drops_all_for_relation(): void
    {
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tagA));
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tagB));

        $this->graph->clearPivotEdgesFor($post, 'tags');

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function clear_pivot_edges_for_unknown_parent_is_noop(): void
    {
        $this->graph->clearPivotEdgesFor($this->postIdentity(), 'tags');

        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function forget_pivot_coverage_removes_it(): void
    {
        $post = $this->postIdentity();
        $this->graph->addPivotCoverage($this->makePivotCoverage($post));

        $this->graph->forgetPivotCoverage($post, 'tags');

        $this->assertNull($this->graph->pivotCoverageFor($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotCoverageCount());
    }

    #[Test]
    public function invalidate_model_drops_outgoing_pivot_edges_and_coverage(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));
        $this->graph->addPivotCoverage($this->makePivotCoverage($post));

        $this->graph->invalidateModel($post);

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertNull($this->graph->pivotCoverageFor($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_drops_incoming_pivot_edges_for_that_related(): void
    {
        $postA = $this->postIdentity(1);
        $postB = $this->postIdentity(2);
        $tag = $this->tagIdentity();

        $this->graph->addPivotEdge($this->makePivotEdge($postA, $tag));
        $this->graph->addPivotEdge($this->makePivotEdge($postB, $tag));

        $this->graph->invalidateModel($tag);

        $this->assertSame([], $this->graph->pivotEdgesFrom($postA, 'tags'));
        $this->assertSame([], $this->graph->pivotEdgesFrom($postB, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_keeps_pivot_edges_to_siblings(): void
    {
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $edgeA = $this->makePivotEdge($post, $tagA);
        $edgeB = $this->makePivotEdge($post, $tagB);
        $this->graph->addPivotEdge($edgeA);
        $this->graph->addPivotEdge($edgeB);

        $this->graph->invalidateModel($tagA);

        $this->assertSame([$edgeB], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(1, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_class_drops_pivot_edges_on_either_side(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();

        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));
        $this->graph->addPivotCoverage($this->makePivotCoverage($post));

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertNull($this->graph->pivotCoverageFor($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_class_keeps_pivot_edges_to_siblings(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $labelClass = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Label',
            table: 'labels',
            primaryKeyName: 'id',
            primaryKeyValue: 5,
            scopeFingerprint: 'default',
        );
        $edgeToTag = $this->makePivotEdge($post, $tag);
        $edgeToLabel = $this->makePivotEdge($post, $labelClass, relationName: 'labels');
        $this->graph->addPivotEdge($edgeToTag);
        $this->graph->addPivotEdge($edgeToLabel);

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(
            [$edgeToLabel],
            $this->graph->pivotEdgesFrom($post, 'labels'),
            'sibling pivot edges to other classes must survive',
        );
    }

    #[Test]
    public function invalidate_model_class_does_not_match_pivot_class_prefix_collision(): void
    {
        $post = $this->postIdentity();
        $tag = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Tag',
            table: 'tags',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $tagExtra = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\TagExtra',
            table: 'tag_extras',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag, relationName: 'tags'));
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tagExtra, relationName: 'tagExtras'));

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertCount(
            1,
            $this->graph->pivotEdgesFrom($post, 'tagExtras'),
            'TagExtra must not be invalidated when Tag is',
        );
    }

    #[Test]
    public function invalidate_model_class_removes_pivot_coverage_when_parent_class_matches(): void
    {
        $post = $this->postIdentity();
        $coverage = $this->makePivotCoverage($post);
        $this->graph->addPivotCoverage($coverage);

        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->assertNull($this->graph->pivotCoverageFor($post, 'tags'));
    }

    #[Test]
    public function invalidate_model_class_keeps_unrelated_pivot_coverage(): void
    {
        $post = $this->postIdentity();
        $coverage = $this->makePivotCoverage($post);
        $this->graph->addPivotCoverage($coverage);

        $this->graph->invalidateModelClass('App\\Models\\OtherUnrelated');

        $this->assertSame($coverage, $this->graph->pivotCoverageFor($post, 'tags'));
    }

    #[Test]
    public function invalidate_model_does_not_match_pivot_keys_that_share_pk_prefix(): void
    {
        $post1 = $this->postIdentity(1);
        $post10 = $this->postIdentity(10);
        $tag = $this->tagIdentity();
        $edge1 = $this->makePivotEdge($post1, $tag);
        $edge10 = $this->makePivotEdge($post10, $tag);
        $this->graph->addPivotEdge($edge1);
        $this->graph->addPivotEdge($edge10);

        $this->graph->invalidateModel($post1);

        $this->assertSame([], $this->graph->pivotEdgesFrom($post1, 'tags'));
        $this->assertSame(
            [$edge10],
            $this->graph->pivotEdgesFrom($post10, 'tags'),
            'id=10 must not be invalidated when id=1 is',
        );
    }

    #[Test]
    public function flush_clears_pivot_storage(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));
        $this->graph->addPivotCoverage($this->makePivotCoverage($post));

        $this->graph->flush();

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertNull($this->graph->pivotCoverageFor($post, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
        $this->assertSame(0, $this->graph->pivotCoverageCount());
    }

    #[Test]
    public function pivot_edges_count_against_total_edge_cap(): void
    {
        $graph = new IdentityGraph(maxEdges: 2);
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $tagC = $this->tagIdentity(30);

        $graph->addPivotEdge($this->makePivotEdge($post, $tagA));
        $graph->addPivotEdge($this->makePivotEdge($post, $tagB));
        $this->assertSame(2, $graph->pivotEdgeCount());

        $graph->addPivotEdge($this->makePivotEdge($post, $tagC));
        $this->assertSame(0, $graph->pivotEdgeCount(), 'graph flushes when total edge cap reached');
    }

    #[Test]
    public function pivot_coverage_counts_against_total_coverage_cap(): void
    {
        $graph = new IdentityGraph(maxCoverage: 2);
        $postA = $this->postIdentity(1);
        $postB = $this->postIdentity(2);
        $postC = $this->postIdentity(3);

        $graph->addPivotCoverage($this->makePivotCoverage($postA));
        $graph->addPivotCoverage($this->makePivotCoverage($postB));
        $this->assertSame(2, $graph->pivotCoverageCount());

        $graph->addPivotCoverage($this->makePivotCoverage($postC));
        $this->assertSame(0, $graph->pivotCoverageCount(), 'graph flushes when total coverage cap reached');
    }

    #[Test]
    public function regular_and_pivot_edges_share_a_total_cap(): void
    {
        $graph = new IdentityGraph(maxEdges: 2);
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $tagC = $this->tagIdentity(30);

        $graph->addPivotEdge($this->makePivotEdge($post, $tagA));
        $graph->addPivotEdge($this->makePivotEdge($post, $tagB));
        $this->assertSame(2, $graph->totalEdgeCount());

        // a third edge — even a regular RelationEdge — should overflow.
        $graph->addEdge(new RelationEdge(
            from: $post,
            relationName: 'whatever',
            kind: RelationKind::HasMany,
            to: $tagC,
            source: EdgeSource::LoadedRelation,
            confidence: EdgeConfidence::Certain,
            version: 1,
        ));

        $this->assertSame(0, $graph->totalEdgeCount(), 'mixing regular and pivot must share the cap');
    }

    #[Test]
    public function add_pivot_edge_upsert_at_cap_does_not_flush(): void
    {
        $graph = new IdentityGraph(maxEdges: 1);
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $first = $this->makePivotEdge($post, $tag, attrs: ['active' => true]);
        $second = $this->makePivotEdge($post, $tag, attrs: ['active' => false]);

        $graph->addPivotEdge($first);
        $graph->addPivotEdge($second);

        $this->assertSame(1, $graph->pivotEdgeCount());
        $this->assertSame($second, $graph->pivotEdgesFrom($post, 'tags')[0]);
    }

    #[Test]
    public function invalidate_model_processes_all_outgoing_pivot_buckets_for_that_parent(): void
    {
        // Same parent, two distinct relations → two pivot buckets keyed by parent.
        $post = $this->postIdentity();
        $tagA = $this->tagIdentity(10);
        $tagB = $this->tagIdentity(20);
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tagA, relationName: 'tags'));
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tagB, relationName: 'archivedTags'));

        $this->graph->invalidateModel($post);

        $this->assertSame([], $this->graph->pivotEdgesFrom($post, 'tags'));
        $this->assertSame(
            [],
            $this->graph->pivotEdgesFrom($post, 'archivedTags'),
            'all outgoing pivot buckets for the invalidated parent must be cleared, not just the first',
        );
    }

    #[Test]
    public function invalidate_model_class_processes_all_matching_pivot_coverage_entries(): void
    {
        // Two coverages keyed by different parents but same class — needle matches both.
        $postA = $this->postIdentity(1);
        $postB = $this->postIdentity(2);
        $covA = $this->makePivotCoverage($postA);
        $covB = $this->makePivotCoverage($postB);
        $this->graph->addPivotCoverage($covA);
        $this->graph->addPivotCoverage($covB);
        $this->assertSame(2, $this->graph->pivotCoverageCount());

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertNull($this->graph->pivotCoverageFor($postA, 'tags'));
        $this->assertNull(
            $this->graph->pivotCoverageFor($postB, 'tags'),
            'all matching pivot coverage entries must be cleared, not just the first',
        );
    }

    #[Test]
    public function invalidate_model_class_continues_past_each_needle_matching_coverage_key(): void
    {
        // When the needle matches the coverage KEY (not just the class check), the loop
        // must `continue` so subsequent matches are still processed.
        // Coverage keys: '...|App\\Models\\Post|posts|...|tags' — needle '|Post|' is in each.
        $postA = $this->postIdentity(1);
        $postB = $this->postIdentity(2);
        $postC = $this->postIdentity(3);
        $this->graph->addPivotCoverage($this->makePivotCoverage($postA));
        $this->graph->addPivotCoverage($this->makePivotCoverage($postB));
        $this->graph->addPivotCoverage($this->makePivotCoverage($postC));

        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->assertNull($this->graph->pivotCoverageFor($postA, 'tags'));
        $this->assertNull($this->graph->pivotCoverageFor($postB, 'tags'));
        $this->assertNull(
            $this->graph->pivotCoverageFor($postC, 'tags'),
            'all needle-matching coverage entries must be removed, not just the first',
        );
        $this->assertSame(0, $this->graph->pivotCoverageCount());
    }

    #[Test]
    public function total_coverage_count_sums_both_kinds_publicly(): void
    {
        $post = $this->postIdentity();
        $userParent = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $this->graph->addPivotCoverage($this->makePivotCoverage($post));
        $this->graph->addCoverage(new RelationCoverage(
            parent: $userParent,
            relationName: 'posts',
            relatedModelClass: 'App\\Models\\Post',
            complete: true,
            columns: new ColumnSet(['*']),
            childPrimaryKeys: [10],
        ));

        // totalCoverageCount() is a public API used by callers to size the graph;
        // assert its sum behaviour and visibility directly.
        $this->assertSame(2, $this->graph->totalCoverageCount());
    }

    #[Test]
    public function total_edge_count_sums_both_kinds_publicly(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $user = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));
        $this->graph->addEdge(new RelationEdge(
            from: $user,
            relationName: 'posts',
            kind: RelationKind::HasMany,
            to: $post,
            source: EdgeSource::LoadedRelation,
            confidence: EdgeConfidence::Certain,
            version: 1,
        ));

        $this->assertSame(2, $this->graph->totalEdgeCount());
    }

    #[Test]
    public function invalidate_model_class_continues_past_orphan_pivot_edge_bucket(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $user = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 7,
            scopeFingerprint: 'default',
        );

        $this->graph->addPivotEdge($this->makePivotEdge($post, $tag));
        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->graph->addPivotEdge(new PivotEdge(
            parent: $user,
            relationName: 'tags',
            related: $tag,
            pivotTable: 'user_tag',
            pivotAttributes: ['flag' => true],
            source: EdgeSource::Pivot,
            confidence: EdgeConfidence::Certain,
            version: 1,
        ));

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame(
            [],
            $this->graph->pivotEdgesFrom($user, 'tags'),
            'After invalidating Tag, the live User→Tag pivot bucket must still be processed even when an orphaned bucket precedes it in pivotEdgesBucketsByClass.',
        );
    }

    #[Test]
    public function invalidate_model_class_continues_past_orphan_pivot_coverage_entry(): void
    {
        $post = $this->postIdentity();
        $user = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 7,
            scopeFingerprint: 'default',
        );

        $this->graph->addPivotCoverage($this->makePivotCoverage(
            $post,
            relationName: 'tags',
            relatedModelClass: 'App\\Models\\Tag',
        ));
        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->graph->addPivotCoverage($this->makePivotCoverage(
            $user,
            relationName: 'tags',
            relatedModelClass: 'App\\Models\\Tag',
        ));

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertNull(
            $this->graph->pivotCoverageFor($user, 'tags'),
            'After invalidating Tag, the live User→Tag pivot coverage must still be processed even when an orphaned key precedes it in pivotCoverageKeysByClass.',
        );
    }

    #[Test]
    public function invalidate_model_class_keeps_pivot_siblings_in_same_bucket(): void
    {
        $post = $this->postIdentity();
        $tag = $this->tagIdentity();
        $label = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Label',
            table: 'labels',
            primaryKeyName: 'id',
            primaryKeyValue: 5,
            scopeFingerprint: 'default',
        );

        $edgeToTag = $this->makePivotEdge($post, $tag);
        $edgeToLabel = new PivotEdge(
            parent: $post,
            relationName: 'tags',
            related: $label,
            pivotTable: 'post_tag',
            pivotAttributes: ['flag' => true],
            source: EdgeSource::Pivot,
            confidence: EdgeConfidence::Certain,
            version: 1,
        );
        $this->graph->addPivotEdge($edgeToTag);
        $this->graph->addPivotEdge($edgeToLabel);

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame(
            [$edgeToLabel],
            $this->graph->pivotEdgesFrom($post, 'tags'),
            'Surviving pivot edges to other classes must remain in the bucket; the bucket must NOT be unset when $kept is non-empty.',
        );
    }

    #[Test]
    public function add_pivot_coverage_upsert_at_cap_does_not_flush(): void
    {
        $graph = new IdentityGraph(maxCoverage: 1);
        $post = $this->postIdentity();
        $first = $this->makePivotCoverage($post, complete: false);
        $second = $this->makePivotCoverage($post, complete: true);

        $graph->addPivotCoverage($first);
        $graph->addPivotCoverage($second);

        $this->assertSame(1, $graph->pivotCoverageCount());
        $this->assertSame($second, $graph->pivotCoverageFor($post, 'tags'));
    }
}
