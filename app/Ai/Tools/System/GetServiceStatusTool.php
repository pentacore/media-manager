<?php

declare(strict_types=1);

namespace App\Ai\Tools\System;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetServiceStatusTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get the current health status, version, and update-available flag for every configured service connection (Sonarr, Radarr, Emby, Seerr, Prowlarr).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{services: array<int, array<string, mixed>>}
     */
    protected function execute(Request $request): array
    {
        $connections = ServiceConnection::orderBy('type')->orderBy('name')->get();

        $data = $connections->map(fn (ServiceConnection $sc): array => [
            'id' => $sc->id,
            'name' => $sc->name,
            'type' => $sc->type->value,
            'is_active' => $sc->is_active,
            'health_status' => ($sc->health_status ?? HealthStatus::Unknown)->value,
            'version' => $sc->version,
            'latest_version' => $sc->latest_version,
            'update_available' => $sc->latest_version !== null
                && $sc->version !== null
                && $sc->latest_version !== $sc->version,
            'last_seen_at' => $sc->last_seen_at?->toISOString(),
        ])->all();

        return ['services' => $data];
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
