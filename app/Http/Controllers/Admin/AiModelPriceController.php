<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelPriceRequest;
use App\Http\Requests\Admin\UpdateAiModelPriceRequest;
use App\Models\AiModelPrice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
}
