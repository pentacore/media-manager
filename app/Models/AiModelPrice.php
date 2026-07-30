<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PricingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property PricingSource|null $pricing_source
 * @property string|null $pricing_source_url
 * @property CarbonImmutable|null $pricing_source_updated_at
 * @property CarbonImmutable|null $pricing_synced_at
 * @property CarbonImmutable|null $pricing_verified_at
 * @property bool $is_price_locked
 * @property-read bool $automatic_updates_enabled
 * @property-read AiFreeUsagePool|null $freeUsagePool
 * @property-read Collection<int, AiModelRateLimit> $rateLimits
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
    'pricing_source',
    'pricing_source_url',
    'pricing_source_updated_at',
    'pricing_synced_at',
    'pricing_verified_at',
    'is_price_locked',
])]
#[Appends(['automatic_updates_enabled'])]
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
            'pricing_source' => PricingSource::class,
            'pricing_source_updated_at' => 'immutable_date',
            'pricing_synced_at' => 'immutable_datetime',
            'pricing_verified_at' => 'immutable_datetime',
            'is_price_locked' => 'boolean',
        ];
    }

    /**
     * Whether automatic price syncs may overwrite this row. Derived from the
     * lock flag: a locked row is under manual control and opts out of syncs.
     *
     * @return Attribute<bool, never>
     */
    protected function automaticUpdatesEnabled(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->is_price_locked);
    }

    /**
     * @return BelongsTo<AiFreeUsagePool, $this>
     */
    public function freeUsagePool(): BelongsTo
    {
        return $this->belongsTo(AiFreeUsagePool::class);
    }

    /**
     * @return HasMany<AiModelRateLimit, $this>
     */
    public function rateLimits(): HasMany
    {
        return $this->hasMany(AiModelRateLimit::class);
    }
}
