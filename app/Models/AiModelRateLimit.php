<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $ai_model_price_id
 * @property RateLimitMetric $metric
 * @property RateLimitPeriod $period
 * @property int $limit_value
 * @property-read AiModelPrice $price
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiModelRateLimit newModelQuery()
 * @method static Builder<static>|AiModelRateLimit newQuery()
 * @method static Builder<static>|AiModelRateLimit query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'ai_model_price_id',
    'metric',
    'period',
    'limit_value',
])]
class AiModelRateLimit extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<AiModelPrice, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(AiModelPrice::class, 'ai_model_price_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metric' => RateLimitMetric::class,
            'period' => RateLimitPeriod::class,
            'limit_value' => 'integer',
        ];
    }
}
