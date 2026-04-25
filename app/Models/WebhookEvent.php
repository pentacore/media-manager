<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property int $service_connection_id
 * @property string $event_type
 * @property array<array-key, mixed> $payload
 * @property string|null $payload_hash
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ActionRequest> $actionRequests
 * @property-read int|null $action_requests_count
 * @property-read ServiceConnection $serviceConnection
 *
 * @method static WebhookEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|WebhookEvent newModelQuery()
 * @method static Builder<static>|WebhookEvent newQuery()
 * @method static Builder<static>|WebhookEvent query()
 * @method static Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static Builder<static>|WebhookEvent whereEventType($value)
 * @method static Builder<static>|WebhookEvent whereId($value)
 * @method static Builder<static>|WebhookEvent wherePayload($value)
 * @method static Builder<static>|WebhookEvent wherePayloadHash($value)
 * @method static Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static Builder<static>|WebhookEvent whereServiceConnectionId($value)
 * @method static Builder<static>|WebhookEvent whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['service_connection_id', 'event_type', 'payload', 'payload_hash', 'processed_at'])]
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

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(self::normalizePayload($payload), JSON_THROW_ON_ERROR));
    }

    private static function normalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalizePayload(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $nestedValue) {
            $value[$key] = self::normalizePayload($nestedValue);
        }

        return $value;
    }
}
