<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\MediaReplacementScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ReplacementTargetRequest;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\MediaReplacementTargetFingerprint;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MediaReplacementController extends Controller
{
    public function inspect(
        ReplacementTargetRequest $request,
        MediaFileInspector $mediaFileInspector,
        MediaReplacementSettings $mediaReplacementSettings,
    ): JsonResponse {
        $validated = $request->validated();

        // Per .ai/rules/controllers.md: narrow the catch to the upstream
        // client's own exceptions — never Throwable — and translate a failed
        // round-trip into a structured 502 instead of a 500.
        try {
            $snapshot = $mediaFileInspector->inspect(
                (string) ($validated['service'] ?? ''),
                (int) ($validated['item_id'] ?? 0),
                seasonNumber: isset($validated['season_number']) ? (int) $validated['season_number'] : null,
                episodeNumber: isset($validated['episode_number']) ? (int) $validated['episode_number'] : null,
                serviceConnection: $request->connection(),
            );
        } catch (RequestException|ConnectionException) {
            return response()->json(['message' => 'Sonarr/Radarr is unreachable.'], 502);
        }

        abort_if(
            ($snapshot['ambiguous'] ?? false) === true,
            422,
            $this->ambiguityMessage($snapshot),
        );

        $scope = MediaReplacementScope::tryFrom((string) ($snapshot['scope'] ?? ''));

        return response()->json([
            'snapshot' => $snapshot,
            'fingerprint' => MediaReplacementTargetFingerprint::fromSnapshot($snapshot),
            'required_languages' => $scope === null ? [] : $mediaReplacementSettings->effectiveLanguages($scope),
        ]);
    }

    public function candidates(
        ReplacementTargetRequest $request,
        MediaFileInspector $mediaFileInspector,
        ReplacementCandidateFinder $replacementCandidateFinder,
    ): JsonResponse {
        $connection = $request->connection();
        $validated = $request->validated();

        // Narrow catch per .ai/rules/controllers.md — never Throwable — covering
        // both upstream round-trips (inspect + find) with one 502 translation.
        try {
            $snapshot = $mediaFileInspector->inspect(
                (string) ($validated['service'] ?? ''),
                (int) ($validated['item_id'] ?? 0),
                seasonNumber: isset($validated['season_number']) ? (int) $validated['season_number'] : null,
                episodeNumber: isset($validated['episode_number']) ? (int) $validated['episode_number'] : null,
                serviceConnection: $connection,
            );

            abort_if(($snapshot['ambiguous'] ?? false) === true, 422, 'This file cannot be replaced automatically.');

            $fingerprint = MediaReplacementTargetFingerprint::fromSnapshot($snapshot);

            $result = Cache::remember(
                "media-replacement:candidates:{$fingerprint}",
                120,
                fn (): array => $replacementCandidateFinder->find($snapshot, serviceConnection: $connection),
            );
        } catch (RequestException|ConnectionException) {
            return response()->json(['message' => 'Sonarr/Radarr is unreachable.'], 502);
        }

        return response()->json([
            'candidates' => $result['candidates'],
            'effective_languages' => $result['effective_languages'],
            'excluded' => $result['excluded'],
        ]);
    }

    /**
     * Translate the inspector's machine-readable ambiguity reason code (e.g.
     * `no_file`, `multiple_episodes`) into a human-readable message.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function ambiguityMessage(array $snapshot): string
    {
        $reason = $snapshot['reason'] ?? null;

        return is_string($reason) && $reason !== ''
            ? Str::headline($reason)
            : 'This file cannot be replaced automatically.';
    }
}
