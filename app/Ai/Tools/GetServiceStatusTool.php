<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetServiceStatusTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get the current health status, version, and update-available flag for every configured service connection (Sonarr, Radarr, Emby, Seerr).';
    }

    public function handle(Request $request): Stringable|string
    {
        $connections = ServiceConnection::orderBy('type')->orderBy('name')->get();

        $data = $connections->map(fn (ServiceConnection $serviceConnection): array => [
            'id' => $serviceConnection->id,
            'name' => $serviceConnection->name,
            'type' => $serviceConnection->type->value,
            'is_active' => $serviceConnection->is_active,
            'health_status' => ($serviceConnection->health_status ?? HealthStatus::Unknown)->value,
            'version' => $serviceConnection->version,
            'latest_version' => $serviceConnection->latest_version,
            'update_available' => $serviceConnection->latest_version !== null
                && $serviceConnection->version !== null
                && $serviceConnection->latest_version !== $serviceConnection->version,
            'last_seen_at' => $serviceConnection->last_seen_at?->toISOString(),
        ])->all();

        return json_encode(['services' => $data]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
