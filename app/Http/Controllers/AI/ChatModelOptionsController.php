<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\ModelCatalog;
use App\Ai\ReasoningOptions;
use App\Enums\AiReasoningLevel;
use App\Http\Controllers\Controller;
use App\Services\Chat\ConversationModelOverride;
use Illuminate\Http\JsonResponse;

/**
 * The options behind the chat's per-conversation model picker: the admin
 * chat default plus the same catalog the AI Models page offers.
 */
class ChatModelOptionsController extends Controller
{
    public function __invoke(ConversationModelOverride $conversationModelOverride, ModelCatalog $modelCatalog): JsonResponse
    {
        return response()->json([
            'defaults' => $conversationModelOverride->chatDefaults(),
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'modelCapabilities' => $modelCatalog->reasoningCapabilities(),
            'reasoningProviders' => array_values(array_filter($modelCatalog->textProviders(), ReasoningOptions::supportsProvider(...))),
        ]);
    }
}
