<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Checks a completed, tag-matched import for the required subtitle languages
 * and, when one is missing, dispatches a replace_media_file ActionRequest
 * through the existing pipeline.
 *
 * Loop safety rests on two guards: an import that belongs to a replacement
 * attempt is left to MediaReplacementTracker, and a per-target attempt cap
 * bounds how often the same item can be re-requested.
 *
 * The indexer search only runs once a required language is known to be missing;
 * an import that already carries them costs the arr calls of the inspection and
 * nothing more.
 */
final readonly class ImportedSubtitleAuditor
{
    public function __construct(
        private MediaReplacementSettings $mediaReplacementSettings,
        private SubtitleCheckTagSettings $subtitleCheckTagSettings,
        private MediaFileInspector $mediaFileInspector,
        private ReplacementCandidateFinder $replacementCandidateFinder,
        private ReplacementRequestBuilder $replacementRequestBuilder,
        private MediaReplacementTracker $mediaReplacementTracker,
        private ActionOrchestrator $actionOrchestrator,
        private LanguageNormalizer $languageNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function audit(ServiceConnection $serviceConnection, array $payload, ?WebhookEvent $webhookEvent): void
    {
        try {
            $this->run($serviceConnection, $payload, $webhookEvent);
        } catch (Throwable $throwable) {
            Log::error('Automatic subtitle check failed.', [
                'service_connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(ServiceConnection $serviceConnection, array $payload, ?WebhookEvent $webhookEvent): void
    {
        if (! $this->mediaReplacementSettings->subtitleCheckEnabled()) {
            $this->skip($serviceConnection, 'disabled');

            return;
        }

        $configuredTags = $this->subtitleCheckTagSettings->forConnection($serviceConnection);

        if ($configuredTags === []) {
            $this->skip($serviceConnection, 'no_tags_configured');

            return;
        }

        $isRadarr = $serviceConnection->type === ServiceType::Radarr;
        $itemId = $this->positiveInt($isRadarr
            ? ($payload['movie']['id'] ?? null)
            : ($payload['series']['id'] ?? null));

        if ($itemId === null) {
            $this->skip($serviceConnection, 'no_item_id');

            return;
        }

        if (! $this->hasConfiguredTag($serviceConnection, $itemId, $configuredTags, $isRadarr)) {
            $this->skip($serviceConnection, 'no_configured_tag');

            return;
        }

        // A replacement's own import belongs to the tracker, which verifies it
        // and flags a still-missing language itself. Auditing it here would
        // request a replacement for the replacement, forever. This runs before
        // the inspection so a replacement's import costs nothing at all.
        $downloadId = $payload['downloadId'] ?? null;

        if (is_string($downloadId) && $downloadId !== ''
            && $this->mediaReplacementTracker->hasAttemptForDownload($serviceConnection, $downloadId)) {
            $this->skip($serviceConnection, 'replacement_attempt_import');

            return;
        }

        $snapshot = $this->mediaFileInspector->inspect(
            service: $isRadarr ? 'radarr' : 'sonarr',
            itemId: $itemId,
            seasonNumber: $isRadarr ? null : $this->wholeNumber($payload['episodes'][0]['seasonNumber'] ?? null),
            episodeNumber: $isRadarr ? null : $this->positiveInt($payload['episodes'][0]['episodeNumber'] ?? null),
            serviceConnection: $serviceConnection,
        );

        if (($snapshot['ambiguous'] ?? false) === true) {
            $this->notify(
                $serviceConnection,
                (string) ($payload['series']['title'] ?? $payload['movie']['title'] ?? 'Imported media'),
                'The automatic subtitle check could not identify a single file for this import.',
            );

            return;
        }

        $scope = MediaReplacementScope::tryFrom((string) ($snapshot['scope'] ?? ''));

        if (! $scope instanceof MediaReplacementScope) {
            Log::warning('Automatic subtitle check could not determine the target scope.', [
                'service_connection_id' => $serviceConnection->id,
                'scope' => $snapshot['scope'] ?? null,
            ]);

            return;
        }

        $autoCheckKey = $this->autoCheckKey($serviceConnection, $snapshot, $isRadarr);
        $missing = $this->missingLanguages($snapshot, $scope);

        // Checked before the attempt cap: the cap's notification claims a
        // language is still missing, and it may only say so once that is known.
        // It is also the cheaper of the two — no query, no indexer search.
        if ($missing === []) {
            Log::info('Automatic subtitle check passed.', [
                'service_connection_id' => $serviceConnection->id,
                'auto_check_key' => $autoCheckKey,
            ]);

            return;
        }

        if ($this->capReached($autoCheckKey)) {
            $this->notify(
                $serviceConnection,
                (string) ($snapshot['display_name'] ?? 'Imported media'),
                sprintf(
                    'Subtitles are still missing but the automatic check has already requested %d replacement(s) for this item in the last %d hour(s).',
                    $this->mediaReplacementSettings->subtitleCheckMaxAttempts(),
                    $this->mediaReplacementSettings->subtitleCheckCooldownHours(),
                ),
            );

            return;
        }

        $result = $this->replacementCandidateFinder->find(
            target: $snapshot,
            languageOverride: null,
            limit: 10,
            serviceConnection: $serviceConnection,
        );

        $automaticCandidate = is_array($result['automatic_candidate'] ?? null) ? $result['automatic_candidate'] : null;
        $candidate = $automaticCandidate ?? ($result['candidates'][0] ?? null);

        if (! is_array($candidate)) {
            $this->notify(
                $serviceConnection,
                (string) ($snapshot['display_name'] ?? 'Imported media'),
                sprintf('Missing subtitles (%s) but no eligible replacement was found.', implode(', ', $missing)),
            );

            return;
        }

        $built = $this->replacementRequestBuilder->build(
            snapshot: $snapshot,
            candidate: $candidate,
            requiredLanguages: $result['effective_languages'],
            selectionMode: $automaticCandidate === null ? 'manual' : 'automatic',
            reason: sprintf(
                'Automatic subtitle check: the imported file is missing %s.',
                implode(', ', $missing),
            ),
            autoCheckKey: $autoCheckKey,
        );

        $this->actionOrchestrator->dispatch(
            type: 'replace_media_file',
            sourceService: $isRadarr ? 'radarr' : 'sonarr',
            targetService: $isRadarr ? 'radarr' : 'sonarr',
            payload: $built['payload'],
            webhookEvent: $webhookEvent,
            // No confident candidate means nothing may be picked without a
            // human, so the operator must approve even if the action type is
            // otherwise configured to run unattended. ActionOrchestrator still
            // owns the gate; this flag can only tighten it.
            forceRequiresApproval: $automaticCandidate === null || $built['force_requires_approval'],
        );

        $this->notify(
            $serviceConnection,
            (string) ($snapshot['display_name'] ?? 'Imported media'),
            sprintf(
                'Missing subtitles (%s); requested a replacement (%s selection).',
                implode(', ', $missing),
                $automaticCandidate === null ? 'operator' : 'automatic',
            ),
        );
    }

    /**
     * Required languages for the scope that the inspected file does not carry.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function missingLanguages(array $snapshot, MediaReplacementScope $mediaReplacementScope): array
    {
        $required = $this->mediaReplacementSettings->effectiveLanguages($mediaReplacementScope);
        $found = $this->languageNormalizer->normalizeMany(
            array_values(array_filter(
                is_array($snapshot['subtitles'] ?? null) ? $snapshot['subtitles'] : [],
                is_string(...),
            )),
        );

        return array_values(array_diff($required, $found));
    }

    /**
     * @param  list<string>  $configuredTags
     */
    private function hasConfiguredTag(
        ServiceConnection $serviceConnection,
        int $itemId,
        array $configuredTags,
        bool $isRadarr,
    ): bool {
        $client = $isRadarr ? new RadarrClient($serviceConnection) : new SonarrClient($serviceConnection);
        $item = $isRadarr ? $client->getMovieById($itemId) : $client->getSeriesById($itemId);
        $tagIds = is_array($item['tags'] ?? null) ? $item['tags'] : [];

        if ($tagIds === []) {
            return false;
        }

        // The webhook payload's own `tags` field is deliberately not used: its
        // presence and format vary by arr version, and both calls here are cached.
        $labelsById = [];

        foreach ($client->getTags() as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $id = $this->positiveInt($tag['id'] ?? null);
            $label = $tag['label'] ?? null;

            if ($id !== null && is_string($label)) {
                // Folded through the same helper the picker stores with, so an
                // upstream label that differs only in case or surrounding
                // whitespace still matches what the admin ticked.
                $labelsById[$id] = $this->subtitleCheckTagSettings->normalizeLabel($label);
            }
        }

        foreach ($tagIds as $tagId) {
            $id = $this->positiveInt($tagId);

            if ($id !== null && in_array($labelsById[$id] ?? '', $configuredTags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stable per-target key for the attempt cap, written into the request
     * payload by ReplacementRequestBuilder and read back by capReached().
     * Episode ids are sorted so a multi-episode file yields the same key
     * regardless of the order the arr listed them.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function autoCheckKey(ServiceConnection $serviceConnection, array $snapshot, bool $isRadarr): string
    {
        if ($isRadarr) {
            return sprintf('radarr:%d:%d', $serviceConnection->id, (int) ($snapshot['movie_id'] ?? 0));
        }

        $episodeIds = array_map(intval(...), array_values(array_filter(
            is_array($snapshot['episode_ids'] ?? null) ? $snapshot['episode_ids'] : [],
            static fn (mixed $id): bool => is_int($id) || is_string($id),
        )));
        sort($episodeIds, SORT_NUMERIC);

        return sprintf(
            'sonarr:%d:%d-%s',
            $serviceConnection->id,
            (int) ($snapshot['series_id'] ?? 0),
            implode('-', $episodeIds),
        );
    }

    private function capReached(string $autoCheckKey): bool
    {
        $cutoff = now()->subHours($this->mediaReplacementSettings->subtitleCheckCooldownHours());

        $attempts = ActionRequest::query()
            ->where('type', 'replace_media_file')
            ->where('payload->auto_check_key', $autoCheckKey)
            ->where('created_at', '>=', $cutoff)
            ->count();

        return $attempts >= $this->mediaReplacementSettings->subtitleCheckMaxAttempts();
    }

    /**
     * Record why an import was not audited. Debug level because the skipped
     * paths fire on every unrelated import; the acted-on outcomes log at info.
     */
    private function skip(ServiceConnection $serviceConnection, string $reason): void
    {
        Log::debug('Automatic subtitle check skipped.', [
            'service_connection_id' => $serviceConnection->id,
            'reason' => $reason,
        ]);
    }

    private function notify(ServiceConnection $serviceConnection, string $title, string $message): void
    {
        Log::info('Automatic subtitle check outcome.', [
            'service_connection_id' => $serviceConnection->id,
            'title' => $title,
            'message' => $message,
        ]);

        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new MediaReplacementStatusChanged(
            service: $serviceConnection->type->value,
            title: $title,
            message: $message,
            level: 'info',
        ));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', trim($value)) !== 1) {
            return null;
        }

        $integer = (int) trim($value);

        return $integer > 0 ? $integer : null;
    }

    /**
     * Season numbers are whole numbers: Sonarr uses season 0 for Specials, so a
     * specials import must not be coerced to "no season" and become ambiguous.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
    }
}
