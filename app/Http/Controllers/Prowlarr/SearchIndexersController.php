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
            'results' => array_map($this->presentRelease(...), $results),
            'hasConnection' => true,
            'error' => null,
        ]);
    }

    /**
     * Keep only the display fields. Prowlarr's downloadUrl embeds its own
     * API key, and guid/magnetUrl can carry a tracker passkey, so the raw
     * release must never reach the browser.
     *
     * @param  array<string, mixed>  $release
     * @return array{title: mixed, indexer: mixed, size: mixed, seeders: mixed, age: mixed, publishDate: mixed}
     */
    private function presentRelease(array $release): array
    {
        return [
            'title' => $release['title'] ?? null,
            'indexer' => $release['indexer'] ?? null,
            'size' => $release['size'] ?? null,
            'seeders' => $release['seeders'] ?? null,
            'age' => $release['age'] ?? null,
            'publishDate' => $release['publishDate'] ?? null,
        ];
    }
}
