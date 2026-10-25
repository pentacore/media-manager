<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\Decision\DecisionPrompt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewDecisionPromptRequest;
use Illuminate\Http\JsonResponse;

class BenchmarkDecisionPromptController extends Controller
{
    public function __invoke(PreviewDecisionPromptRequest $previewDecisionPromptRequest): JsonResponse
    {
        $validated = $previewDecisionPromptRequest->validated();

        return response()->json([
            'prompt' => DecisionPrompt::build(
                $validated['service'],
                $validated['event_type'],
                DecisionPrompt::payloadFromJson($validated['payload_json']) ?? [],
            ),
        ]);
    }
}
