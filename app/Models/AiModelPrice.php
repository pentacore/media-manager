<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property string $input_per_mtok
 * @property string $output_per_mtok
 * @property string $cache_read_per_mtok
 * @property string $cache_write_per_mtok
 * @property string $reasoning_per_mtok
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiModelPrice newModelQuery()
 * @method static Builder<static>|AiModelPrice newQuery()
 * @method static Builder<static>|AiModelPrice query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'provider',
    'model',
    'input_per_mtok',
    'output_per_mtok',
    'cache_read_per_mtok',
    'cache_write_per_mtok',
    'reasoning_per_mtok',
])]
class AiModelPrice extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'input_per_mtok' => 'decimal:4',
            'output_per_mtok' => 'decimal:4',
            'cache_read_per_mtok' => 'decimal:4',
            'cache_write_per_mtok' => 'decimal:4',
            'reasoning_per_mtok' => 'decimal:4',
        ];
    }
}
