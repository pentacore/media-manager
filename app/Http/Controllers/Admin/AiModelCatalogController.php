<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCatalogModelPricesRequest;
use App\Services\AiUsage\Pricing\AiModelPriceWriter;
use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use App\Services\AiUsage\Pricing\CatalogUnavailableException;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AiModelCatalogController extends Controller
{
    /**
     * Catalog models for one provider that have no price row yet, for the
     * admin "add from catalog" pickers. `covered` is false when no enabled
     * feed prices the provider at all.
     */
    public function index(string $provider, CatalogModelBrowser $catalogModelBrowser): JsonResponse
    {
        abort_unless(in_array($provider, $catalogModelBrowser->providers(), true), 404);

        try {
            $options = $catalogModelBrowser->available($provider);
            $covered = $catalogModelBrowser->covers($provider);
        } catch (CatalogUnavailableException $catalogUnavailableException) {
            return response()->json(['message' => $catalogUnavailableException->getMessage()], 503);
        }

        return response()->json([
            'models' => array_map(fn (CatalogModelOption $catalogModelOption): array => $catalogModelOption->toArray(), $options),
            'covered' => $covered,
        ]);
    }

    /**
     * Add the admin's picked catalog models. Prices always come from the
     * catalog, never the request, and each row is written through the pricing
     * writer so it carries feed provenance and keeps syncing.
     */
    public function store(
        StoreCatalogModelPricesRequest $storeCatalogModelPricesRequest,
        CatalogModelBrowser $catalogModelBrowser,
        AiModelPriceWriter $aiModelPriceWriter,
    ): RedirectResponse {
        $validated = $storeCatalogModelPricesRequest->validated();
        /** @var string $provider */
        $provider = $validated['provider'];
        /** @var list<string> $models */
        $models = $validated['models'];

        try {
            $candidates = $catalogModelBrowser->addableCandidates($provider, $models);
        } catch (CatalogUnavailableException $catalogUnavailableException) {
            // A validation error keeps the dialog open with the admin's picks.
            throw ValidationException::withMessages([
                'models' => __('Could not load the pricing catalog: :message', ['message' => $catalogUnavailableException->getMessage()]),
            ]);
        }

        $refreshScope = RefreshScope::forExplicitCreates($provider, $models);
        $added = 0;

        foreach ($candidates as $candidate) {
            if ($aiModelPriceWriter->write($candidate, $refreshScope, $candidate->source) === WriteOutcome::Created) {
                $added++;
            }
        }

        $total = count($models);
        $skipped = $total - $added;

        Inertia::flash('toast', [
            'type' => $added > 0 ? 'success' : 'info',
            'message' => $skipped === 0
                ? trans_choice('Added :count model.|Added :count models.', $added)
                : trans_choice(
                    'Added :added of :total; :count model skipped (already added, no longer in the catalog, or rejected).|Added :added of :total; :count models skipped (already added, no longer in the catalog, or rejected).',
                    $skipped,
                    ['added' => $added, 'total' => $total],
                ),
        ]);

        return to_route('admin.ai-prices.index');
    }
}
