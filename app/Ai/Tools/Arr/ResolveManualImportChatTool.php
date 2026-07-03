<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ManualImportResolver;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Chat-surface variant of the DecisionAgent's manual-import resolution.
 * Queues a resolve_manual_import ActionRequest (executed by
 * ManualImportActions). Partially-mapped candidate sets always require
 * human approval, mirroring the DecisionAgent rule.
 */
class ResolveManualImportChatTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Import a stuck Sonarr/Radarr download. ALWAYS inspect first with InspectStuckImportTool and '
            .'tell the user what you found. Queues an ActionRequest; partially-mapped file sets are always '
            .'held for human approval. If the download should NOT be imported (e.g. "not an upgrade"), use '
            .'RemoveStuckDownloadChatTool instead.';
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

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException('service must be "sonarr" or "radarr".'),
        };

        if ($downloadId === '') {
            throw new InvalidArgumentException('download_id is required.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('reason is required so the approver understands the decision.');
        }

        $connection = ServiceConnection::resolveActive($type);
        $client = $type === ServiceType::Sonarr
            ? new SonarrClient($connection)
            : new RadarrClient($connection);

        $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        $assessment = resolve(ManualImportResolver::class)->assess($candidates, $service, $downloadId);

        if ($assessment['importable'] === 0) {
            throw new InvalidArgumentException(
                'No candidate file can be mapped to a series/movie — nothing to import. '
                .'Use RemoveStuckDownloadChatTool or leave it for a human.',
            );
        }

        $partial = ! $assessment['fully_mapped'];

        return [
            'type' => 'resolve_manual_import',
            'source_service' => 'ai',
            'target_service' => $service,
            'payload' => [
                'service' => $service,
                'download_id' => $downloadId,
                'assessment' => $assessment,
                'agent_rationale' => mb_substr($reason, 0, 1000),
            ],
            'force_requires_approval' => $partial,
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
                ->description('The downloadId of the stuck download (from GetDownloadQueueTool or InspectStuckImportTool).')
                ->required(),
            'reason' => $schema->string()
                ->description('Short plain-English justification for importing (shown to the human approver).')
                ->required(),
        ];
    }
}
