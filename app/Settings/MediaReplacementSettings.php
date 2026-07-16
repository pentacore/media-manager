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
     *     sonarr_root_folders: list<array{
     *         service_connection_id: int,
     *         root_folder_id: int,
     *         path: string,
     *         scope: 'anime'|'tv'|null
     *     }>,
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
        'sonarr_root_folders' => [],
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
     *     sonarr_root_folders: list<array{
     *         service_connection_id: int,
     *         root_folder_id: int,
     *         path: string,
     *         scope: 'anime'|'tv'|null
     *     }>,
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
     * @return list<array{
     *     service_connection_id: int,
     *     root_folder_id: int,
     *     path: string,
     *     scope: 'anime'|'tv'|null
     * }>
     */
    public function sonarrRootFolders(): array
    {
        return $this->configuration()['sonarr_root_folders'];
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
     *     sonarr_root_folders: list<array{
     *         service_connection_id: int,
     *         root_folder_id: int,
     *         path: string,
     *         scope: 'anime'|'tv'|null
     *     }>,
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
        $sonarrRootFolders = $configuration['sonarr_root_folders'] ?? null;
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
        $normalizedConfiguration['sonarr_root_folders'] = is_array($sonarrRootFolders)
            ? $this->normalizeSonarrRootFolders($sonarrRootFolders)
            : self::DEFAULT_CONFIGURATION['sonarr_root_folders'];

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

    /**
     * @param  array<array-key, mixed>  $rootFolders
     * @return list<array{
     *     service_connection_id: int,
     *     root_folder_id: int,
     *     path: string,
     *     scope: 'anime'|'tv'|null
     * }>
     */
    private function normalizeSonarrRootFolders(array $rootFolders): array
    {
        $normalizedRootFolders = [];

        foreach ($rootFolders as $rootFolder) {
            if (! is_array($rootFolder)) {
                continue;
            }

            $serviceConnectionId = $rootFolder['service_connection_id'] ?? null;
            $rootFolderId = $rootFolder['root_folder_id'] ?? null;
            $path = $this->normalizePath($rootFolder['path'] ?? null);
            $scope = $rootFolder['scope'] ?? null;
            if (! is_int($serviceConnectionId)) {
                continue;
            }

            if ($serviceConnectionId <= 0) {
                continue;
            }

            if (! is_int($rootFolderId)) {
                continue;
            }

            if ($rootFolderId <= 0) {
                continue;
            }

            if ($path === null) {
                continue;
            }

            if (! is_null($scope) && ! in_array($scope, [MediaReplacementScope::Anime->value, MediaReplacementScope::Tv->value], true)) {
                continue;
            }

            $normalizedRootFolders[$serviceConnectionId.':'.$rootFolderId] = [
                'service_connection_id' => $serviceConnectionId,
                'root_folder_id' => $rootFolderId,
                'path' => $path,
                'scope' => $scope,
            ];
        }

        return array_values($normalizedRootFolders);
    }

    private function normalizePath(mixed $path): ?string
    {
        if (! is_string($path) || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', trim($path));

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return $normalizedPath === '' ? null : $normalizedPath;
    }
}
