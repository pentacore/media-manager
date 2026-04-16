<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\JsonSchema\Types\Type;
use App\Models\ActionRequest;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateActionRequestTool implements Tool
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    public function description(): Stringable|string
    {
        return 'Create a cross-service ActionRequest (e.g. delete_series, delete_movie, cleanup_seerr_request, emby_library_scan). '
            .'Actions with requires_approval=true will be queued for user approval; auto-execute actions run immediately. '
            .'Use ids from SearchMediaTool or GetServiceStatusTool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $args = $request->toArray();
        $type = (string) ($args['type'] ?? '');
        $sourceService = (string) ($args['source_service'] ?? 'ai');
        $targetService = (string) ($args['target_service'] ?? '');
        $payload = $args['payload'] ?? [];

        if (! is_array($payload)) {
            $payload = [];
        }

        if ($type === '' || $targetService === '') {
            return json_encode(['error' => 'type and target_service are required']);
        }

        $actionRequest = $this->actionOrchestrator->dispatch(
            type: $type,
            sourceService: $sourceService,
            targetService: $targetService,
            payload: $payload,
        );

        if (! $actionRequest instanceof ActionRequest) {
            return json_encode([
                'created' => false,
                'reason' => 'No ActionTypeConfig exists for type, or it is disabled.',
            ]);
        }

        return json_encode([
            'created' => true,
            'id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Action type slug such as delete_series, delete_movie, cleanup_seerr_request, emby_library_scan.')
                ->required(),
            'target_service' => $schema->string()
                ->description('Target service name (sonarr, radarr, emby, or seerr).')
                ->required(),
            'source_service' => $schema->string()
                ->description('Source service (defaults to "ai" when originating from the assistant).'),
            'payload' => $schema->object()
                ->description('Action-specific payload, e.g. {"sonarr_series_id": 42, "delete_files": true} for delete_series.'),
        ];
    }
}
