<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StatRollupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $metric
 * @property string $period
 * @property CarbonImmutable $bucket
 * @property array<array-key, mixed> $dimensions
 * @property int $count
 * @property float|null $sum
 * @property float|null $min
 * @property float|null $max
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static StatRollupFactory factory($count = null, $state = [])
 * @method static Builder<static>|StatRollup newModelQuery()
 * @method static Builder<static>|StatRollup newQuery()
 * @method static Builder<static>|StatRollup query()
 *
 * @mixin \Eloquent
 */
class StatRollup extends Model
{
    /** @use HasFactory<StatRollupFactory> */
    use HasFactory;

    #[Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'bucket' => 'immutable_datetime',
            'dimensions' => 'array',
            'count' => 'integer',
            'sum' => 'float',
            'min' => 'float',
            'max' => 'float',
        ];
    }
}
