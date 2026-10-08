<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AiReasoningLevel;
use App\Enums\PricingSource;
use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Enums\SettingsGroup;
use App\Events\AiPriceRefreshStateChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkDestroyAiModelPriceRequest;
use App\Http\Requests\Admin\BulkUpdateAiModelPriceRequest;
use App\Http\Requests\Admin\StoreAiModelPriceRequest;
use App\Http\Requests\Admin\UpdateAiModelPriceRequest;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiModelPriceController extends Controller
{
    /**
     * The standard and batch per-MTok rate columns, plus the per-1k
     * search-unit rates, that constitute a manual price edit. A change to any of these takes the row under manual
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
        'search_unit_per_k',
        'batch_search_unit_per_k',
    ];

    public function index(CatalogModelBrowser $catalogModelBrowser): Response
    {
        return Inertia::render('Admin/AiPrices/Index', [
            'catalog_providers' => $catalogModelBrowser->providers(),
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

    public function store(StoreAiModelPriceRequest $storeAiModelPriceRequest, CatalogModelBrowser $catalogModelBrowser, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $storeAiModelPriceRequest->validated();
        $rateLimits = Arr::pull($validated, 'rate_limits') ?? [];
        $automaticUpdatesEnabled = $this->pullBooleanFlag($validated, 'automatic_updates_enabled');
        $fromCatalog = $this->pullBooleanFlag($validated, 'from_catalog');
        $this->zeroBlankSearchUnitRate($validated);

        // A manually entered price is owned by the admin: it defaults to
        // locked and marked manual so a sync never overwrites it, unless the
        // admin opts into automatic updates at creation time.
        $validated['pricing_source'] = PricingSource::Manual;
        $validated['is_price_locked'] = $automaticUpdatesEnabled !== true;

        // A price picked from the catalog and saved unedited with automatic
        // updates on keeps the feed's provenance, so it reads as synced. When
        // it stays manual instead, tell a cold cache (the pick sat past the
        // catalog TTL, so nothing was cached to compare against) apart from a
        // genuine mismatch (cached, but the submitted rates differ or the
        // model is gone): only the cold-cache case needs an explanation, the
        // mismatch keeps the ordinary toast.
        $catalogExpired = false;

        if ($fromCatalog === true && $automaticUpdatesEnabled === true) {
            $catalogAttributes = $catalogModelBrowser->catalogAttributes($validated['provider'], $validated['model'], $validated);

            if ($catalogAttributes !== null) {
                $validated = [...$validated, ...$catalogAttributes];
            } elseif (! $catalogModelBrowser->isCached($validated['provider'])) {
                $catalogExpired = true;
            }
        }

        DB::transaction(function () use ($validated, $rateLimits, $auditLogger): void {
            $aiModelPrice = AiModelPrice::create($validated);
            $aiModelPrice->rateLimits()->createMany($rateLimits);

            $auditLogger->settingsUpdated(
                SettingsGroup::AiModelPrices,
                [],
                $this->auditSnapshot($aiModelPrice->refresh()),
                ['operation' => 'created', 'record_id' => $aiModelPrice->id],
                sprintf('Added the AI model price for %s/%s.', $aiModelPrice->provider, $aiModelPrice->model),
            );
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $catalogExpired
                ? __("Model price added as a manual price — the catalog had expired, so its feed source wasn't recorded. The next price refresh will sync it.")
                : __('Model price added.'),
        ]);

        return to_route('admin.ai-prices.index');
    }

    public function update(UpdateAiModelPriceRequest $updateAiModelPriceRequest, AiModelPrice $aiModelPrice, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $this->auditSnapshot($aiModelPrice);
        $validated = $updateAiModelPriceRequest->validated();
        $rateLimits = Arr::pull($validated, 'rate_limits') ?? [];
        $automaticUpdatesEnabled = $this->pullBooleanFlag($validated, 'automatic_updates_enabled');
        $this->zeroBlankSearchUnitRate($validated);
        $this->normalizeReasoningCapability($validated);

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

        DB::transaction(function () use ($aiModelPrice, $validated, $rateLimits, $auditLogger, $before): void {
            $aiModelPrice->update($validated);
            $aiModelPrice->rateLimits()->delete();
            $aiModelPrice->rateLimits()->createMany($rateLimits);

            $auditLogger->settingsUpdated(
                SettingsGroup::AiModelPrices,
                $before,
                $this->auditSnapshot($aiModelPrice),
                ['operation' => 'updated', 'record_id' => $aiModelPrice->id],
                sprintf('Updated the AI model price for %s/%s.', $aiModelPrice->provider, $aiModelPrice->model),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price updated.')]);

        return to_route('admin.ai-prices.index');
    }

    public function destroy(AiModelPrice $aiModelPrice, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $this->auditSnapshot($aiModelPrice);

        DB::transaction(function () use ($aiModelPrice, $auditLogger, $before): void {
            $aiModelPrice->delete();

            $auditLogger->settingsUpdated(
                SettingsGroup::AiModelPrices,
                $before,
                [],
                ['operation' => 'deleted', 'record_id' => $aiModelPrice->id],
                sprintf('Removed the AI model price for %s/%s.', $aiModelPrice->provider, $aiModelPrice->model),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model price removed.')]);

        return to_route('admin.ai-prices.index');
    }

    /**
     * Apply the same automatic-updates, free-pool and rate-limit settings to
     * every selected row; a field the request leaves out stays unchanged.
     * Like the single-row edit, toggling automatic updates only flips the
     * lock and never rewrites the stored price's pricing_source, and every
     * changed row gets the single edit's audit row (marked bulk), committed
     * with the change.
     */
    public function bulkUpdate(BulkUpdateAiModelPriceRequest $bulkUpdateAiModelPriceRequest, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $bulkUpdateAiModelPriceRequest->validated();
        $ids = Arr::pull($validated, 'ids');
        $automaticUpdatesEnabled = $this->pullBooleanFlag($validated, 'automatic_updates_enabled');
        $rateLimits = Arr::pull($validated, 'rate_limits');

        $attributes = Arr::only($validated, ['free_usage_pool_id']);

        if ($automaticUpdatesEnabled !== null) {
            $attributes['is_price_locked'] = ! $automaticUpdatesEnabled;
        }

        DB::transaction(function () use ($ids, $attributes, $rateLimits, $auditLogger): void {
            $aiModelPrices = AiModelPrice::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            foreach ($aiModelPrices as $aiModelPrice) {
                $before = $this->auditSnapshot($aiModelPrice);

                if ($attributes !== []) {
                    $aiModelPrice->update($attributes);
                }

                if ($rateLimits !== null) {
                    $aiModelPrice->rateLimits()->delete();
                    $aiModelPrice->rateLimits()->createMany($rateLimits);
                }

                $auditLogger->settingsUpdated(
                    SettingsGroup::AiModelPrices,
                    $before,
                    $this->auditSnapshot($aiModelPrice),
                    ['operation' => 'updated', 'record_id' => $aiModelPrice->id, 'bulk' => true],
                    sprintf('Updated the AI model price for %s/%s.', $aiModelPrice->provider, $aiModelPrice->model),
                );
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count model price updated.|:count model prices updated.', count($ids)),
        ]);

        return to_route('admin.ai-prices.index');
    }

    /**
     * Remove every selected row with the single delete's audit row (marked
     * bulk), all in one transaction. Rate limits go with their price
     * (cascading foreign key).
     */
    public function bulkDestroy(BulkDestroyAiModelPriceRequest $bulkDestroyAiModelPriceRequest, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $bulkDestroyAiModelPriceRequest->validated();

        $removed = DB::transaction(function () use ($validated, $auditLogger): int {
            $aiModelPrices = AiModelPrice::query()->whereKey($validated['ids'])->orderBy('id')->lockForUpdate()->get();

            foreach ($aiModelPrices as $aiModelPrice) {
                $before = $this->auditSnapshot($aiModelPrice);
                $aiModelPrice->delete();

                $auditLogger->settingsUpdated(
                    SettingsGroup::AiModelPrices,
                    $before,
                    [],
                    ['operation' => 'deleted', 'record_id' => $aiModelPrice->id, 'bulk' => true],
                    sprintf('Removed the AI model price for %s/%s.', $aiModelPrice->provider, $aiModelPrice->model),
                );
            }

            return $aiModelPrices->count();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count model price removed.|:count model prices removed.', $removed),
        ]);

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
     * Translate the edit form's reasoning fields into column values: the
     * yes/no/unknown select becomes a nullable boolean, and an empty level set
     * means unknown (null), otherwise the levels are stored in scale order.
     *
     * @param  array<string, mixed>  $validated
     */
    private function normalizeReasoningCapability(array &$validated): void
    {
        if (array_key_exists('supports_reasoning', $validated)) {
            $validated['supports_reasoning'] = match ($validated['supports_reasoning']) {
                'yes' => true,
                'no' => false,
                default => null,
            };
        }

        if (array_key_exists('reasoning_levels', $validated)) {
            $levels = array_map(AiReasoningLevel::from(...), $validated['reasoning_levels']);
            usort($levels, static fn (AiReasoningLevel $a, AiReasoningLevel $b): int => $a->rank() <=> $b->rank());

            $validated['reasoning_levels'] = $levels === []
                ? null
                : array_values(array_unique(array_map(static fn (AiReasoningLevel $level): string => $level->value, $levels)));
        }
    }

    /**
     * The standard search-unit rate column is NOT NULL (token-only models
     * bill zero search units), so a blank form field means "free", not
     * "unset". The batch rate stays nullable like the other batch columns.
     *
     * @param  array<string, mixed>  $validated
     */
    private function zeroBlankSearchUnitRate(array &$validated): void
    {
        if (array_key_exists('search_unit_per_k', $validated) && $validated['search_unit_per_k'] === null) {
            $validated['search_unit_per_k'] = 0;
        }
    }

    /**
     * Whether any of the standard/batch price fields present in the
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

    /**
     * The price row plus its rate limits, which the edit form replaces as a
     * whole, so they diff as one list. `automatic_updates_enabled` is derived
     * from `is_price_locked` (the model appends it for display), so it is
     * excluded here — otherwise every lock toggle would double up as two
     * "changed" fields instead of one.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(AiModelPrice $aiModelPrice): array
    {
        return [
            ...Arr::except(AuditChanges::snapshot($aiModelPrice), ['automatic_updates_enabled']),
            'rate_limits' => $aiModelPrice->rateLimits()
                ->orderBy('id')
                ->get()
                ->map(fn (AiModelRateLimit $aiModelRateLimit): array => [
                    'metric' => $aiModelRateLimit->metric->value,
                    'period' => $aiModelRateLimit->period->value,
                    'limit_value' => $aiModelRateLimit->limit_value,
                ])
                ->values()
                ->all(),
        ];
    }
}
