<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\PHPStan\data;

use function PHPStan\Testing\assertType;

use Vusys\QuantumSlipstreamDrive\Tests\Models\Comment;

assertType('Vusys\QuantumSlipstreamDrive\Tests\Models\Comment|null', Comment::query()->find(1));
assertType('Vusys\QuantumSlipstreamDrive\Tests\Models\Comment|null', Comment::query()->find('1'));
assertType('Illuminate\Database\Eloquent\Collection<int, Vusys\QuantumSlipstreamDrive\Tests\Models\Comment>', Comment::query()->find([1, 2]));
assertType('Illuminate\Database\Eloquent\Collection<int, Vusys\QuantumSlipstreamDrive\Tests\Models\Comment>', Comment::query()->find(collect([1, 2])));
