<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;
use Vusys\QueryRicerExtreme\Tests\Factories\PostFactory;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $tag_id
 * @property string $title
 * @property bool $published
 */
final class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasIdentityMap;
    use UsesContextConnection;

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    /** @var list<string> */
    protected $fillable = ['user_id', 'tag_id', 'title', 'published'];

    /** @var array<string, string> */
    protected $casts = ['published' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
