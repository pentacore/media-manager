<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Events\AiPriceRefreshStateChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelPriceRequest;
use App\Http\Requests\Admin\UpdateAiModelPriceRequest;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AiModelPriceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/AiPrices/Index', [
            'prices' => AiModelPrice::query()
                ->with('rateLimits')
                ->orderBy('provider')
                ->orderBy('model')
                ->get(),
            'pools' => AiFreeUsagePool::query()
                ->withCount('prices')
                ->orderBy('name')
                ->get(),
            'refresh_running' => RefreshAiPricesJob::isRunning(),
            'rate_limit_metrics' => RateLimitMetric::options(),
            'rate_limit_periods' => RateLimitPeriod::options(),
        ]);
    }

    public function store(StoreAiModelPriceRequest $storeAiModelPriceRequest): RedirectResponse
    {
        $validated = $storeAiModelPriceRequest->validated();
        $rateLimits = Arr::pull($validated, 'rate_limits') ?? [];

        $aiModelPrice = AiModelPrice::create($validated);
        $aiModelPrice->rateLimits()->createMany($rateLimits);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price added.')]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiModelPriceRequest $updateAiModelPriceRequest, AiModelPrice $aiModelPrice): RedirectResponse
    {
        $validated = $updateAiModelPriceRequest->validated();
        $rateLimits = Arr::pull($validated, 'rate_limits') ?? [];

        $aiModelPrice->update($validated);
        $aiModelPrice->rateLimits()->delete();
        $aiModelPrice->rateLimits()->createMany($rateLimits);

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
     * Queue a PriceFetcherAgent run. The agent reaches out to ~6 provider
     * pricing pages and can take 30+ seconds, so we hand it to the queue and
     * surface progress via the admin.ai-prices broadcast channel. A cache
     * lock guarantees only one refresh runs at a time across all admins.
     */
    public function refresh(): RedirectResponse
    {
        $user = Auth::user();

        if (! RefreshAiPricesJob::tryLock($user->id)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('A price refresh is already running. Wait for it to finish.'),
            ]);

            return to_route('admin.ai-prices.index');
        }

        // Fire QUEUED before dispatch so the broadcast ordering matches the
        // prod queue lifecycle even when tests run with QUEUE_CONNECTION=sync
        // (which invokes handle() inline during dispatch()).
        event(new AiPriceRefreshStateChanged(
            state: AiPriceRefreshStateChanged::STATE_QUEUED,
            triggeredBy: $user,
        ));

        dispatch(new RefreshAiPricesJob($user));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Price refresh queued. Updates will appear automatically.'),
        ]);

        return to_route('admin.ai-prices.index');
    }
}
