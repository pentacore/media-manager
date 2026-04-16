<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $service_connection_id
 * @property string $event_type
 * @property array<array-key, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ActionRequest> $actionRequests
 * @property-read int|null $action_requests_count
 * @property-read ServiceConnection $serviceConnection
 * @method static WebhookEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|WebhookEvent newModelQuery()
 * @method static Builder<static>|WebhookEvent newQuery()
 * @method static Builder<static>|WebhookEvent query()
 * @method static Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static Builder<static>|WebhookEvent whereEventType($value)
 * @method static Builder<static>|WebhookEvent whereId($value)
 * @method static Builder<static>|WebhookEvent wherePayload($value)
 * @method static Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static Builder<static>|WebhookEvent whereServiceConnectionId($value)
 * @method static Builder<static>|WebhookEvent whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[Fillable(['service_connection_id', 'event_type', 'payload', 'processed_at'])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return HasMany<ActionRequest, $this>
     */
    public function actionRequests(): HasMany
    {
        return $this->hasMany(ActionRequest::class);
    }

    public function markProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }
}
