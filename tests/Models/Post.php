<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Concerns\UsesContextConnection;
use Vusys\QuantumSlipstreamDrive\Tests\Factories\PostFactory;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Pivots\CastTagging;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $tag_id
 * @property int|null $label_id
 * @property string $title
 * @property bool $published
 * @property int $view_count
 * @property float|null $rating
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
    protected $fillable = ['user_id', 'tag_id', 'label_id', 'title', 'published', 'view_count', 'rating'];

    /** @var array<string, string> */
    protected $casts = [
        'published' => 'boolean',
        'view_count' => 'integer',
        'rating' => 'float',
    ];

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

    /** @return BelongsTo<Label, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot(['active', 'priority', 'role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Tag, $this, CastTagging, 'pivot'> */
    public function castTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag')
            ->using(CastTagging::class)
            ->withPivot(['active', 'priority', 'role'])
            ->withTimestamps();
    }
}
