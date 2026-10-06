<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\SeasonPackPolicy;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
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
        private ReleaseFingerprint $releaseFingerprint,
        private ArrConnections $arrConnections,
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
        ?ServiceConnection $serviceConnection = null,
    ): array {
        return $this->rankedSearch($target, $languageOverride, $limit, $serviceConnection)['found'];
    }

    /**
     * One native search answered twice: the shortlist find() returns, plus the
     * raw release resource whose fingerprint matches, so an executor can
     * re-check the reviewed release and grab exactly that resource without a
     * second search (an arr release search can take up to 120 s). raw_release
     * is null when the search no longer offers the release.
     *
     * @param  array<string, mixed>  $target
     * @param  array<int, string>|null  $languageOverride
     * @return array{
     *     found: array{
     *         target: array<string, mixed>,
     *         effective_languages: list<string>,
     *         guidance: array{notes: string},
     *         candidates: list<array<string, mixed>>,
     *         excluded: array<string, int>,
     *         unique_best: bool,
     *         automatic_candidate: array<string, mixed>|null
     *     },
     *     raw_release: array<string, mixed>|null
     * }
     */
    public function findWithRawRelease(
        array $target,
        string $fingerprint,
        ?array $languageOverride = null,
        int $limit = 5,
        ?ServiceConnection $serviceConnection = null,
    ): array {
        $search = $this->rankedSearch($target, $languageOverride, $limit, $serviceConnection);

        return [
            'found' => $search['found'],
            'raw_release' => $this->matchingRelease($search['service'], $search['releases'], $fingerprint),
        ];
    }

    /**
     * Run the native search once and rank its rows. The scope and settings are
     * resolved first, so an invalid target is refused before any request.
     *
     * @param  array<string, mixed>  $target
     * @param  array<int, string>|null  $languageOverride
     * @return array{
     *     found: array{
     *         target: array<string, mixed>,
     *         effective_languages: list<string>,
     *         guidance: array{notes: string},
     *         candidates: list<array<string, mixed>>,
     *         excluded: array<string, int>,
     *         unique_best: bool,
     *         automatic_candidate: array<string, mixed>|null
     *     },
     *     service: string,
     *     releases: array<int, array<string, mixed>>
     * }
     */
    private function rankedSearch(
        array $target,
        ?array $languageOverride,
        int $limit,
        ?ServiceConnection $serviceConnection,
    ): array {
        $service = mb_strtolower(trim((string) ($target['service'] ?? '')));
        $scope = MediaReplacementScope::tryFrom((string) ($target['scope'] ?? ''))
            ?? throw new InvalidArgumentException('target scope must be anime, tv, or movie.');

        $effectiveLanguages = $this->mediaReplacementSettings->effectiveLanguages($scope, $languageOverride);
        $guidance = $this->mediaReplacementSettings->guidance($scope);
        $seasonPackPolicy = $this->mediaReplacementSettings->seasonPackPolicy();
        $releases = $this->searchReleases($service, $target, $serviceConnection);

        $ranked = $this->releaseCandidateRanker->rank(
            releases: $releases,
            requiredLanguages: $effectiveLanguages,
            rules: is_array($guidance['rules']) ? $guidance['rules'] : [],
            target: $target,
            seasonPackPolicy: $seasonPackPolicy,
            limit: $limit,
        );

        return [
            'found' => [
                'target' => $target,
                'effective_languages' => $effectiveLanguages,
                'guidance' => ['notes' => $guidance['notes']],
                'candidates' => $ranked['candidates'],
                'excluded' => $ranked['excluded'],
                'unique_best' => $ranked['unique_best'],
                'automatic_candidate' => $this->automaticCandidate($ranked, $seasonPackPolicy),
            ],
            'service' => $service,
            'releases' => $releases,
        ];
    }

    /**
     * The raw release resource whose fingerprint matches, from rows already
     * searched, so an executor grabs exactly the reviewed release.
     *
     * @param  array<int, array<string, mixed>>  $releases
     * @return array<string, mixed>|null
     */
    private function matchingRelease(string $service, array $releases, string $fingerprint): ?array
    {
        foreach ($releases as $release) {
            if (is_array($release) && $this->releaseFingerprint->make($service, $release) === $fingerprint) {
                return $release;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<int, array<string, mixed>>
     */
    private function searchReleases(string $service, array $target, ?ServiceConnection $serviceConnection): array
    {
        $serviceConnection ??= $this->connectionFor($service, $target);

        throw_unless(in_array($service, ['sonarr', 'radarr'], true), InvalidArgumentException::class, 'target service must be "sonarr" or "radarr".');

        return $this->arrConnections->client($serviceConnection)->getReleases(
            $service === 'sonarr'
                ? $this->sonarrSearchParams($target)
                : ['movieId' => (int) ($target['movie_id'] ?? 0)],
        );
    }

    /**
     * Resolve the pinned connection from the target, else the active connection.
     *
     * @param  array<string, mixed>  $target
     */
    private function connectionFor(string $service, array $target): ServiceConnection
    {
        $id = $target['service_connection_id'] ?? null;

        if (is_int($id) && $id > 0) {
            $connection = ServiceConnection::find($id);

            if ($connection instanceof ServiceConnection) {
                return $connection;
            }
        }

        return ServiceConnection::resolveActive($service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr);
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

        if (($best['requires_approval'] ?? false) === true) {
            return null;
        }

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
