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
     *     subtitle_check: array{
     *         enabled: bool,
     *         max_attempts_per_target: int<1, max>,
     *         cooldown_hours: int<1, max>
     *     },
     *     subtitle_audit: array{
     *         search_minutes_per_episode: int,
     *         min_search_minutes: int,
     *         max_search_minutes: int,
     *         in_chat_wait_seconds: int,
     *         watchdog_delay_seconds: int,
     *         sweep_grace_minutes: int
     *     },
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
        'subtitle_check' => [
            'enabled' => false,
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 24,
        ],
        'subtitle_audit' => [
            'search_minutes_per_episode' => 3,
            'min_search_minutes' => 30,
            'max_search_minutes' => 720,
            'in_chat_wait_seconds' => 60,
            'watchdog_delay_seconds' => 180,
            'sweep_grace_minutes' => 5,
        ],
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

    /**
     * SearchSubtitleAuditEpisode's #[Timeout(270)] must stay below this grace
     * (300 s), so a live search never looks expired to the sweeper.
     */
    public const int MIN_SUBTITLE_AUDIT_SWEEP_GRACE_MINUTES = 5;

    /**
     * The accepted range of every subtitle audit tunable. The update request
     * validates against the same bounds (the watchdog delay additionally must
     * outlast the request ceiling); normalization falls back to the default
     * for a stored value outside them.
     *
     * @var array{
     *     search_minutes_per_episode: array{int, int},
     *     min_search_minutes: array{int, int},
     *     max_search_minutes: array{int, int},
     *     in_chat_wait_seconds: array{int, int},
     *     watchdog_delay_seconds: array{int, int},
     *     sweep_grace_minutes: array{int, int}
     * }
     */
    public const array SUBTITLE_AUDIT_BOUNDS = [
        'search_minutes_per_episode' => [1, 30],
        'min_search_minutes' => [5, 720],
        'max_search_minutes' => [30, 1440],
        'in_chat_wait_seconds' => [0, 120],
        'watchdog_delay_seconds' => [1, 3600],
        'sweep_grace_minutes' => [self::MIN_SUBTITLE_AUDIT_SWEEP_GRACE_MINUTES, 120],
    ];

    /** How far past the request ceiling a watchdog delay inside it is moved. */
    private const int WATCHDOG_CEILING_MARGIN_SECONDS = 30;

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
     *     subtitle_check: array{
     *         enabled: bool,
     *         max_attempts_per_target: int<1, max>,
     *         cooldown_hours: int<1, max>
     *     },
     *     subtitle_audit: array{
     *         search_minutes_per_episode: int,
     *         min_search_minutes: int,
     *         max_search_minutes: int,
     *         in_chat_wait_seconds: int,
     *         watchdog_delay_seconds: int,
     *         sweep_grace_minutes: int
     *     },
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

    public function subtitleCheckEnabled(): bool
    {
        return $this->configuration()['subtitle_check']['enabled'];
    }

    public function subtitleCheckMaxAttempts(): int
    {
        return $this->configuration()['subtitle_check']['max_attempts_per_target'];
    }

    public function subtitleCheckCooldownHours(): int
    {
        return $this->configuration()['subtitle_check']['cooldown_hours'];
    }

    /**
     * The search window of a subtitle audit batch is this many minutes per
     * missing episode (the searches run one after another on a single worker),
     * held between the minimum and maximum below. A searching batch blocks a
     * new repair of its series until the window passes.
     */
    public function subtitleAuditSearchMinutesPerEpisode(): int
    {
        return $this->configuration()['subtitle_audit']['search_minutes_per_episode'];
    }

    /**
     * Never above the maximum, so a stored minimum past it cannot widen the
     * window beyond the cap.
     */
    public function subtitleAuditMinSearchMinutes(): int
    {
        $subtitleAudit = $this->configuration()['subtitle_audit'];

        return min($subtitleAudit['min_search_minutes'], $subtitleAudit['max_search_minutes']);
    }

    public function subtitleAuditMaxSearchMinutes(): int
    {
        return $this->configuration()['subtitle_audit']['max_search_minutes'];
    }

    /**
     * How long the chat turn waits for the release searches before handing the
     * batch off; 0 hands it off at once. RepairSeriesSubtitlesTool still caps
     * the wait at what is left of the request.
     */
    public function subtitleAuditInChatWaitSeconds(): int
    {
        return $this->configuration()['subtitle_audit']['in_chat_wait_seconds'];
    }

    /**
     * The delay of the batch watchdog. The chat turn that started the batch
     * runs inside the HTTP request, so the watchdog must outlast the Octane
     * request ceiling before it adopts a batch nobody claimed; a delay at or
     * inside the ceiling is moved 30 s past it. Without a ceiling the saved
     * value applies as is.
     */
    public function subtitleAuditWatchdogDelaySeconds(): int
    {
        $delay = $this->configuredSubtitleAuditWatchdogDelaySeconds();
        $ceiling = self::requestCeilingSeconds();

        return $ceiling > 0 && $delay <= $ceiling
            ? $ceiling + self::WATCHDOG_CEILING_MARGIN_SECONDS
            : $delay;
    }

    /**
     * The saved watchdog delay, unclamped, for the admin form.
     */
    public function configuredSubtitleAuditWatchdogDelaySeconds(): int
    {
        return $this->configuration()['subtitle_audit']['watchdog_delay_seconds'];
    }

    /**
     * How long past its search deadline the sweeper waits before it fails a
     * batch's searches. Safe only while SearchSubtitleAuditEpisode's
     * #[Timeout(270)] stays below the 5 minute floor: a live search never looks
     * expired. The searcher's guarded outcome write makes any overlap harmless.
     */
    public function subtitleAuditSweepGraceMinutes(): int
    {
        return max(
            self::MIN_SUBTITLE_AUDIT_SWEEP_GRACE_MINUTES,
            $this->configuration()['subtitle_audit']['sweep_grace_minutes'],
        );
    }

    /**
     * Octane's request ceiling in seconds (`octane.max_execution_time`); 0
     * means no ceiling.
     */
    public static function requestCeilingSeconds(): int
    {
        return max(0, (int) config('octane.max_execution_time', 0));
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
     *     subtitle_check: array{
     *         enabled: bool,
     *         max_attempts_per_target: int<1, max>,
     *         cooldown_hours: int<1, max>
     *     },
     *     subtitle_audit: array{
     *         search_minutes_per_episode: int,
     *         min_search_minutes: int,
     *         max_search_minutes: int,
     *         in_chat_wait_seconds: int,
     *         watchdog_delay_seconds: int,
     *         sweep_grace_minutes: int
     *     },
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
            ? (SeasonPackPolicy::tryFrom($seasonPackPolicy)->value ?? self::DEFAULT_CONFIGURATION['season_pack_policy'])
            : self::DEFAULT_CONFIGURATION['season_pack_policy'];
        $normalizedConfiguration['global_languages'] = is_array($globalLanguages)
            ? $this->normalizeLanguageList($globalLanguages)
            : self::DEFAULT_CONFIGURATION['global_languages'];
        $normalizedConfiguration['sonarr_root_folders'] = is_array($sonarrRootFolders)
            ? $this->normalizeSonarrRootFolders($sonarrRootFolders)
            : self::DEFAULT_CONFIGURATION['sonarr_root_folders'];

        $subtitleCheck = is_array($configuration['subtitle_check'] ?? null)
            ? $configuration['subtitle_check']
            : [];
        $subtitleCheckEnabled = $subtitleCheck['enabled'] ?? null;
        $subtitleCheckMaxAttempts = $subtitleCheck['max_attempts_per_target'] ?? null;
        $subtitleCheckCooldownHours = $subtitleCheck['cooldown_hours'] ?? null;

        $normalizedConfiguration['subtitle_check'] = [
            'enabled' => is_bool($subtitleCheckEnabled)
                ? $subtitleCheckEnabled
                : self::DEFAULT_CONFIGURATION['subtitle_check']['enabled'],
            'max_attempts_per_target' => is_int($subtitleCheckMaxAttempts) && $subtitleCheckMaxAttempts >= 1
                ? $subtitleCheckMaxAttempts
                : self::DEFAULT_CONFIGURATION['subtitle_check']['max_attempts_per_target'],
            'cooldown_hours' => is_int($subtitleCheckCooldownHours) && $subtitleCheckCooldownHours >= 1
                ? $subtitleCheckCooldownHours
                : self::DEFAULT_CONFIGURATION['subtitle_check']['cooldown_hours'],
        ];

        $subtitleAudit = is_array($configuration['subtitle_audit'] ?? null)
            ? $configuration['subtitle_audit']
            : [];

        foreach (self::SUBTITLE_AUDIT_BOUNDS as $key => [$minimum, $maximum]) {
            $value = $subtitleAudit[$key] ?? null;

            $normalizedConfiguration['subtitle_audit'][$key] = is_int($value) && $value >= $minimum && $value <= $maximum
                ? $value
                : self::DEFAULT_CONFIGURATION['subtitle_audit'][$key];
        }

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
