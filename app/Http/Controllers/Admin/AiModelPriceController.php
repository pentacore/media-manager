<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PricingSource;
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
    /**
     * The ten standard and batch per-MTok rate columns that constitute a
     * manual price edit. A change to any of these takes the row under manual
     * control; free-pool and rate-limit changes are deliberately excluded.
     *
     * @var list<string>
     */
    private const array PRICE_FIELDS = [
        'input_per_mtok',
        'output_per_mtok',
        'cache_read_per_mtok',
        'cache_write_per_mtok',
        'reasoning_per_mtok',
        'batch_input_per_mtok',
        'batch_output_per_mtok',
        'batch_cache_read_per_mtok',
        'batch_cache_write_per_mtok',
        'batch_reasoning_per_mtok',
    ];

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
        $automaticUpdatesEnabled = $this->pullBooleanFlag($validated, 'automatic_updates_enabled');

        // A manually entered price is owned by the admin: it defaults to
        // locked and marked manual so a sync never overwrites it, unless the
        // admin opts into automatic updates at creation time.
        $validated['pricing_source'] = PricingSource::Manual;
        $validated['is_price_locked'] = $automaticUpdatesEnabled !== true;

        $aiModelPrice = AiModelPrice::create($validated);
        $aiModelPrice->rateLimits()->createMany($rateLimits);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price added.')]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiModelPriceRequest $updateAiModelPriceRequest, AiModelPrice $aiModelPrice): RedirectResponse
    {
        $validated = $updateAiModelPriceRequest->validated();
        $rateLimits = Arr::pull($validated, 'rate_limits') ?? [];
        $automaticUpdatesEnabled = $this->pullBooleanFlag($validated, 'automatic_updates_enabled');

        $priceChanged = $this->pricingFieldsChanged($aiModelPrice, $validated);

        if ($automaticUpdatesEnabled === true) {
            // Re-enabling automatic updates unlocks the row. The source is left
            // untouched — the next sync will refresh it — so it stays manual
            // until then even though the price change (if any) is applied.
            $validated['is_price_locked'] = false;
        } elseif ($priceChanged) {
            // A manual price edit takes ownership of the row.
            $validated['is_price_locked'] = true;
            $validated['pricing_source'] = PricingSource::Manual;
        } elseif ($automaticUpdatesEnabled === false) {
            // Explicitly disabling automatic updates locks the row even when no
            // price field changed. The stored price's origin did not change, so
            // the pricing_source is deliberately left untouched.
            $validated['is_price_locked'] = true;
        }

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
     * Pull a nullable boolean flag out of the validated payload. The real HTML
     * form submits the string '1'/'0' from its hidden toggle input, so coerce
     * those (and genuine booleans) to a bool while preserving null for an
     * absent field — the update flow relies on that null to mean "unchanged".
     *
     * @param  array<string, mixed>  $validated
     */
    private function pullBooleanFlag(array &$validated, string $key): ?bool
    {
        $value = Arr::pull($validated, $key);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether any of the ten standard/batch price fields present in the
     * validated payload differs from the stored value. Comparison is done on
     * normalized 4-decimal strings to avoid binary float equality pitfalls.
     *
     * @param  array<string, mixed>  $validated
     */
    private function pricingFieldsChanged(AiModelPrice $aiModelPrice, array $validated): bool
    {
        foreach (self::PRICE_FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $existing = $this->normalizePrice($aiModelPrice->getAttribute($field));
            $incoming = $this->normalizePrice($validated[$field]);

            if ($existing !== $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a price value as a fixed 4-decimal string (or null) so two values
     * can be compared as strings rather than by float equality.
     */
    private function normalizePrice(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
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
