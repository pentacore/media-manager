<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Chat-surface removal of a stuck download from the Sonarr/Radarr queue
 * (no blocklist, no re-download). Queues a remove_stuck_download
 * ActionRequest executed by RemoveStuckDownloadActions.
 */
class RemoveStuckDownloadChatTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Remove a stuck Sonarr/Radarr download from the queue and delete its data (without '
            .'blocklisting). Use when an inspected stuck import should NOT be imported, e.g. "not an upgrade '
            .'for existing file(s)". ALWAYS inspect with InspectStuckImportTool first and give a reason.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $downloadId = (string) ($args['download_id'] ?? '');
        $reason = (string) ($args['reason'] ?? '');

        throw_unless(in_array($service, ['sonarr', 'radarr'], true), InvalidArgumentException::class, 'service must be "sonarr" or "radarr".');

        throw_if($downloadId === '', InvalidArgumentException::class, 'download_id is required.');

        throw_if($reason === '', InvalidArgumentException::class, 'reason is required so the approver understands why removal beats importing.');

        return [
            'type' => 'remove_stuck_download',
            'source_service' => 'ai',
            'target_service' => $service,
            'payload' => [
                'service' => $service,
                'download_id' => $downloadId,
                'agent_rationale' => mb_substr($reason, 0, 1000),
            ],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->description('The arr service the stuck download belongs to: "sonarr" or "radarr".')
                ->required(),
            'download_id' => $schema->string()
                ->description('The downloadId of the stuck download.')
                ->required(),
            'reason' => $schema->string()
                ->description('Short plain-English reason for removing rather than importing.')
                ->required(),
        ];
    }
}
