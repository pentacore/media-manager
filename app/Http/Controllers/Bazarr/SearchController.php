<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bazarr\SearchRequest;
use App\Http\Resources\Bazarr\SubtitleCandidateResource;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class SearchController extends Controller
{
    public function __invoke(
        SearchRequest $searchRequest,
        SubtitleInventoryService $subtitleInventoryService,
    ): JsonResponse {
        $validated = $searchRequest->validated();
        $connection = $this->connection((int) $validated['connection']);
        $mediaType = (string) $validated['media_type'];
        $mediaId = (int) $validated['media_id'];
        $inspection = $subtitleInventoryService->inspect($connection, $mediaType, $mediaId);

        if (($inspection['item']['target_fingerprint'] ?? null) !== $validated['target_fingerprint']) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'The media file changed. Refresh the Subtitle Center before searching.',
            ]);
        }

        $bazarrClient = new BazarrClient($connection);
        $capabilities = $bazarrClient->getCapabilities();

        // Without manual search the provider query below fails, and the response
        // that would have carried the capability never arrives.
        if (($capabilities['manual_search'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'connection' => 'This Bazarr version does not support manual subtitle search.',
            ]);
        }

        $candidates = $mediaType === 'episode'
            ? $bazarrClient->searchEpisode($mediaId)
            : $bazarrClient->searchMovie($mediaId);

        return response()->json([
            'item' => $inspection['item'],
            'history' => $inspection['history'],
            'candidates' => array_map(
                static fn (array $candidate): array => new SubtitleCandidateResource($candidate)->resolve(),
                $candidates,
            ),
            'capabilities' => $capabilities,
        ]);
    }

    private function connection(int $connectionId): ServiceConnection
    {
        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
