<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;
use Vusys\QueryRicerExtreme\Tests\Factories\TagFactory;

/**
 * @property int $id
 * @property string $name
 */
final class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['name'];

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
