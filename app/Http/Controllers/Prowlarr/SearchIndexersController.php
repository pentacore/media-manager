<?php

declare(strict_types=1);

namespace App\Http\Controllers\Prowlarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\IndexerReleaseCache;
use App\Services\Prowlarr\ProwlarrClient;
use App\Support\Abilities;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SearchIndexersController extends Controller
{
    public function __invoke(Request $request, IndexerReleaseCache $indexerReleaseCache): Response
    {
        $query = trim((string) $request->query('q', ''));

        $connection = ServiceConnection::findActive(ServiceType::Prowlarr);

        if (! $connection instanceof ServiceConnection) {
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
        } catch (RequestException|ConnectionException $exception) {
            Log::warning('Prowlarr indexer search failed', [
                'connection_id' => $connection->id,
                'exception' => $exception::class,
            ]);

            return Inertia::render('Prowlarr/Search', [
                'query' => $query,
                'results' => [],
                'hasConnection' => true,
                'error' => 'Indexer search failed.',
            ]);
        }

        $releases = array_values(array_filter($results, is_array(...)));

        // IndexerReleaseCache keeps the guid server-side and returns only the
        // display allowlist plus the release key and indexer id. Only admins
        // can grab (prowlarr.grab is can:admin), so only their searches are
        // remembered; everyone else gets the same rows with no key.
        return Inertia::render('Prowlarr/Search', [
            'query' => $query,
            'results' => $request->user()->can(Abilities::ADMIN)
                ? $indexerReleaseCache->remember($connection, $releases)
                : $indexerReleaseCache->present($releases),
            'hasConnection' => true,
            'error' => null,
        ]);
    }
}
