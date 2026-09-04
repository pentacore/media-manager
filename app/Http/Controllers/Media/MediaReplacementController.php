<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\QueueReplacementRequest;
use App\Http\Requests\Media\ReplacementTargetRequest;
use App\Models\ActionRequest;
use App\Services\Actions\ActionOrchestrator;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\MediaReplacementTargetFingerprint;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Services\MediaReplacement\ReplacementRequestBuilder;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            'snapshot' => $this->presentSnapshot($snapshot),
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

    public function replace(
        QueueReplacementRequest $request,
        MediaFileInspector $mediaFileInspector,
        ReplacementCandidateFinder $replacementCandidateFinder,
        ReplacementRequestBuilder $replacementRequestBuilder,
        PendingReplacementGuard $pendingReplacementGuard,
        ActionOrchestrator $actionOrchestrator,
    ): JsonResponse {
        $validated = $request->validated();
        $connection = $request->connection();

        // Narrow catch per .ai/rules/controllers.md — never Throwable.
        try {
            $snapshot = $mediaFileInspector->inspect(
                (string) ($validated['service'] ?? ''),
                (int) ($validated['item_id'] ?? 0),
                seasonNumber: isset($validated['season_number']) ? (int) $validated['season_number'] : null,
                episodeNumber: isset($validated['episode_number']) ? (int) $validated['episode_number'] : null,
                serviceConnection: $connection,
            );
        } catch (RequestException|ConnectionException) {
            return response()->json(['message' => 'Sonarr/Radarr is unreachable.'], 502);
        }

        abort_if(($snapshot['ambiguous'] ?? false) === true, 422, 'This file cannot be replaced automatically.');

        $fingerprint = MediaReplacementTargetFingerprint::fromSnapshot($snapshot);

        // Fresh recompute — the echoed fingerprint only selects the comparison.
        if ($fingerprint !== $validated['target_fingerprint']) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'The media file changed — reopen the dialog.',
            ]);
        }

        // Two concurrent POSTs for the same target could both pass the guard
        // check below before either dispatches, creating duplicate pending
        // ActionRequests. Serialize the guard→dispatch section per target.
        // Key prefix ("media-replacement:submit:") is distinct from
        // SharedMediaTargetLock's ("media-target:") and MediaReplacementExecutionLock's
        // — no deadlock risk: ExecuteActionRequest only runs later, in a
        // separate queue worker process, after this lock is already released.
        $submissionLock = Cache::lock("media-replacement:submit:{$fingerprint}", 30);

        if (! $submissionLock->get()) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'Another replacement request for this file is being processed — try again.',
            ]);
        }

        try {
            abort_if(
                $pendingReplacementGuard->inFlightFor($snapshot),
                422,
                'A replacement for this file is already in flight.',
            );

            try {
                $result = Cache::remember(
                    "media-replacement:candidates:{$fingerprint}",
                    120,
                    fn (): array => $replacementCandidateFinder->find($snapshot, serviceConnection: $connection),
                );
            } catch (RequestException|ConnectionException) {
                return response()->json(['message' => 'Sonarr/Radarr is unreachable.'], 502);
            }

            $candidate = collect($result['candidates'])
                ->firstWhere('fingerprint', $validated['candidate_fingerprint']);

            if ($candidate === null) {
                throw ValidationException::withMessages([
                    'candidate_fingerprint' => 'That release is no longer available — search again.',
                ]);
            }

            $built = $replacementRequestBuilder->build(
                $snapshot,
                $candidate,
                $result['effective_languages'],
                'manual',
                sprintf('Manual replacement requested by %s', $request->user()->name),
                verifySubtitles: (bool) ($validated['verify_subtitles'] ?? true),
            );

            $isRadarr = $validated['service'] === 'radarr';

            // Exact signature: dispatch(string $type, string $sourceService, string $targetService,
            //   array $payload, ?WebhookEvent $webhookEvent = null, ?bool $forceRequiresApproval = null,
            //   bool $deferExecution = false): ?ActionRequest  — same call shape as ImportedSubtitleAuditor.
            $actionRequest = $actionOrchestrator->dispatch(
                type: 'replace_media_file',
                sourceService: $isRadarr ? 'radarr' : 'sonarr',
                targetService: $isRadarr ? 'radarr' : 'sonarr',
                payload: $built['payload'],
                forceRequiresApproval: $built['force_requires_approval'],
            );

            abort_if(! $actionRequest instanceof ActionRequest, 422, 'This action is disabled in Action Rules.');

            return response()->json([
                'action_request_id' => $actionRequest->id,
                'action_queue_url' => route('actions.requests.index'),
                'requires_approval' => $actionRequest->status === ActionRequestStatus::Pending,
            ], 201);
        } finally {
            $submissionLock->release();
        }
    }

    /**
     * Whitelist the inspector snapshot down to the fields the "Replace file"
     * dialog actually renders. `scene_name` / `installed_release` can carry
     * the upstream `relativePath` fallback (see MediaFileInspector::sceneName())
     * — a filesystem path leak — and internal identifiers
     * (episode_file_ids/movie_file_ids/original_history_id/...) have no
     * business reaching the client. candidates()/replace() re-inspect
     * server-side, so they are unaffected by this trim.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function presentSnapshot(array $snapshot): array
    {
        return Arr::only($snapshot, [
            'display_name',
            'service',
            'service_connection_id',
            'scope',
            'quality',
            'size',
            'date_added',
            'subtitles',
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
