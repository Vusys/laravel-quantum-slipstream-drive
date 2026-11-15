<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Vusys\QuantumSlipstreamDrive\HasIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\UsesThirdPartyBuilder;

/**
 * A model whose builder another package owns, given the identity map by
 * composing the two builders — the pattern the README documents.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
final class ComposedUser extends Model
{
    use HasIdentityMap, UsesThirdPartyBuilder {
        UsesThirdPartyBuilder::newEloquentBuilder insteadof HasIdentityMap;
    }

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];

    /**
     * @param  QueryBuilder  $query
     * @return ComposedUserBuilder<self>
     */
    #[\Override]
    public function newEloquentBuilder($query): ComposedUserBuilder
    {
        /** @var ComposedUserBuilder<self> $builder */
        $builder = new ComposedUserBuilder($query);

        return $builder;
    }
}
