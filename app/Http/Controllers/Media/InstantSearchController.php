<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstantSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        $term = trim((string) $request->query('q'));
        $limit = (int) config('mediamanager.search.instant_max_results', 8);
        $driver = config('mediamanager.search.driver', 'typesense');

        if ($term === '' || $driver !== 'typesense') {
            return new JsonResponse(['series' => [], 'movies' => []]);
        }

        try {
            $series = IndexedSeries::search($term)->take($limit)->get();
            $movies = IndexedMovie::search($term)->take($limit)->get();
        } catch (Throwable $throwable) {
            Log::warning('InstantSearch query failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return new JsonResponse(['series' => [], 'movies' => [], 'error' => 'Search is temporarily unavailable.'], 503);
        }

        return new JsonResponse([
            'series' => $series->map(static fn (IndexedSeries $indexedSeries): array => [
                'id' => $indexedSeries->sonarr_id,
                'title' => $indexedSeries->title,
                'year' => $indexedSeries->year,
                'title_slug' => $indexedSeries->title_slug,
                'poster_url' => $indexedSeries->poster_url,
                'kind' => 'series',
            ])->all(),
            'movies' => $movies->map(static fn (IndexedMovie $indexedMovie): array => [
                'id' => $indexedMovie->radarr_id,
                'title' => $indexedMovie->title,
                'year' => $indexedMovie->year,
                'title_slug' => $indexedMovie->title_slug,
                'poster_url' => $indexedMovie->poster_url,
                'kind' => 'movie',
            ])->all(),
        ]);
    }
}
