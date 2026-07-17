<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\ServiceType;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\MediaReplacement\LanguageNormalizer;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;
use JsonException;
use LengthException;

final readonly class SubtitleAdvisorProjection
{
    private const int MAX_JSON_BYTES = 12_000;

    private const int MAX_CANDIDATES = 5;

    public function __construct(
        private MediaFileInspector $mediaFileInspector,
        private ReplacementCandidateFinder $replacementCandidateFinder,
        private SubtitleCaseFingerprint $subtitleCaseFingerprint,
        private LanguageNormalizer $languageNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function forCase(SubtitleCase $subtitleCase): array
    {
        return $this->replacementContextForCase($subtitleCase)['projection'];
    }

    /**
     * Server-side replacement context. Only `projection` is safe for the model.
     *
     * @return array{
     *     projection: array<string, mixed>,
     *     target: array<string, mixed>,
     *     automatic_candidate: array<string, mixed>|null,
     *     effective_languages: list<string>
     * }
     *
     * @throws JsonException
     */
    public function replacementContextForCase(SubtitleCase $subtitleCase): array
    {
        $subtitleCase = SubtitleCase::query()
            ->with('serviceConnection')
            ->findOrFail($subtitleCase->id);

        throw_unless(
            in_array($subtitleCase->status, [
                SubtitleCaseStatus::ReplacementEligible,
                SubtitleCaseStatus::AdvisorRunning,
            ], true),
            InvalidArgumentException::class,
            'The subtitle case is not eligible for Advisor inspection.',
        );

        $mappedConnection = $subtitleCase->serviceConnection;
        $service = $this->mappedService($mappedConnection);
        $target = $this->inspectTarget($subtitleCase, $mappedConnection, $service);
        $freshFileFingerprint = $this->fileFingerprint($target, $mappedConnection, $service);

        throw_unless(
            hash_equals($subtitleCase->file_fingerprint, $freshFileFingerprint),
            InvalidArgumentException::class,
            'The installed file changed after the subtitle case was observed.',
        );

        $requiredLanguages = $this->requiredLanguages($subtitleCase);
        $replacement = $this->replacementCandidateFinder->find(
            target: $target,
            languageOverride: $requiredLanguages,
            limit: self::MAX_CANDIDATES,
            serviceConnection: $mappedConnection,
        );
        $candidates = array_values(array_map(
            $this->sanitizeCandidate(...),
            array_slice($replacement['candidates'], 0, self::MAX_CANDIDATES),
        ));
        $automaticFingerprint = is_array($replacement['automatic_candidate'] ?? null)
            ? ($replacement['automatic_candidate']['fingerprint'] ?? null)
            : null;
        $automaticCandidate = is_string($automaticFingerprint)
            ? collect($candidates)->first(
                static fn (array $candidate): bool => ($candidate['fingerprint'] ?? null) === $automaticFingerprint,
            )
            : null;
        $lastProbe = $subtitleCase->attempts()
            ->where('type', SubtitleCaseAttemptType::Probe)
            ->latest('started_at')
            ->first(['started_at']);

        $projection = [
            'case_id' => $subtitleCase->id,
            'bazarr_connection_id' => $subtitleCase->bazarr_connection_id,
            'service' => $service,
            'service_connection_id' => $mappedConnection->id,
            'scope' => $subtitleCase->scope,
            'display_name' => $this->safeText(
                $subtitleCase->evidence['display_name'] ?? $target['display_name'] ?? null,
                240,
                'Subtitle case #'.$subtitleCase->id,
            ),
            'required_languages' => $requiredLanguages,
            'current_subtitles' => $this->languageNormalizer->normalizeMany(
                is_array($target['subtitles'] ?? null) ? $target['subtitles'] : [],
            ),
            'bazarr_evidence' => [
                'first_seen_at' => $subtitleCase->observed_at->toIso8601ZuluString(),
                'empty_probe_count' => $subtitleCase->attempts()
                    ->where('type', SubtitleCaseAttemptType::Probe)
                    ->where('outcome', SubtitleCaseAttemptOutcome::Empty)
                    ->count(),
                'last_probe_at' => $lastProbe?->started_at->toIso8601ZuluString(),
                'download_attempted' => $subtitleCase->download_action_request_id !== null
                    || $subtitleCase->attempts()->where('type', SubtitleCaseAttemptType::Download)->exists(),
            ],
            'replacement' => [
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
                'automatic_candidate' => $automaticCandidate,
            ],
        ];

        return [
            'projection' => $this->fitJsonBudget($projection),
            'target' => $target,
            'automatic_candidate' => is_array($replacement['automatic_candidate'] ?? null)
                ? $replacement['automatic_candidate']
                : null,
            'effective_languages' => $replacement['effective_languages'],
        ];
    }

    private function mappedService(ServiceConnection $serviceConnection): string
    {
        throw_unless(
            $serviceConnection->is_active
                && in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true),
            InvalidArgumentException::class,
            'The subtitle case mapped service connection is unavailable.',
        );

        return $serviceConnection->type->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectTarget(
        SubtitleCase $subtitleCase,
        ServiceConnection $serviceConnection,
        string $service,
    ): array {
        if ($service === ServiceType::Radarr->value) {
            $movieId = $this->positiveInteger($subtitleCase->target_ids['radarr_id'] ?? null);
            throw_if($movieId === null, InvalidArgumentException::class, 'The subtitle case movie target is invalid.');

            $target = $this->mediaFileInspector->inspect(
                service: $service,
                itemId: $movieId,
                serviceConnection: $serviceConnection,
            );
        } else {
            $seriesId = $this->positiveInteger($subtitleCase->target_ids['series_id'] ?? null);
            $episodeId = $this->positiveInteger($subtitleCase->target_ids['episode_id'] ?? null);
            throw_if(
                $seriesId === null || $episodeId === null,
                InvalidArgumentException::class,
                'The subtitle case episode target is invalid.',
            );

            $episode = collect(new SonarrClient($serviceConnection)->getEpisodesBySeries($seriesId))
                ->first(fn (mixed $candidate): bool => is_array($candidate)
                    && $this->positiveInteger($candidate['id'] ?? null) === $episodeId);
            throw_unless(is_array($episode), InvalidArgumentException::class, 'The subtitle case episode no longer exists.');

            $target = $this->mediaFileInspector->inspect(
                service: $service,
                itemId: $seriesId,
                seasonNumber: $this->nonNegativeInteger($episode['seasonNumber'] ?? null),
                episodeNumber: $this->positiveInteger($episode['episodeNumber'] ?? null),
                absoluteEpisodeNumber: $this->positiveInteger($episode['absoluteEpisodeNumber'] ?? null),
                serviceConnection: $serviceConnection,
            );
        }

        throw_if(
            ($target['ambiguous'] ?? true) === true
                || ($target['service_connection_id'] ?? null) !== $serviceConnection->id,
            InvalidArgumentException::class,
            'The subtitle case target is no longer uniquely inspectable.',
        );

        return $target;
    }

    /**
     * @param  array<string, mixed>  $target
     *
     * @throws JsonException
     */
    private function fileFingerprint(
        array $target,
        ServiceConnection $serviceConnection,
        string $service,
    ): string {
        $isSonarr = $service === ServiceType::Sonarr->value;

        return $this->subtitleCaseFingerprint->file([
            'service' => $service,
            'service_connection_id' => $serviceConnection->id,
            'file_ids' => $target[$isSonarr ? 'episode_file_ids' : 'movie_file_ids'] ?? [],
            'media_ids' => $isSonarr
                ? ($target['episode_ids'] ?? [])
                : [$target['movie_id'] ?? null],
            'size' => $target['size'] ?? null,
            'date_added' => $target['date_added'] ?? null,
            'scene_name' => $target['scene_name'] ?? null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function requiredLanguages(SubtitleCase $subtitleCase): array
    {
        $languages = array_map(
            static fn (mixed $requirement): mixed => is_array($requirement)
                ? ($requirement['code'] ?? null)
                : $requirement,
            $subtitleCase->required_languages,
        );

        return $this->languageNormalizer->normalizeMany($languages);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function sanitizeCandidate(array $candidate): array
    {
        return [
            'fingerprint' => is_string($candidate['fingerprint'] ?? null)
                ? mb_substr($candidate['fingerprint'], 0, 64)
                : '',
            'title' => $this->safeText($candidate['title'] ?? null, 240),
            'release_group' => $this->safeText($candidate['release_group'] ?? null, 80),
            'subgroup' => $this->safeText($candidate['subgroup'] ?? null, 80),
            'mapped_ids' => array_values(array_slice(array_filter(
                is_array($candidate['mapped_ids'] ?? null) ? $candidate['mapped_ids'] : [],
                static fn (mixed $id): bool => is_int($id) && $id > 0,
            ), 0, 20)),
            'quality' => $this->safeText($candidate['quality'] ?? null, 80),
            'size' => $this->number($candidate['size'] ?? null),
            'age' => $this->number($candidate['age'] ?? null),
            'seeders' => $this->number($candidate['seeders'] ?? null),
            'custom_format_score' => $this->number($candidate['custom_format_score'] ?? null),
            'confidence' => is_int($candidate['confidence'] ?? null)
                ? max(0, min(100, $candidate['confidence']))
                : 0,
            'requires_approval' => ($candidate['requires_approval'] ?? false) === true,
            'rejection_reasons' => array_values(array_map(
                fn (string $reason): string => $this->safeText($reason, 120),
                array_slice(array_filter(
                    is_array($candidate['rejection_reasons'] ?? null) ? $candidate['rejection_reasons'] : [],
                    is_string(...),
                ), 0, 3),
            )),
            'matched_rules' => array_values(array_map(
                $this->sanitizeMatchedRule(...),
                array_slice(array_filter(
                    is_array($candidate['matched_rules'] ?? null) ? $candidate['matched_rules'] : [],
                    is_array(...),
                ), 0, 3),
            )),
            'season_pack' => ($candidate['season_pack'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function sanitizeMatchedRule(array $rule): array
    {
        return [
            'name' => $this->safeText($rule['name'] ?? null, 80, 'Rule'),
            'strength' => in_array($rule['strength'] ?? null, [
                'guarantee',
                'strong_evidence',
                'preference',
            ], true) ? $rule['strength'] : 'preference',
            'languages' => array_values(array_slice(array_filter(
                is_array($rule['languages'] ?? null) ? $rule['languages'] : [],
                static fn (mixed $language): bool => is_string($language)
                    && preg_match('/^[a-z]{2,3}$/D', $language) === 1,
            ), 0, 10)),
            'evidences_subtitles' => ($rule['evidences_subtitles'] ?? false) === true,
            'explanation' => $this->safeText($rule['explanation'] ?? null, 160),
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function fitJsonBudget(array $projection): array
    {
        while (strlen(json_encode($projection, JSON_THROW_ON_ERROR)) > self::MAX_JSON_BYTES
            && count($projection['replacement']['candidates']) > 1) {
            array_pop($projection['replacement']['candidates']);
        }

        throw_if(
            strlen(json_encode($projection, JSON_THROW_ON_ERROR)) > self::MAX_JSON_BYTES,
            LengthException::class,
            'The subtitle Advisor projection exceeds its safe size limit.',
        );

        return $projection;
    }

    private function safeText(mixed $value, int $limit, string $fallback = ''): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $fallback;
        }

        $value = trim($value);

        if ($value === ''
            || str_contains($value, '://')
            || str_contains($value, '/')
            || str_contains($value, '\\')) {
            return $fallback;
        }

        return mb_substr($value, 0, $limit);
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function number(mixed $value): int|float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? $value : 0;
    }
}
