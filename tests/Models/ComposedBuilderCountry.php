<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that picks its own builder and composes the identity map onto it, without
 * the HasIdentityMap trait. All-models mode must cover it.
 *
 * @property int $id
 * @property string $name
 */
final class ComposedBuilderCountry extends Model
{
    protected $table = 'countries';

    protected static string $builder = ComposedUserBuilder::class;

    /** @var list<string> */
    protected $fillable = ['name'];
}
