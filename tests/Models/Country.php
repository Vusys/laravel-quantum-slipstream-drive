<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\UsesContextConnection;

/**
 * @property int $id
 * @property string $name
 */
final class Country extends Model
{
    use HasIdentityMap;
    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return HasManyThrough<Post, User, $this> */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class);
    }
}
