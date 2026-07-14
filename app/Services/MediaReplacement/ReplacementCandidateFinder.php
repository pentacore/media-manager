<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\SeasonPackPolicy;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Settings\MediaReplacementSettings;
use InvalidArgumentException;

/**
 * Runs the native Sonarr/Radarr interactive release search for an inspected
 * target, hands the raw rows to the deterministic ranker, and returns a compact
 * shortlist plus effective settings. Only surfaces an automatic candidate when
 * automation is enabled and the safety/uniqueness/threshold constraints hold.
 */
final readonly class ReplacementCandidateFinder
{
    public function __construct(
        private MediaReplacementSettings $mediaReplacementSettings,
        private ReleaseCandidateRanker $releaseCandidateRanker,
    ) {}

    /**
     * @param  array<string, mixed>  $target
     * @param  array<int, string>|null  $languageOverride
     * @return array{
     *     target: array<string, mixed>,
     *     effective_languages: list<string>,
     *     guidance: array{notes: string},
     *     candidates: list<array<string, mixed>>,
     *     excluded: array<string, int>,
     *     unique_best: bool,
     *     automatic_candidate: array<string, mixed>|null
     * }
     */
    public function find(
        array $target,
        ?array $languageOverride = null,
        int $limit = 5,
    ): array {
        $service = mb_strtolower(trim((string) ($target['service'] ?? '')));
        $scope = MediaReplacementScope::tryFrom((string) ($target['scope'] ?? ''))
            ?? throw new InvalidArgumentException('target scope must be anime, tv, or movie.');

        $effectiveLanguages = $this->mediaReplacementSettings->effectiveLanguages($scope, $languageOverride);
        $guidance = $this->mediaReplacementSettings->guidance($scope);
        $seasonPackPolicy = $this->mediaReplacementSettings->seasonPackPolicy();

        $ranked = $this->releaseCandidateRanker->rank(
            releases: $this->searchReleases($service, $target),
            requiredLanguages: $effectiveLanguages,
            rules: is_array($guidance['rules']) ? $guidance['rules'] : [],
            target: $target,
            seasonPackPolicy: $seasonPackPolicy,
            limit: $limit,
        );

        return [
            'target' => $target,
            'effective_languages' => $effectiveLanguages,
            'guidance' => ['notes' => $guidance['notes']],
            'candidates' => $ranked['candidates'],
            'excluded' => $ranked['excluded'],
            'unique_best' => $ranked['unique_best'],
            'automatic_candidate' => $this->automaticCandidate($ranked, $seasonPackPolicy),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<int, array<string, mixed>>
     */
    private function searchReleases(string $service, array $target): array
    {
        return match ($service) {
            'sonarr' => new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr))
                ->getReleases($this->sonarrSearchParams($target)),
            'radarr' => new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr))
                ->getReleases(['movieId' => (int) ($target['movie_id'] ?? 0)]),
            default => throw new InvalidArgumentException('target service must be "sonarr" or "radarr".'),
        };
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, int>
     */
    private function sonarrSearchParams(array $target): array
    {
        $episodeIds = is_array($target['episode_ids'] ?? null) ? array_values($target['episode_ids']) : [];
        $params = ['seriesId' => (int) ($target['series_id'] ?? 0)];

        if ($episodeIds !== []) {
            $params['episodeId'] = (int) $episodeIds[0];
        }

        return $params;
    }

    /**
     * @param  array{candidates: list<array<string, mixed>>, unique_best: bool}  $ranked
     * @return array<string, mixed>|null
     */
    private function automaticCandidate(array $ranked, SeasonPackPolicy $seasonPackPolicy): ?array
    {
        if (! $this->mediaReplacementSettings->automaticSelectionEnabled()) {
            return null;
        }

        if (! $ranked['unique_best'] || $ranked['candidates'] === []) {
            return null;
        }

        $best = $ranked['candidates'][0];

        if (($best['confidence'] ?? 0) < $this->mediaReplacementSettings->automaticSelectionThreshold()) {
            return null;
        }

        if (($best['season_pack'] ?? false) === true
            && $seasonPackPolicy !== SeasonPackPolicy::AutomaticAboveThreshold) {
            return null;
        }

        return $best;
    }
}
