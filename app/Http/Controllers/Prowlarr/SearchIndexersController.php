<?php

declare(strict_types=1);

namespace App\Http\Controllers\Prowlarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SearchIndexersController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));

        $connection = ServiceConnection::query()
            ->where('type', ServiceType::Prowlarr)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($connection === null) {
            return Inertia::render('Prowlarr/Search', [
                'query' => $query,
                'results' => [],
                'hasConnection' => false,
                'error' => null,
            ]);
        }

        if ($query === '') {
            return Inertia::render('Prowlarr/Search', [
                'query' => '',
                'results' => [],
                'hasConnection' => true,
                'error' => null,
            ]);
        }

        try {
            $results = new ProwlarrClient($connection)->searchIndexers($query);
        } catch (Throwable $throwable) {
            Log::warning('Prowlarr indexer search failed', [
                'connection_id' => $connection->id,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return Inertia::render('Prowlarr/Search', [
                'query' => $query,
                'results' => [],
                'hasConnection' => true,
                'error' => 'Indexer search failed.',
            ]);
        }

        return Inertia::render('Prowlarr/Search', [
            'query' => $query,
            'results' => $results,
            'hasConnection' => true,
            'error' => null,
        ]);
    }
}
