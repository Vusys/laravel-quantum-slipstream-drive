<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property Carbon|null $deleted_at
 */
final class UuidUser extends Model
{
    use HasIdentityMap;
    use SoftDeletes;

    protected $table = 'uuid_users';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'name', 'email', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
    ];
}
