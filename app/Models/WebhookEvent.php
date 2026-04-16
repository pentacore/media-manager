<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property \Carbon\CarbonImmutable|null $processed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ActionRequest> $actionRequests
 * @property-read int|null $action_requests_count
 * @property-read \App\Models\ServiceConnection $serviceConnection
 * @method static \Database\Factories\WebhookEventFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereEventType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereServiceConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereUpdatedAt($value)
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
    #[\Override]
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
