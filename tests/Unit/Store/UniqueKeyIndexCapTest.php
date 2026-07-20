<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Store\UniqueKeyIndex;

final class UniqueKeyIndexCapTest extends TestCase
{
    #[Test]
    public function absent_markers_accumulate_up_to_the_cap_then_flush(): void
    {
        $index = new UniqueKeyIndex(maxKeys: 2);

        $index->recordAbsent('fp-a');
        $this->assertSame(1, $index->debugStats()['unique_absent']);

        $index->recordAbsent('fp-b');
        $this->assertSame(2, $index->debugStats()['unique_absent']);

        $index->recordAbsent('fp-c');
        $this->assertSame(0, $index->debugStats()['unique_absent'], 'index flushes once the cap is reached');
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
