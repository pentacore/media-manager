<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;

final class InspectSubtitleTool extends BaseTool
{
    public function description(): string
    {
        return 'Inspect one exact Bazarr episode or movie using an explicit Bazarr connection and media ID. '
            .'Returns a compact sanitized subtitle inventory and recent history. Never guess any ID.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $arguments = $request->toArray();

        return resolve(SubtitleInventoryService::class)->inspect(
            $this->connection((int) ($arguments['bazarr_connection_id'] ?? 0)),
            (string) ($arguments['media_type'] ?? ''),
            (int) ($arguments['media_id'] ?? 0),
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'bazarr_connection_id' => $schema->integer()
                ->description('The exact active Bazarr connection ID.')
                ->required(),
            'media_type' => $schema->string()
                ->enum(['episode', 'movie'])
                ->description('The exact Bazarr media type.')
                ->required(),
            'media_id' => $schema->integer()
                ->description('The exact Sonarr episode ID or Radarr movie ID known to Bazarr.')
                ->required(),
        ];
    }

    private function connection(int $connectionId): ServiceConnection
    {
        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
