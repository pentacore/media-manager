<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\ManualImportResolver;
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
            .'held for human approval. Sample files are skipped automatically; pass excluded_paths to leave out other '
            .'candidate files (extras or a wrong file). If the download should NOT be imported (e.g. "not an upgrade"), use '
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
        $validated = $request->validate([
            'download_id' => ['required', 'string'],
            'reason' => ['required', 'string'],
            'excluded_paths' => ['nullable', 'array', 'max:50'],
            'excluded_paths.*' => ['string', 'max:1024'],
        ], [
            'download_id.required' => 'download_id is required.',
            'download_id.string' => 'download_id must be a string.',
            'reason.required' => 'reason is required so the approver understands the decision.',
            'reason.string' => 'reason must be a short plain-text explanation for the approver.',
        ]);
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $downloadId = (string) $validated['download_id'];
        $reason = trim((string) $validated['reason']);
        $excludedPaths = array_values(array_filter((array) ($validated['excluded_paths'] ?? []), is_string(...)));

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException('service must be "sonarr" or "radarr".'),
        };

        $serviceConnection = ServiceConnection::resolveActive($type);
        $client = resolve(ArrConnections::class)->client($serviceConnection);

        $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        $assessment = resolve(ManualImportResolver::class)->assess($candidates, $service, $downloadId, $excludedPaths);

        throw_if($assessment['importable'] === 0, InvalidArgumentException::class, 'No candidate file can be mapped to a series/movie — nothing to import. '
        .'Use RemoveStuckDownloadChatTool or leave it for a human.');

        $partial = ! $assessment['fully_mapped'];

        return [
            'type' => 'resolve_manual_import',
            'source_service' => 'ai',
            'target_service' => $service,
            'payload' => [
                'service' => $service,
                'download_id' => $downloadId,
                'excluded_paths' => $excludedPaths,
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
            'excluded_paths' => $schema->array()
                ->items($schema->string())
                ->description('Optional: candidate paths to leave out of the import (e.g. extras or a wrong file). Samples are skipped automatically.'),
        ];
    }
}
