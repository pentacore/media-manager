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
 * Chat-surface removal of a stuck download from the Sonarr/Radarr queue,
 * optionally blocklisting the release so it is never grabbed again and/or
 * searching for a replacement release afterwards. Queues a
 * remove_stuck_download ActionRequest executed by RemoveStuckDownloadActions.
 */
class RemoveStuckDownloadChatTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Remove a stuck Sonarr/Radarr download from the queue and delete its data. Use when an '
            .'inspected stuck import should NOT be imported, e.g. "not an upgrade for existing file(s)". '
            .'Optionally pass blocklist=true to also blocklist the release so the arr never grabs it again '
            .'(use only when the release itself is bad — corrupt/fake/wrong content). Pass '
            .'search_replacement=true to have the arr immediately search for a replacement release after '
            .'removal (combine with blocklist=true to retry with a different release). ALWAYS inspect with '
            .'InspectStuckImportTool first and give a reason.';
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
        $reason = trim((string) ($args['reason'] ?? ''));

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
                'blocklist' => ($args['blocklist'] ?? null) === true,
                'search_replacement' => ($args['search_replacement'] ?? null) === true,
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
            'blocklist' => $schema->boolean()
                ->description('Also blocklist the release so the arr never grabs it again. Use when the release itself is bad (corrupt, fake, wrong content) — not when it merely isn\'t an upgrade. Default false.')
                ->required()
                ->nullable(),
            'search_replacement' => $schema->boolean()
                ->description('After removing, have the arr immediately search for a replacement release. Combine with blocklist=true to retry with a different release; leave false when the content should not be re-grabbed at all. Default false.')
                ->required()
                ->nullable(),
        ];
    }
}
