<?php

declare(strict_types=1);

namespace App\Http\Controllers\Prowlarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prowlarr\GrabIndexerReleaseRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\IndexerReleaseCache;
use App\Services\Prowlarr\ProwlarrClient;
use App\Support\UpstreamErrorText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

/**
 * Admin Grab from the indexer search: only a release this connection's
 * search put in IndexerReleaseCache can be grabbed, so the browser never
 * supplies (or sees) a guid. Prowlarr hands it to its own download client.
 */
class GrabReleaseController extends Controller
{
    public function __invoke(GrabIndexerReleaseRequest $grabIndexerReleaseRequest, IndexerReleaseCache $indexerReleaseCache): JsonResponse
    {
        $validated = $grabIndexerReleaseRequest->validated();

        $connection = ServiceConnection::query()
            ->where('type', ServiceType::Prowlarr)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        abort_unless($connection instanceof ServiceConnection, 422, 'No active Prowlarr connection is configured.');

        $release = $indexerReleaseCache->find($connection, (int) $validated['indexer_id'], (string) $validated['release_key']);

        abort_if($release === null, 422, 'That release is no longer available — run the search again.');

        try {
            new ProwlarrClient($connection)->grabIndexerRelease($release['guid'], $release['indexer_id']);
        } catch (ConnectionException) {
            return response()->json(['message' => __('Prowlarr is unreachable right now.')], 502);
        } catch (RequestException $requestException) {
            return $this->refusal($requestException);
        }

        ActivityLog::create([
            'user_id' => $grabIndexerReleaseRequest->user()->id,
            'service_connection_id' => $connection->id,
            'action' => 'prowlarr.release.grabbed',
            'description' => sprintf('Sent "%s" from %s to the download client.', $release['title'] ?? 'a release', $release['indexer'] ?? 'an indexer'),
            'metadata' => ['indexer_id' => $release['indexer_id'], 'title' => $release['title'], 'indexer' => $release['indexer']],
        ]);

        return response()->json([
            'message' => __('Sent ":title" to the download client.', ['title' => $release['title'] ?? __('the release')]),
        ]);
    }

    /**
     * 404 = Prowlarr's own release cache expired. Any other answer with a
     * message (e.g. no download client configured, which Prowlarr reports as
     * a 5xx) is shown sanitized; a bare 5xx is an outage.
     */
    private function refusal(RequestException $requestException): JsonResponse
    {
        $response = $requestException->response;

        if ($response->status() === 404) {
            return response()->json(['message' => __('Prowlarr no longer has that release cached — run the search again.')], 422);
        }

        $message = $response->json('message') ?? $response->json('0.errorMessage');

        if (is_string($message) && trim($message) !== '') {
            return response()->json(['message' => sprintf('Prowlarr could not grab the release: %s', UpstreamErrorText::sanitize($message))], 422);
        }

        return $response->serverError()
            ? response()->json(['message' => __('Prowlarr is unreachable right now.')], 502)
            : response()->json(['message' => __('Prowlarr refused the grab.')], 422);
    }
}
