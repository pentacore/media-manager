<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiPriceRefreshRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $mode
 * @property string $trigger
 * @property int|null $triggered_by_user_id
 * @property string $status
 * @property string|null $models_dev_status
 * @property int $providers_requested
 * @property int $providers_succeeded
 * @property int $providers_failed
 * @property int $models_created
 * @property int $models_updated
 * @property int $models_unchanged
 * @property int $models_locked
 * @property int $models_rejected
 * @property int $models_tiered
 * @property array<int, string>|null $fallback_targets
 * @property array<int, string>|null $unverified_targets
 * @property array<string, mixed>|null $provider_results
 * @property string|null $error_message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $triggeredBy
 *
 * @method static AiPriceRefreshRunFactory factory($count = null, $state = [])
 * @method static Builder<static>|AiPriceRefreshRun newModelQuery()
 * @method static Builder<static>|AiPriceRefreshRun newQuery()
 * @method static Builder<static>|AiPriceRefreshRun query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'mode',
    'trigger',
    'triggered_by_user_id',
    'status',
    'models_dev_status',
    'providers_requested',
    'providers_succeeded',
    'providers_failed',
    'models_created',
    'models_updated',
    'models_unchanged',
    'models_locked',
    'models_rejected',
    'models_tiered',
    'fallback_targets',
    'unverified_targets',
    'provider_results',
    'error_message',
    'started_at',
    'completed_at',
])]
class AiPriceRefreshRun extends Model
{
    /** @use HasFactory<AiPriceRefreshRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'triggered_by_user_id' => 'integer',
            'providers_requested' => 'integer',
            'providers_succeeded' => 'integer',
            'providers_failed' => 'integer',
            'models_created' => 'integer',
            'models_updated' => 'integer',
            'models_unchanged' => 'integer',
            'models_locked' => 'integer',
            'models_rejected' => 'integer',
            'models_tiered' => 'integer',
            'fallback_targets' => 'array',
            'unverified_targets' => 'array',
            'provider_results' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
