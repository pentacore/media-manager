<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
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
use Illuminate\Support\Facades\Cache;
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

        $seasonNumber = null;
        $episodeNumber = null;

        if (! $isRadarr) {
            $seasonNumber = $this->wholeNumber($payload['episodes'][0]['seasonNumber'] ?? null);
            $episodeNumber = $this->positiveInt($payload['episodes'][0]['episodeNumber'] ?? null);

            // Both are required to name one episode. A missing season matches no
            // episode, and a missing episode number widens the match to the whole
            // season — which either reports an ambiguity the operator can do
            // nothing about or, for a one-episode season, quietly replaces a file
            // the payload never named. Skipping is the honest outcome for a
            // payload this class could not read.
            if ($seasonNumber === null || $episodeNumber === null) {
                $this->skip($serviceConnection, 'no_episode_reference');

                return;
            }
        }

        // Bust the connection's ARR cache BEFORE inspecting: the webhook handler
        // only busts it after the per-event handlers run, and the tracker's own
        // bust sits inside its matched-attempt branch, so it never fires for the
        // organic import this class exists for. A cached getSeriesById /
        // getEpisodesBySeries (600s / 300s) still describes the library as it was
        // before this import: on a first import the episode has no file id yet
        // (inspect() returns ambiguous `no_file` and the operator is told a
        // perfectly identifiable import could not be identified), and on an
        // upgrade it names the file this import just replaced (getEpisodeFileById
        // 404s and the RequestException is swallowed into a bare log line).
        //
        // Busting is the whole fix; the payload's authoritative
        // `episodeFile.id` is deliberately not used instead. inspect() derives
        // the file from the episode list because that same list is what detects
        // the ambiguity cases (`shared_multi_episode_file`, `multiple_episodes`)
        // that are this feature's safety boundary — so the list has to be fresh
        // either way, and once it is, the derived id and the payload's id agree
        // by construction. Trusting the payload would also need a new
        // MediaFileInspector entry point, and the snapshot shape it returns is
        // what ReplacementRequestBuilder, ReplacementCandidateFinder and the
        // executor's post-approval sameFiles() re-inspection all consume.
        $this->bustConnectionCache($serviceConnection);

        $snapshot = $this->mediaFileInspector->inspect(
            service: $isRadarr ? 'radarr' : 'sonarr',
            itemId: $itemId,
            seasonNumber: $seasonNumber,
            episodeNumber: $episodeNumber,
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

        $acquired = Cache::lock(
            AutomaticSubtitleCheckLock::key($autoCheckKey),
            AutomaticSubtitleCheckLock::TTL_SECONDS,
        )->get(function () use ($serviceConnection, $snapshot, $missing, $autoCheckKey, $webhookEvent, $isRadarr): void {
            $this->requestReplacement(
                $serviceConnection,
                $snapshot,
                $missing,
                $autoCheckKey,
                $webhookEvent,
                $isRadarr,
            );
        });

        if ($acquired === false) {
            $this->skip($serviceConnection, 'concurrent_audit');
        }
    }

    /**
     * Admit and dispatch one replacement while the caller owns the target lock.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $missing
     */
    private function requestReplacement(
        ServiceConnection $serviceConnection,
        array $snapshot,
        array $missing,
        string $autoCheckKey,
        ?WebhookEvent $webhookEvent,
        bool $isRadarr,
    ): void {
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

        // dispatch() rather than dispatchFromAgent(): this is a deterministic,
        // AI-free trigger. One consequence worth knowing is that dispatch() also
        // forces Pending whenever the AI chat mode is Advisory, so an AI setting
        // gates this non-AI feature. That only ever tightens the gate, so it is
        // left as-is rather than worked around.
        $actionRequest = $this->actionOrchestrator->dispatch(
            type: 'replace_media_file',
            sourceService: $isRadarr ? 'radarr' : 'sonarr',
            targetService: $isRadarr ? 'radarr' : 'sonarr',
            payload: $built['payload'],
            webhookEvent: $webhookEvent,
            // No confident candidate means nothing may be picked without a
            // human, so the operator must approve even if the action type is
            // otherwise configured to run unattended. ActionOrchestrator still
            // owns the gate; this flag can only tighten it.
            //
            // The right-hand operand cannot currently decide the result on its
            // own: everything that makes the builder set force_requires_approval
            // (a rejected release, an approval-gated season pack) also makes
            // ReplacementCandidateFinder::automaticCandidate() return null, so
            // the left operand is already true. It is kept because it mirrors
            // ReplaceMediaFileTool and becomes load-bearing the moment the
            // finder's constraints loosen.
            forceRequiresApproval: $automaticCandidate === null || $built['force_requires_approval'],
        );

        if (! $actionRequest instanceof ActionRequest) {
            $this->notify(
                $serviceConnection,
                (string) ($snapshot['display_name'] ?? 'Imported media'),
                sprintf(
                    'Missing subtitles (%s), but an automatic replacement could not be requested because the replacement action type is missing or disabled.',
                    implode(', ', $missing),
                ),
            );

            return;
        }

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
     * Drop the connection's cached arr reads so the inspection sees the library
     * as it is after the import. Mirrors MediaReplacementTracker::verifyDownload
     * and MediaReplacementActions, which bust for the same reason.
     */
    private function bustConnectionCache(ServiceConnection $serviceConnection): void
    {
        $serviceConnection->type === ServiceType::Radarr
            ? new RadarrCache($serviceConnection)->bustAll()
            : new SonarrCache($serviceConnection)->bustAll();
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
     *
     * A non-ambiguous Sonarr snapshot carries exactly one episode id today, so
     * the sort is currently a no-op; it is kept because the key is a loop guard
     * and a widened snapshot must not silently produce two keys for one target.
     *
     * An episode id list that is somehow empty yields a trailing-dash key shared
     * by every such episode of the series. That over-blocks rather than
     * under-blocks — the cap fires sooner, never later — so it is left alone.
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

    /**
     * Coerce a positive identifier. Also used for the webhook's episode number,
     * which is deliberately *not* widened the way the season number is: Sonarr
     * numbers episodes from 1, so 0 is not a real episode. A 0 or absent episode
     * number therefore lands here as null — and because inspect() reads null as
     * "no episode filter" and would then match the whole season, run() rejects a
     * null episode number outright rather than passing it on. Season 0 is real
     * (Specials), which is why the season number gets wholeNumber() instead.
     */
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
