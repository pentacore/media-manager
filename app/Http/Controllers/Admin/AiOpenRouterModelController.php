<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOpenRouterModelsRequest;
use App\Services\AiUsage\Pricing\OpenRouterModelImporter;
use App\Services\AiUsage\Pricing\PricingTransportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AiOpenRouterModelController extends Controller
{
    public function index(OpenRouterModelImporter $openRouterModelImporter): JsonResponse
    {
        try {
            return response()->json(['models' => $openRouterModelImporter->available()]);
        } catch (PricingTransportException $pricingTransportException) {
            return response()->json(['message' => $pricingTransportException->getMessage()], 502);
        }
    }

    public function store(
        StoreOpenRouterModelsRequest $storeOpenRouterModelsRequest,
        OpenRouterModelImporter $openRouterModelImporter,
    ): RedirectResponse {
        $validated = $storeOpenRouterModelsRequest->validated();

        try {
            $created = $openRouterModelImporter->import($validated['models']);
        } catch (PricingTransportException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('OpenRouter is unreachable — no models were added.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count OpenRouter model added.|:count OpenRouter models added.', $created, ['count' => $created])]);

        return back();
    }
}
