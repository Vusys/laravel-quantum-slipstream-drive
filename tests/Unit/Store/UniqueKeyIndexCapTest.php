<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Store\UniqueKeyIndex;

final class UniqueKeyIndexCapTest extends TestCase
{
    #[Test]
    public function absent_markers_accumulate_up_to_the_cap_then_evict_the_oldest(): void
    {
        $index = new UniqueKeyIndex(maxKeys: 2);

        $index->recordAbsent('fp-a');
        $this->assertSame(1, $index->debugStats()['unique_absent']);

        $index->recordAbsent('fp-b');
        $this->assertSame(2, $index->debugStats()['unique_absent']);

        $index->recordAbsent('fp-c');
        $this->assertSame(2, $index->debugStats()['unique_absent'], 'the breach evicts the coldest marker, then records the new one');
        $this->assertFalse($index->isAbsent('fp-a'), 'the least-recently-used marker is the one evicted');
        $this->assertTrue($index->isAbsent('fp-b'));
        $this->assertTrue($index->isAbsent('fp-c'));
    }

    #[Test]
    public function a_recently_hit_absent_marker_survives_the_cap_breach(): void
    {
        $index = new UniqueKeyIndex(maxKeys: 2);

        $index->recordAbsent('fp-a');
        $index->recordAbsent('fp-b');

        // Hitting fp-a makes fp-b the least recently used.
        $index->isAbsent('fp-a');

        $index->recordAbsent('fp-c');

        $this->assertTrue($index->isAbsent('fp-a'), 'the hot marker survives');
        $this->assertFalse($index->isAbsent('fp-b'), 'the cold marker is evicted');
    }

    #[Test]
    public function re_recording_an_existing_absent_marker_at_the_cap_does_not_flush(): void
    {
        $index = new UniqueKeyIndex(maxKeys: 2);
        $index->recordAbsent('fp-a');
        $index->recordAbsent('fp-b');

        $index->recordAbsent('fp-a');

        $this->assertSame(2, $index->debugStats()['unique_absent'], 're-recording a known marker is not growth and must not flush');
    }

    #[Test]
    public function null_cap_never_flushes(): void
    {
        $index = new UniqueKeyIndex;

        foreach (range(1, 50) as $i) {
            $index->recordAbsent("fp-{$i}");
        }

        $this->assertSame(50, $index->debugStats()['unique_absent']);
    }
}
