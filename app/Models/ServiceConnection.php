<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use Database\Factories\ServiceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
