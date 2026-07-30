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
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class BazarrNotificationController extends Controller
{
    public function __invoke(
        BazarrNotificationRequest $bazarrNotificationRequest,
        ServiceConnection $serviceConnection,
    ): JsonResponse {
        abort_unless($serviceConnection->type === ServiceType::Bazarr && $serviceConnection->is_active, 404);
        $this->authenticate($bazarrNotificationRequest, $serviceConnection);
        $validated = $bazarrNotificationRequest->validated();
        $eventType = $this->eventType($validated);
        $payload = $this->sanitizedPayload($validated);

        // Hash the whole posted body, not just the sanitized projection: two
        // different notifications about the same target inside the dedup window
        // would otherwise collapse into one hint.
        $payloadHash = WebhookEvent::payloadHash([
            'event_type' => $eventType,
            ...$bazarrNotificationRequest->json()->all(),
        ]);
        $dedupKey = sprintf('bazarr-notification:%d:%s', $serviceConnection->id, $payloadHash);

        if (! Cache::add($dedupKey, true, now()->addMinutes(5))) {
            return response()->json(['status' => 'received']);
        }

        // The marker is claimed atomically to keep concurrent duplicates out,
        // but it must not outlive a failed hand-off: releasing it lets Bazarr's
        // retry be accepted instead of answered as a duplicate of nothing.
        try {
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
        } catch (Throwable $throwable) {
            Cache::forget($dedupKey);

            throw $throwable;
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Apprise payloads carry `type` (info/success/warning/failure) rather than
     * an arr-style `eventType`; either identifies the hint well enough to store.
     *
     * @param  array<string, mixed>  $validated
     */
    private function eventType(array $validated): string
    {
        foreach (['eventType', 'type'] as $key) {
            $value = $validated[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 100);
            }
        }

        return 'notification';
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

        // Match in SQL rather than filtering a recent window in PHP: a unique
        // older case would otherwise be missed and demoted to a connection-wide
        // sweep. Two rows are enough to tell unique from ambiguous.
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
            ->where(static function (Builder $builder) use ($mediaType, $mediaId): void {
                if ($mediaType !== 'episode') {
                    $builder->where('target_ids->radarr_id', (string) $mediaId);

                    return;
                }

                // A shared subtitle file covers several episodes, so the case
                // records the whole list alongside its primary episode id.
                $builder->where('target_ids->episode_id', (string) $mediaId)
                    ->orWhereJsonContains('target_ids->episode_ids', $mediaId);
            })
            ->latest('id')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
