<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;

/**
 * Identity-mapped through a builder that filters at execution time, and says so.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
final class AmbientAwareUser extends Model
{
    use HasIdentityMap;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];

    /**
     * @param  QueryBuilder  $query
     * @return AmbientAwareUserBuilder<self>
     */
    #[\Override]
    public function newEloquentBuilder($query): AmbientAwareUserBuilder
    {
        /** @var AmbientAwareUserBuilder<self> $builder */
        $builder = new AmbientAwareUserBuilder($query);

        return $builder;
    }
}
