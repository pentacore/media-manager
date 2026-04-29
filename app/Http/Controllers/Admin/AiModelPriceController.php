<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\Agents\PriceFetcherAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelPriceRequest;
use App\Http\Requests\Admin\UpdateAiModelPriceRequest;
use App\Models\AiModelPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiModelPriceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/AiPrices/Index', [
            'prices' => AiModelPrice::query()
                ->orderBy('provider')
                ->orderBy('model')
                ->get(),
        ]);
    }

    public function store(StoreAiModelPriceRequest $storeAiModelPriceRequest): RedirectResponse
    {
        AiModelPrice::create($storeAiModelPriceRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price added.')]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiModelPriceRequest $updateAiModelPriceRequest, AiModelPrice $aiModelPrice): RedirectResponse
    {
        $aiModelPrice->update($updateAiModelPriceRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price updated.')]);

        return to_route('admin.ai-prices.index');
    }

    public function destroy(AiModelPrice $aiModelPrice): RedirectResponse
    {
        $aiModelPrice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price removed.')]);

        return to_route('admin.ai-prices.index');
    }

    /**
     * Spin up the PriceFetcherAgent. The agent visits provider pricing
     * pages with WebFetchTool and writes rates back via
     * UpsertModelPriceTool — no hardcoded catalog. Synchronous; finishes
     * in a few seconds because the agent only fans out to ~6 hosts.
     */
    public function refresh(): RedirectResponse
    {
        $before = AiModelPrice::query()->count();

        try {
            $response = (new PriceFetcherAgent)->prompt(
                'Refresh the catalog now. Visit the canonical pricing page for OpenAI, Anthropic, Google Gemini, DeepSeek, xAI, and Mistral. Upsert one row per generally-available text/chat model with up-to-date input, output, cache, and reasoning rates. Skip image / audio / embedding products.'
            );
        } catch (Throwable $throwable) {
            Log::error('PriceFetcherAgent run failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Price refresh failed: :msg', ['msg' => $throwable->getMessage()]),
            ]);

            return to_route('admin.ai-prices.index');
        }

        $after = AiModelPrice::query()->count();
        $added = max(0, $after - $before);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Refreshed via PriceFetcherAgent. :added new, :total total. :summary', [
                'added' => $added,
                'total' => $after,
                'summary' => mb_substr((string) $response->text, 0, 200),
            ]),
        ]);

        return to_route('admin.ai-prices.index');
    }
}
