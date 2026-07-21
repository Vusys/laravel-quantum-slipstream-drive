<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\UsesContextConnection;

/**
 * @property int $id
 * @property string $imageable_type
 * @property int $imageable_id
 * @property string $url
 * @property bool $primary
 */
final class Image extends Model
{
    use HasIdentityMap;
    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['imageable_type', 'imageable_id', 'url', 'primary'];

    /** @var array<string, string> */
    protected $casts = ['primary' => 'boolean'];

    /** @return MorphTo<Model, $this> */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
