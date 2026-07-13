<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use Illuminate\Support\Str;

final readonly class ReleaseCandidateRanker
{
    public function __construct(
        private ReleaseRuleMatcher $releaseRuleMatcher,
        private ReleaseFingerprint $releaseFingerprint,
        private LanguageNormalizer $languageNormalizer,
    ) {}

    /**
     * Rank eligible ARR releases by subtitle confidence and deterministic suitability fields.
     *
     * @param  array<array-key, mixed>  $releases
     * @param  array<array-key, mixed>  $requiredLanguages
     * @param  array<array-key, mixed>  $rules
     * @param  array<string, mixed>  $target
     * @return array{
     *     candidates: list<array{
     *         fingerprint: string,
     *         title: string,
     *         release_group: string,
     *         subgroup: string,
     *         mapped_ids: list<int>,
     *         quality: string|null,
     *         size: int|float,
     *         age: int|float,
     *         seeders: int|float,
     *         custom_format_score: int|float,
     *         confidence: int,
     *         matched_rules: list<array{
     *             name: string,
     *             strength: 'guarantee'|'strong_evidence'|'preference',
     *             languages: list<string>,
     *             evidences_subtitles: bool,
     *             explanation?: string
     *         }>,
     *         season_pack: bool
     *     }>,
     *     excluded: array{
     *         mapping: int,
     *         unavailable: int,
     *         rejected: int,
     *         installed_release: int,
     *         subtitle_evidence: int,
     *         season_pack_policy: int
     *     },
     *     unique_best: bool
     * }
     */
    public function rank(
        array $releases,
        array $requiredLanguages,
        array $rules,
        array $target,
        SeasonPackPolicy $seasonPackPolicy,
        int $limit = 5,
    ): array {
        $limit = max(1, min(10, $limit));
        $requiredLanguages = $this->normalizedLanguages($requiredLanguages);
        $excluded = [
            'mapping' => 0,
            'unavailable' => 0,
            'rejected' => 0,
            'installed_release' => 0,
            'subtitle_evidence' => 0,
            'season_pack_policy' => 0,
        ];
        $rankedCandidates = [];

        foreach ($releases as $release) {
            if (! is_array($release) || ! $this->matchesTarget($release, $target)) {
                $excluded['mapping']++;

                continue;
            }

            if (($release['downloadAllowed'] ?? null) !== true) {
                $excluded['unavailable']++;

                continue;
            }

            if ($this->hasRejections($release['rejections'] ?? null)) {
                $excluded['rejected']++;

                continue;
            }

            if ($this->isInstalledRelease($release['title'] ?? null, $target['installed_release'] ?? null)) {
                $excluded['installed_release']++;

                continue;
            }

            $ruleEvaluation = $this->evaluateRules($rules, $release, $requiredLanguages);

            if ($ruleEvaluation === null) {
                $excluded['subtitle_evidence']++;

                continue;
            }

            $seasonPack = ($release['fullSeason'] ?? null) === true;

            if ($seasonPack && $seasonPackPolicy === SeasonPackPolicy::Never) {
                $excluded['season_pack_policy']++;

                continue;
            }

            $service = $this->service($target);
            $customFormatScore = $this->number($release['customFormatScore'] ?? null);
            $qualityWeight = $this->number($release['qualityWeight'] ?? null);
            $seeders = $this->number($release['seeders'] ?? null);
            $age = $this->number($release['ageMinutes'] ?? null);

            $rankedCandidates[] = [
                'candidate' => [
                    'fingerprint' => $this->releaseFingerprint->make($service, $release),
                    'title' => $this->string($release['title'] ?? null),
                    'release_group' => $this->string($release['releaseGroup'] ?? null),
                    'subgroup' => $this->string($release['subGroup'] ?? null),
                    'mapped_ids' => $this->mappedIds($service, $release),
                    'quality' => $this->quality($release['quality'] ?? null),
                    'size' => $this->number($release['size'] ?? null),
                    'age' => $age,
                    'seeders' => $seeders,
                    'custom_format_score' => $customFormatScore,
                    'confidence' => $ruleEvaluation['confidence'],
                    'matched_rules' => $ruleEvaluation['matched_rules'],
                    'season_pack' => $seasonPack,
                ],
                'ranking' => [
                    'confidence' => $ruleEvaluation['confidence'],
                    'preference_count' => $ruleEvaluation['preference_count'],
                    'custom_format_score' => $customFormatScore,
                    'quality_weight' => $qualityWeight,
                    'seeders' => $seeders,
                    'age' => $age,
                ],
            ];
        }

        usort($rankedCandidates, $this->compare(...));

        $uniqueBest = match (count($rankedCandidates)) {
            0 => false,
            1 => true,
            default => $this->compareSubstantive($rankedCandidates[0], $rankedCandidates[1]) !== 0,
        };
        $candidates = array_map(
            static fn (array $rankedCandidate): array => $rankedCandidate['candidate'],
            array_slice($rankedCandidates, 0, $limit),
        );

        return [
            'candidates' => $candidates,
            'excluded' => $excluded,
            'unique_best' => $uniqueBest,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @param  array<string, mixed>  $release
     * @param  list<string>  $requiredLanguages
     * @return array{
     *     confidence: int,
     *     preference_count: int,
     *     matched_rules: list<array{
     *         name: string,
     *         strength: 'guarantee'|'strong_evidence'|'preference',
     *         languages: list<string>,
     *         evidences_subtitles: bool,
     *         explanation?: string
     *     }>
     * }|null
     */
    private function evaluateRules(array $rules, array $release, array $requiredLanguages): ?array
    {
        if ($requiredLanguages === []) {
            return null;
        }

        $confidenceByLanguage = array_fill_keys($requiredLanguages, null);
        $matchedRules = [];
        $preferenceCount = 0;

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (! $this->releaseRuleMatcher->matches($rule, $release)) {
                continue;
            }

            $strength = SubtitleRuleStrength::tryFrom($rule['strength']);
            $ruleLanguages = $this->normalizedLanguages($rule['languages']);
            $evidencedLanguages = array_values(array_intersect($requiredLanguages, $ruleLanguages));
            if ($strength === null) {
                continue;
            }

            if ($evidencedLanguages === []) {
                continue;
            }

            $evidencesSubtitles = $strength !== SubtitleRuleStrength::Preference;

            if ($evidencesSubtitles) {
                $confidence = $strength->confidence();

                foreach ($evidencedLanguages as $evidencedLanguage) {
                    $confidenceByLanguage[$evidencedLanguage] = max(
                        $confidenceByLanguage[$evidencedLanguage] ?? 0,
                        $confidence ?? 0,
                    );
                }
            } else {
                $preferenceCount++;
            }

            $matchedRules[] = $this->matchedRule(
                $rule,
                $strength,
                $evidencedLanguages,
                $evidencesSubtitles,
            );
        }

        foreach ($confidenceByLanguage as $confidence) {
            if (! is_int($confidence) || $confidence <= 0) {
                return null;
            }
        }

        return [
            'confidence' => min($confidenceByLanguage),
            'preference_count' => $preferenceCount,
            'matched_rules' => $matchedRules,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $languages
     * @return array{
     *     name: string,
     *     strength: 'guarantee'|'strong_evidence'|'preference',
     *     languages: list<string>,
     *     evidences_subtitles: bool,
     *     explanation?: string
     * }
     */
    private function matchedRule(
        array $rule,
        SubtitleRuleStrength $subtitleRuleStrength,
        array $languages,
        bool $evidencesSubtitles,
    ): array {
        $matchedRule = [
            'name' => $this->nonEmptyString($rule['name'] ?? null) ?? 'Untitled rule',
            'strength' => $subtitleRuleStrength->value,
            'languages' => $languages,
            'evidences_subtitles' => $evidencesSubtitles,
        ];
        $explanation = $this->nonEmptyString($rule['explanation'] ?? null);

        if ($explanation !== null) {
            $matchedRule['explanation'] = $explanation;
        }

        return $matchedRule;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $target
     */
    private function matchesTarget(array $release, array $target): bool
    {
        return match ($this->service($target)) {
            'sonarr' => $this->matchesSonarrTarget($release, $target),
            'radarr' => $this->matchesRadarrTarget($release, $target),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $target
     */
    private function matchesSonarrTarget(array $release, array $target): bool
    {
        if (! is_array($release['episodeIds'] ?? null) || ! is_array($target['episode_ids'] ?? null)) {
            return false;
        }

        $releaseIds = $this->validatedIds($release['episodeIds']);
        $targetIds = $this->validatedIds($target['episode_ids']);

        return $releaseIds !== null && $releaseIds === $targetIds;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $target
     */
    private function matchesRadarrTarget(array $release, array $target): bool
    {
        $releaseId = $this->integer($release['movieId'] ?? null);
        $targetId = $this->integer($target['movie_id'] ?? null);

        return $releaseId !== null && $releaseId === $targetId;
    }

    private function hasRejections(mixed $rejections): bool
    {
        if (is_array($rejections)) {
            return $rejections !== [];
        }

        return $rejections !== null && $rejections !== '';
    }

    private function isInstalledRelease(mixed $title, mixed $installedRelease): bool
    {
        $normalizedTitle = $this->normalizedString($title);
        $normalizedInstalledRelease = $this->normalizedString($installedRelease);

        return $normalizedTitle !== null
            && $normalizedInstalledRelease !== null
            && $normalizedTitle === $normalizedInstalledRelease;
    }

    /**
     * @param  array{candidate: array<string, mixed>, ranking: array<string, int|float>}  $left
     * @param  array{candidate: array<string, mixed>, ranking: array<string, int|float>}  $right
     */
    private function compare(array $left, array $right): int
    {
        $substantiveComparison = $this->compareSubstantive($left, $right);

        if ($substantiveComparison !== 0) {
            return $substantiveComparison;
        }

        $leftTitle = (string) $left['candidate']['title'];
        $rightTitle = (string) $right['candidate']['title'];
        $foldedComparison = Str::lower($leftTitle) <=> Str::lower($rightTitle);

        return $foldedComparison !== 0 ? $foldedComparison : $leftTitle <=> $rightTitle;
    }

    /**
     * @param  array{candidate: array<string, mixed>, ranking: array<string, int|float>}  $left
     * @param  array{candidate: array<string, mixed>, ranking: array<string, int|float>}  $right
     */
    private function compareSubstantive(array $left, array $right): int
    {
        foreach (['confidence', 'preference_count', 'custom_format_score', 'quality_weight', 'seeders'] as $field) {
            $comparison = $right['ranking'][$field] <=> $left['ranking'][$field];

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $left['ranking']['age'] <=> $right['ranking']['age'];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function service(array $target): string
    {
        return $this->normalizedString($target['service'] ?? null) ?? '';
    }

    /**
     * @param  array<string, mixed>  $release
     * @return list<int>
     */
    private function mappedIds(string $service, array $release): array
    {
        if ($service === 'sonarr' && is_array($release['episodeIds'] ?? null)) {
            return $this->normalizeIds($release['episodeIds']);
        }

        $movieId = $this->integer($release['movieId'] ?? null);

        return $movieId === null ? [] : [$movieId];
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalizedIds = [];

        foreach ($ids as $id) {
            $normalizedId = $this->integer($id);

            if ($normalizedId !== null) {
                $normalizedIds[$normalizedId] = $normalizedId;
            }
        }

        sort($normalizedIds, SORT_NUMERIC);

        return array_values($normalizedIds);
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>|null
     */
    private function validatedIds(array $ids): ?array
    {
        foreach ($ids as $id) {
            if ($this->integer($id) === null) {
                return null;
            }
        }

        $normalizedIds = $this->normalizeIds($ids);

        return $normalizedIds === [] ? null : $normalizedIds;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', trim($value)) !== 1) {
            return null;
        }

        $integer = filter_var(trim($value), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($integer) ? $integer : null;
    }

    private function quality(mixed $quality): ?string
    {
        if (is_array($quality)) {
            $quality = is_array($quality['quality'] ?? null)
                ? ($quality['quality']['name'] ?? null)
                : ($quality['name'] ?? null);
        }

        return $this->nonEmptyString($quality);
    }

    private function number(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : 0;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $value = trim($value);
            $number = (float) $value;

            if (! is_finite($number) || $number > PHP_INT_MAX || $number < PHP_INT_MIN) {
                return 0;
            }

            if (preg_match('/^[+-]?\d+$/D', $value) === 1) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);

                return is_int($integer) ? $integer : 0;
            }

            return $number;
        }

        return 0;
    }

    /**
     * @param  array<array-key, mixed>  $languages
     * @return list<string>
     */
    private function normalizedLanguages(array $languages): array
    {
        $validLanguages = [];

        foreach ($languages as $language) {
            if (! is_string($language)) {
                continue;
            }
            if (! mb_check_encoding($language, 'UTF-8')) {
                continue;
            }
            $validLanguages[] = $language;
        }

        return $this->languageNormalizer->normalizeMany($validLanguages);
    }

    private function string(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = (string) $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $value = $this->string($value);

        return trim($value) === '' ? null : $value;
    }

    private function normalizedString(mixed $value): ?string
    {
        $value = $this->nonEmptyString($value);

        return $value === null ? null : Str::of($value)->trim()->lower()->toString();
    }
}
