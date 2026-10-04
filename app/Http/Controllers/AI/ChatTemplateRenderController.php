<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\RenderChatTemplateRequest;
use App\Models\ChatTemplate;
use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Http\JsonResponse;

class ChatTemplateRenderController extends Controller
{
    public function __invoke(
        RenderChatTemplateRequest $renderChatTemplateRequest,
        ChatTemplate $chatTemplate,
        ChatTemplateRenderer $chatTemplateRenderer,
    ): JsonResponse {
        $validated = $renderChatTemplateRequest->validated();

        $text = $chatTemplateRenderer->render($chatTemplate, $validated['values'] ?? []);

        $chatTemplate->forceFill(['last_used_at' => now()])->save();

        return response()->json(['text' => $text]);
    }
}
