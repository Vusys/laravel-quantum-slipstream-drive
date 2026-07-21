<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\UsesContextConnection;

/**
 * @property int $id
 * @property string $title
 * @property bool $published
 */
final class Video extends Model
{
    use HasIdentityMap;
    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['title', 'published'];

    /** @var array<string, string> */
    protected $casts = ['published' => 'boolean'];

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
