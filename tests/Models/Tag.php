<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;

/**
 * @property int $id
 * @property string $name
 */
final class Tag extends Model
{
    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['name'];
}
