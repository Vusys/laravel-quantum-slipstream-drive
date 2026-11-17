<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The countries table with no HasIdentityMap trait. Served only under
 * all-models mode.
 *
 * @property int $id
 * @property string $name
 */
final class PlainCountry extends Model
{
    protected $table = 'countries';

    /** @var list<string> */
    protected $fillable = ['name'];
}
