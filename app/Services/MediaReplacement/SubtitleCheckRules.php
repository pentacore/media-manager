<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Settings\MediaReplacementSettings;

/**
 * The language and attempt-cap rules shared by the import subtitle check and
 * the series subtitle audit, so both judge a file and bound retries the same way.
 */
final readonly class SubtitleCheckRules
{
    public function __construct(
        private MediaReplacementSettings $mediaReplacementSettings,
        private LanguageNormalizer $languageNormalizer,
    ) {}

    /**
     * Required languages for the scope that the inspected file does not carry.
     *
     * @param  list<string>  $normalizedSubtitles
     * @return list<string>
     */
    public function missingLanguages(array $normalizedSubtitles, MediaReplacementScope $mediaReplacementScope): array
    {
        $required = $this->mediaReplacementSettings->effectiveLanguages($mediaReplacementScope);
        $found = $this->languageNormalizer->normalizeMany($normalizedSubtitles);

        return array_values(array_diff($required, $found));
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
    public function autoCheckKey(ServiceConnection $serviceConnection, array $snapshot): string
    {
        if ($serviceConnection->type === ServiceType::Radarr) {
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

    public function capReached(string $autoCheckKey): bool
    {
        $cutoff = now()->subHours($this->mediaReplacementSettings->subtitleCheckCooldownHours());

        $attempts = ActionRequest::query()
            ->where('type', 'replace_media_file')
            ->where('payload->auto_check_key', $autoCheckKey)
            ->where('created_at', '>=', $cutoff)
            ->count();

        return $attempts >= $this->mediaReplacementSettings->subtitleCheckMaxAttempts();
    }
}
