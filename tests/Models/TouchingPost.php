<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

/**
 * A post that touches its user on save, through Eloquent's `$touches`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property int $view_count
 */
final class TouchingPost extends Model
{
    use HasIdentityMap;

    protected $table = 'posts';

    /** @var list<string> */
    protected $fillable = ['user_id', 'title', 'published', 'view_count'];

    /** @var list<string> */
    protected $touches = ['user'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
