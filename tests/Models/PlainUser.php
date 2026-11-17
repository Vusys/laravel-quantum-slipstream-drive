<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The users table as an app that never heard of this package models it: no
 * HasIdentityMap trait. Served only under all-models mode.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property int|null $score
 * @property int|null $country_id
 */
final class PlainUser extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active', 'score', 'country_id'];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return BelongsTo<PlainCountry, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(PlainCountry::class, 'country_id');
    }
}
