<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

/**
 * Identity-mapped through a builder that filters at execution time.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
final class AmbientUser extends Model
{
    use HasIdentityMap;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];

    /**
     * @param  QueryBuilder  $query
     * @return AmbientUserBuilder<self>
     */
    #[\Override]
    public function newEloquentBuilder($query): AmbientUserBuilder
    {
        /** @var AmbientUserBuilder<self> $builder */
        $builder = new AmbientUserBuilder($query);

        return $builder;
    }
}
