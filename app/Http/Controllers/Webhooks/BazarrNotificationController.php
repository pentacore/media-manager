<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Cache\Services\BazarrCache;
use App\Enums\ServiceType;
use App\Enums\SubtitleCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\BazarrNotificationRequest;
use App\Jobs\ReconcileBazarrConnection;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class BazarrNotificationController extends Controller
{
    public function __invoke(
        BazarrNotificationRequest $bazarrNotificationRequest,
        ServiceConnection $serviceConnection,
    ): JsonResponse {
        abort_unless($serviceConnection->type === ServiceType::Bazarr && $serviceConnection->is_active, 404);
        $this->authenticate($bazarrNotificationRequest, $serviceConnection);
        $validated = $bazarrNotificationRequest->validated();
        $eventType = mb_substr((string) $validated['eventType'], 0, 100);
        $payload = $this->sanitizedPayload($validated);
        $payloadHash = WebhookEvent::payloadHash(['event_type' => $eventType, ...$payload]);

        if (! Cache::add(
            sprintf('bazarr-notification:%d:%s', $serviceConnection->id, $payloadHash),
            true,
            now()->addMinutes(5),
        )) {
            return response()->json(['status' => 'received']);
        }

        WebhookEvent::query()->create([
            'service_connection_id' => $serviceConnection->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
        ]);
        new BazarrCache($serviceConnection)->bustAll();

        $subtitleCase = $this->targetCase($serviceConnection, $payload);

        if ($subtitleCase instanceof SubtitleCase) {
            dispatch(ReconcileSubtitleCase::forCase($subtitleCase));
        } else {
            dispatch(new ReconcileBazarrConnection($serviceConnection->id));
        }

        return response()->json(['status' => 'received']);
    }

    private function authenticate(BazarrNotificationRequest $bazarrNotificationRequest, ServiceConnection $serviceConnection): void
    {
        $token = $bazarrNotificationRequest->header('X-Webhook-Token');

        if (! is_string($token) || $token === '') {
            $queryToken = $bazarrNotificationRequest->query('token');
            $token = is_string($queryToken) ? $queryToken : null;
        }

        abort_if(
            ! is_string($token)
                || $token === ''
                || ! hash_equals((string) $serviceConnection->webhook_token, $token),
            401,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, int|string>
     */
    private function sanitizedPayload(array $validated): array
    {
        if (is_int($validated['sonarrEpisodeId'] ?? null)) {
            return array_filter([
                'media_type' => 'episode',
                'media_id' => $validated['sonarrEpisodeId'],
                'series_id' => $validated['sonarrSeriesId'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if (is_int($validated['radarrId'] ?? null)) {
            return [
                'media_type' => 'movie',
                'media_id' => $validated['radarrId'],
            ];
        }

        return [];
    }

    /**
     * @param  array<string, int|string>  $payload
     */
    private function targetCase(ServiceConnection $serviceConnection, array $payload): ?SubtitleCase
    {
        $mediaType = $payload['media_type'] ?? null;
        $mediaId = $payload['media_id'] ?? null;

        if (! is_string($mediaType) || ! is_int($mediaId)) {
            return null;
        }

        $matches = SubtitleCase::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->where('media_type', $mediaType)
            ->whereIn('status', [
                SubtitleCaseStatus::Observing,
                SubtitleCaseStatus::BazarrSearching,
                SubtitleCaseStatus::DownloadRequested,
                SubtitleCaseStatus::ReplacementEligible,
                SubtitleCaseStatus::AdvisorRunning,
                SubtitleCaseStatus::ReplacementRequested,
                SubtitleCaseStatus::NeedsReview,
            ])
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(static fn (SubtitleCase $subtitleCase): bool => $mediaType === 'episode'
                ? ($subtitleCase->target_ids['episode_id'] ?? null) === $mediaId
                : ($subtitleCase->target_ids['radarr_id'] ?? null) === $mediaId)
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
