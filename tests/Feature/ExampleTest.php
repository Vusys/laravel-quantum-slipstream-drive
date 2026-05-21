<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ExampleTest extends TestCase
{
    public function test_service_provider_loads(): void
    {
        $this->assertNotNull($this->app);
    }
}
