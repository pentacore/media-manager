<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin ServiceConnection
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'type' => "'sonarr' | 'radarr' | 'emby' | 'seerr' | 'prowlarr'",
    'name' => 'string',
    'url' => 'string',
    'is_active' => 'boolean',
    'health_status' => "'healthy' | 'unhealthy' | 'unknown'",
    'health_message' => 'string | null',
    'version' => 'string | null',
    'latest_version' => 'string | null',
    'update_available' => 'boolean',
    'last_seen_at' => 'string | null',
    'last_seen_human' => 'string | null',
])]
class ServiceConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'url' => $this->url,
            'is_active' => $this->is_active,
            'health_status' => ($this->health_status ?? HealthStatus::Unknown)->value,
            'health_message' => $this->health_message,
            'version' => $this->version,
            'latest_version' => $this->latest_version,
            'update_available' => $this->latest_version !== null
                && $this->version !== null
                && $this->latest_version !== $this->version,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'last_seen_human' => $this->last_seen_at?->diffForHumans(),
        ];
    }
}
