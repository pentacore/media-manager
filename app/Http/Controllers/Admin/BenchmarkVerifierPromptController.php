<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewVerifierPromptRequest;
use App\Services\Benchmark\VerifierCaseInput;
use Illuminate\Http\JsonResponse;

class BenchmarkVerifierPromptController extends Controller
{
    public function __invoke(PreviewVerifierPromptRequest $previewVerifierPromptRequest): JsonResponse
    {
        return response()->json([
            'prompt' => VerifierCaseInput::prompt(VerifierCaseInput::normalize($previewVerifierPromptRequest->validated())),
        ]);
    }
}
