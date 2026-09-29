<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use App\Services\AiUsage\Pricing\CatalogUnavailableException;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use Illuminate\Http\JsonResponse;

class AiModelCatalogController extends Controller
{
    /**
     * Catalog models for one provider that have no price row yet, for the
     * admin "add from catalog" pickers.
     */
    public function index(string $provider, CatalogModelBrowser $catalogModelBrowser): JsonResponse
    {
        abort_unless(in_array($provider, $catalogModelBrowser->providers(), true), 404);

        try {
            $options = $catalogModelBrowser->available($provider);
        } catch (CatalogUnavailableException $catalogUnavailableException) {
            return response()->json(['message' => $catalogUnavailableException->getMessage()], 503);
        }

        return response()->json([
            'models' => array_map(fn (CatalogModelOption $catalogModelOption): array => $catalogModelOption->toArray(), $options),
        ]);
    }
}
