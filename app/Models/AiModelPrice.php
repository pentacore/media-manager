<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $batch_input_per_mtok
 * @property string|null $batch_output_per_mtok
 * @property string|null $batch_cache_read_per_mtok
 * @property string|null $batch_cache_write_per_mtok
 * @property string|null $batch_reasoning_per_mtok
 * @property int|null $free_usage_pool_id
 * @property-read AiFreeUsagePool|null $freeUsagePool
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
    'batch_input_per_mtok',
    'batch_output_per_mtok',
    'batch_cache_read_per_mtok',
    'batch_cache_write_per_mtok',
    'batch_reasoning_per_mtok',
    'free_usage_pool_id',
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
            'batch_input_per_mtok' => 'decimal:4',
            'batch_output_per_mtok' => 'decimal:4',
            'batch_cache_read_per_mtok' => 'decimal:4',
            'batch_cache_write_per_mtok' => 'decimal:4',
            'batch_reasoning_per_mtok' => 'decimal:4',
            'free_usage_pool_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiFreeUsagePool, $this>
     */
    public function freeUsagePool(): BelongsTo
    {
        return $this->belongsTo(AiFreeUsagePool::class);
    }
}
