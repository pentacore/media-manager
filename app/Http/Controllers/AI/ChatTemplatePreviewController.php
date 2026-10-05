<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\PreviewChatTemplateRequest;
use App\Services\Chat\ChatTemplateDefinition;
use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Http\JsonResponse;

class ChatTemplatePreviewController extends Controller
{
    public function __invoke(
        PreviewChatTemplateRequest $previewChatTemplateRequest,
        ChatTemplateRenderer $chatTemplateRenderer,
        ChatTemplateDefinition $chatTemplateDefinition,
    ): JsonResponse {
        $validated = $previewChatTemplateRequest->validated();
        $body = (string) ($validated['body'] ?? '');
        $variables = $validated['variables'] ?? [];

        return response()->json([
            'segments' => $chatTemplateRenderer->preview($body, $variables),
            // An object even when empty, so the client always reads a map.
            'errors' => (object) $chatTemplateDefinition->errors($body, $variables),
        ]);
    }
}
