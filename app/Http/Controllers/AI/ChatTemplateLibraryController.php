<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Enums\ChatTemplateVariableType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\SearchChatTemplateLibraryRequest;
use App\Services\Chat\ChatTemplateLibrary;
use Illuminate\Http\JsonResponse;

class ChatTemplateLibraryController extends Controller
{
    public function __invoke(SearchChatTemplateLibraryRequest $searchChatTemplateLibraryRequest, ChatTemplateLibrary $chatTemplateLibrary): JsonResponse
    {
        $validated = $searchChatTemplateLibraryRequest->validated();

        return response()->json([
            'items' => $chatTemplateLibrary->search(ChatTemplateVariableType::from($validated['type']), (string) $validated['q']),
        ]);
    }
}
