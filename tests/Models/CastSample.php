<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\UsesContextConnection;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Enums\SampleStatus;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $happened_at
 * @property CarbonImmutable|null $archived_at
 * @property array<string, mixed>|null $payload
 * @property SampleStatus|null $status
 * @property string|null $amount
 * @property string|null $secret
 */
final class CastSample extends Model
{
    use HasIdentityMap;
    use UsesContextConnection;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name', 'happened_at', 'archived_at', 'payload', 'status', 'amount', 'secret'];

    /** @var array<string, string> */
    protected $casts = [
        'happened_at' => 'datetime',
        'archived_at' => 'immutable_datetime',
        'payload' => 'array',
        'status' => SampleStatus::class,
        'amount' => 'decimal:2',
        'secret' => 'encrypted',
    ];
}
