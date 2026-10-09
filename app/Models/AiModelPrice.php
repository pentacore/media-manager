<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiReasoningLevel;
use App\Enums\PricingSource;
use App\Observers\AiModelPriceObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property string $search_unit_per_k
 * @property string|null $batch_search_unit_per_k
 * @property int|null $free_usage_pool_id
 * @property PricingSource|null $pricing_source
 * @property string|null $pricing_source_url
 * @property CarbonImmutable|null $pricing_source_updated_at
 * @property CarbonImmutable|null $pricing_synced_at
 * @property CarbonImmutable|null $pricing_verified_at
 * @property bool $is_price_locked
 * @property bool|null $supports_reasoning
 * @property list<string>|null $reasoning_levels
 * @property string|null $reasoning_style
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
    'search_unit_per_k',
    'batch_search_unit_per_k',
    'free_usage_pool_id',
    'pricing_source',
    'pricing_source_url',
    'pricing_source_updated_at',
    'pricing_synced_at',
    'pricing_verified_at',
    'is_price_locked',
    'supports_reasoning',
    'reasoning_levels',
    'reasoning_style',
])]
#[Appends(['automatic_updates_enabled'])]
#[ObservedBy(AiModelPriceObserver::class)]
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
            'search_unit_per_k' => 'decimal:4',
            'batch_search_unit_per_k' => 'decimal:4',
            'free_usage_pool_id' => 'integer',
            'supports_reasoning' => 'boolean',
            'reasoning_levels' => 'array',
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

    /**
     * The reasoning levels this model accepts, in scale order; null when the
     * feeds don't say, [] when it accepts none.
     *
     * @return list<AiReasoningLevel>|null
     */
    public function acceptedReasoningLevels(): ?array
    {
        if ($this->reasoning_levels === null) {
            return null;
        }

        $levels = array_values(array_filter(array_map(AiReasoningLevel::tryFrom(...), $this->reasoning_levels)));
        usort($levels, static fn (AiReasoningLevel $a, AiReasoningLevel $b): int => $a->rank() <=> $b->rank());

        return $levels;
    }
}
