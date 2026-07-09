<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FreePoolOverflowBehavior;
use App\Enums\FreeUsagePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property FreeUsagePeriod $period
 * @property bool $unified
 * @property int|null $free_input_tokens
 * @property int|null $free_output_tokens
 * @property int|null $free_total_tokens
 * @property FreePoolOverflowBehavior $overflow_behavior
 * @property string|null $documentation_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiFreeUsagePool newModelQuery()
 * @method static Builder<static>|AiFreeUsagePool newQuery()
 * @method static Builder<static>|AiFreeUsagePool query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name',
    'period',
    'unified',
    'free_input_tokens',
    'free_output_tokens',
    'free_total_tokens',
    'overflow_behavior',
    'documentation_url',
])]
class AiFreeUsagePool extends Model
{
    use HasFactory;

    /**
     * @return HasMany<AiModelPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AiModelPrice::class, 'free_usage_pool_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'period' => FreeUsagePeriod::class,
            'unified' => 'boolean',
            'free_input_tokens' => 'integer',
            'free_output_tokens' => 'integer',
            'free_total_tokens' => 'integer',
            'overflow_behavior' => FreePoolOverflowBehavior::class,
        ];
    }
}
