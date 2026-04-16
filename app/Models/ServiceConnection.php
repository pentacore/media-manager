<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Observers\ServiceConnectionObserver;
use Database\Factories\ServiceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ServiceType $type
 * @property string $name
 * @property string $url
 * @property string $api_key
 * @property string $webhook_token
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable|null $last_seen_at
 * @property string|null $version
 * @property array<array-key, mixed>|null $settings
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property HealthStatus|null $health_status
 * @property string|null $latest_version
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WebhookEvent> $webhookEvents
 * @property-read int|null $webhook_events_count
 * @method static \Database\Factories\ServiceConnectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereHealthStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereLatestVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServiceConnection whereWebhookToken($value)
 * @mixin \Eloquent
 */
#[ObservedBy(ServiceConnectionObserver::class)]
#[Fillable(['type', 'name', 'url', 'api_key', 'webhook_token', 'is_active', 'version', 'latest_version', 'health_status', 'last_seen_at', 'settings'])]
class ServiceConnection extends Model
{
    /** @use HasFactory<ServiceConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'health_status' => HealthStatus::class,
            'api_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<WebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * @throws ModelNotFoundException
     */
    public static function resolveActive(ServiceType $serviceType): self
    {
        return self::where('type', $serviceType)->where('is_active', true)->firstOrFail();
    }
}
