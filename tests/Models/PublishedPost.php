<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * Post variant with a non-soft-delete global scope for fingerprinter tests.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property bool $published
 */
final class PublishedPost extends Model
{
    use HasIdentityMap;

    protected $table = 'posts';

    /** @var list<string> */
    protected $fillable = ['user_id', 'tag_id', 'title', 'published'];

    /** @var array<string, string> */
    protected $casts = ['published' => 'boolean'];

    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope('published', static function (Builder $builder): void {
            $builder->where('published', true);
        });
    }
}
