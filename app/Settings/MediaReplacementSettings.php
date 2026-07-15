<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\MediaReplacementScope;
use App\Enums\SeasonPackPolicy;
use App\Services\MediaReplacement\LanguageNormalizer;

final readonly class MediaReplacementSettings
{
    /**
     * @var array{
     *     automatic_selection_enabled: bool,
     *     automatic_selection_threshold: int<0, 100>,
     *     global_languages: list<string>,
     *     scoped_languages: array{anime: null, tv: null, movie: null},
     *     season_pack_policy: value-of<SeasonPackPolicy>,
     *     guidance: array{
     *         anime: array{rules: array<array-key, mixed>, notes: string},
     *         tv: array{rules: array<array-key, mixed>, notes: string},
     *         movie: array{rules: array<array-key, mixed>, notes: string}
     *     }
     * }
     */
    private const array DEFAULT_CONFIGURATION = [
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['eng'],
        'scoped_languages' => [
            'anime' => null,
            'tv' => null,
            'movie' => null,
        ],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'rules' => [],
                'notes' => '',
            ],
            'tv' => [
                'rules' => [],
                'notes' => '',
            ],
            'movie' => [
                'rules' => [],
                'notes' => '',
            ],
        ],
    ];

    private const string SETTINGS_KEY = 'ai.media_replacement';

    public function __construct(
        private AppSettings $appSettings,
        private LanguageNormalizer $languageNormalizer,
    ) {}

    /**
     * @return array{
     *     automatic_selection_enabled: bool,
     *     automatic_selection_threshold: int<0, 100>,
     *     global_languages: list<string>,
     *     scoped_languages: array{
     *         anime: list<string>|null,
     *         tv: list<string>|null,
     *         movie: list<string>|null
     *     },
     *     season_pack_policy: value-of<SeasonPackPolicy>,
     *     guidance: array{
     *         anime: array{rules: array<array-key, mixed>, notes: string},
     *         tv: array{rules: array<array-key, mixed>, notes: string},
     *         movie: array{rules: array<array-key, mixed>, notes: string}
     *     }
     * }
     */
    public function configuration(): array
    {
        $storedConfiguration = $this->appSettings->get(self::SETTINGS_KEY, []);

        if (! is_array($storedConfiguration)) {
            $storedConfiguration = [];
        }

        return $this->normalizeConfiguration($storedConfiguration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $normalizedConfiguration = $this->normalizeConfiguration($configuration);

        $this->appSettings->set(self::SETTINGS_KEY, $normalizedConfiguration);
    }

    public function automaticSelectionEnabled(): bool
    {
        return $this->configuration()['automatic_selection_enabled'];
    }

    public function automaticSelectionThreshold(): int
    {
        return $this->configuration()['automatic_selection_threshold'];
    }

    public function seasonPackPolicy(): SeasonPackPolicy
    {
        return SeasonPackPolicy::from($this->configuration()['season_pack_policy']);
    }

    /**
     * @param  array<array-key, mixed>|null  $requestOverride
     * @return list<string>
     */
    public function effectiveLanguages(MediaReplacementScope $mediaReplacementScope, ?array $requestOverride = null): array
    {
        if ($requestOverride !== null) {
            return $this->normalizeLanguageList($requestOverride);
        }

        $configuration = $this->configuration();
        $scopedLanguages = $configuration['scoped_languages'][$mediaReplacementScope->value];

        if ($scopedLanguages !== null) {
            return $scopedLanguages;
        }

        return $configuration['global_languages'];
    }

    /**
     * @return array{rules: array<array-key, mixed>, notes: string}
     */
    public function guidance(MediaReplacementScope $mediaReplacementScope): array
    {
        return $this->configuration()['guidance'][$mediaReplacementScope->value];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{
     *     automatic_selection_enabled: bool,
     *     automatic_selection_threshold: int<0, 100>,
     *     global_languages: list<string>,
     *     scoped_languages: array{
     *         anime: list<string>|null,
     *         tv: list<string>|null,
     *         movie: list<string>|null
     *     },
     *     season_pack_policy: value-of<SeasonPackPolicy>,
     *     guidance: array{
     *         anime: array{rules: array<array-key, mixed>, notes: string},
     *         tv: array{rules: array<array-key, mixed>, notes: string},
     *         movie: array{rules: array<array-key, mixed>, notes: string}
     *     }
     * }
     */
    private function normalizeConfiguration(array $configuration): array
    {
        $automaticSelectionEnabled = $configuration['automatic_selection_enabled'] ?? null;
        $automaticSelectionThreshold = $configuration['automatic_selection_threshold'] ?? null;
        $seasonPackPolicy = $configuration['season_pack_policy'] ?? null;
        $globalLanguages = $configuration['global_languages'] ?? null;
        $scopedLanguages = $configuration['scoped_languages'] ?? null;
        $scopeGuidance = $configuration['guidance'] ?? null;

        $normalizedConfiguration = self::DEFAULT_CONFIGURATION;
        $normalizedConfiguration['automatic_selection_enabled'] = is_bool($automaticSelectionEnabled)
            ? $automaticSelectionEnabled
            : self::DEFAULT_CONFIGURATION['automatic_selection_enabled'];
        $normalizedConfiguration['automatic_selection_threshold'] = is_int($automaticSelectionThreshold)
            && $automaticSelectionThreshold >= 0
            && $automaticSelectionThreshold <= 100
                ? $automaticSelectionThreshold
                : self::DEFAULT_CONFIGURATION['automatic_selection_threshold'];
        $normalizedConfiguration['season_pack_policy'] = is_string($seasonPackPolicy)
            ? (SeasonPackPolicy::tryFrom($seasonPackPolicy)?->value ?? self::DEFAULT_CONFIGURATION['season_pack_policy'])
            : self::DEFAULT_CONFIGURATION['season_pack_policy'];
        $normalizedConfiguration['global_languages'] = is_array($globalLanguages)
            ? $this->normalizeLanguageList($globalLanguages)
            : self::DEFAULT_CONFIGURATION['global_languages'];

        foreach (MediaReplacementScope::cases() as $scope) {
            $languages = is_array($scopedLanguages)
                ? ($scopedLanguages[$scope->value] ?? null)
                : null;

            $normalizedConfiguration['scoped_languages'][$scope->value] = is_array($languages)
                ? $this->normalizeLanguageList($languages)
                : null;

            $guidance = is_array($scopeGuidance)
                ? ($scopeGuidance[$scope->value] ?? null)
                : null;
            $normalizedConfiguration['guidance'][$scope->value] = [
                'rules' => is_array($guidance) && is_array($guidance['rules'] ?? null)
                    ? $guidance['rules']
                    : [],
                'notes' => is_array($guidance) && is_string($guidance['notes'] ?? null)
                    ? $guidance['notes']
                    : '',
            ];
        }

        return $normalizedConfiguration;
    }

    /**
     * @param  array<array-key, mixed>  $languages
     * @return list<string>
     */
    private function normalizeLanguageList(array $languages): array
    {
        return $this->languageNormalizer->normalizeMany($languages);
    }
}
