<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that picks its own builder, as packages like nestedset do. All-models
 * mode must leave it alone.
 *
 * @property int $id
 * @property string $name
 */
final class OwnBuilderCountry extends Model
{
    protected $table = 'countries';

    protected static string $builder = Builder::class;

    /** @var list<string> */
    protected $fillable = ['name'];
}
