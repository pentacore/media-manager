<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Configuration for the autonomous DecisionAgent — the background agent
 * that reasons over inbound webhook events and either suggests or takes
 * actions. Entirely separate from AiSettings (which governs the
 * interactive chat MediaAgent).
 *
 * Backed by the AppSettings key/value store; defaults fall back to
 * config('mediamanager.decision_agent.*').
 */
class DecisionAgentSettings
{
    public const string ENABLED_KEY = 'decision_agent.enabled';

    public const string MODEL_KEY = 'decision_agent.model';

    public const string ALLOWLIST_KEY = 'decision_agent.event_allowlist';

    public const string ALLOW_MANUAL_IMPORT_KEY = 'decision_agent.allow_manual_import';

    public const string NOTIFY_ON_SUGGEST_KEY = 'decision_agent.notify_on_suggest';

    public const string NOTIFY_ON_ACT_KEY = 'decision_agent.notify_on_act';

    public const string MAX_ACTIONS_KEY = 'decision_agent.max_actions_per_run';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly AiSettings $aiSettings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->appSettings->get(
            self::ENABLED_KEY,
            config('mediamanager.decision_agent.enabled', false),
        );
    }

    public function setEnabled(bool $enabled): void
    {
        $this->appSettings->set(self::ENABLED_KEY, $enabled);
    }

    /**
     * Model identifier for the DecisionAgent. Falls back to the chat
     * model when unset so a fresh install works without extra config.
     */
    public function model(): string
    {
        $value = (string) $this->appSettings->get(self::MODEL_KEY, '');

        if ($value !== '') {
            return $value;
        }

        $configured = (string) config('mediamanager.decision_agent.model', '');

        return $configured !== '' ? $configured : $this->aiSettings->model();
    }

    public function setModel(string $model): void
    {
        $this->appSettings->set(self::MODEL_KEY, $model);
    }

    /**
     * The set of inbound events the agent reacts to, each encoded as
     * "service:EventType" (e.g. "sonarr:ManualInteractionRequired").
     * Empty by default — the agent is fully opt-in.
     *
     * @return array<int, string>
     */
    public function eventAllowlist(): array
    {
        $value = $this->appSettings->get(
            self::ALLOWLIST_KEY,
            config('mediamanager.decision_agent.event_allowlist', []),
        );

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? $entry : '', $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param  array<int, string>  $allowlist
     */
    public function setEventAllowlist(array $allowlist): void
    {
        $this->appSettings->set(self::ALLOWLIST_KEY, array_values(array_unique($allowlist)));
    }

    public function isEventAllowed(string $service, string $eventType): bool
    {
        return in_array(self::eventKey($service, $eventType), $this->eventAllowlist(), true);
    }

    public static function eventKey(string $service, string $eventType): string
    {
        return mb_strtolower($service).':'.$eventType;
    }

    /**
     * Catalog of inbound events the agent can be wired to react to, grouped by
     * service. Drives both the settings UI (the checkboxes) and allowlist
     * validation, so the two never drift.
     *
     * @return array<string, array<int, string>>
     */
    public static function eventCatalog(): array
    {
        return [
            'sonarr' => [
                'Grab', 'Download', 'Rename', 'SeriesAdd', 'SeriesDelete',
                'EpisodeFileDelete', 'ManualInteractionRequired', 'Health',
                'HealthRestored', 'ApplicationUpdate',
            ],
            'radarr' => [
                'Grab', 'Download', 'Rename', 'MovieAdded', 'MovieDelete',
                'MovieFileDelete', 'ManualInteractionRequired', 'Health',
                'HealthRestored', 'ApplicationUpdate',
            ],
            'whisparr' => [
                'Grab', 'Download', 'Rename',
                'MovieAdded', 'MovieDelete', 'MovieFileDelete',
                'SeriesAdd', 'SeriesDelete', 'EpisodeFileDelete',
                'ManualInteractionRequired', 'Health', 'HealthRestored', 'ApplicationUpdate',
            ],
            'seerr' => [
                'MEDIA_PENDING', 'MEDIA_APPROVED', 'MEDIA_AUTO_APPROVED',
                'MEDIA_DECLINED', 'MEDIA_AVAILABLE', 'MEDIA_FAILED',
                'ISSUE_CREATED', 'ISSUE_REOPENED',
            ],
        ];
    }

    /**
     * Flattened "service:Event" keys from the catalog, used to validate the
     * stored allowlist.
     *
     * @return array<int, string>
     */
    public static function availableEventKeys(): array
    {
        $keys = [];
        foreach (self::eventCatalog() as $service => $events) {
            foreach ($events as $event) {
                $keys[] = self::eventKey($service, $event);
            }
        }

        return $keys;
    }

    public function allowManualImport(): bool
    {
        return (bool) $this->appSettings->get(
            self::ALLOW_MANUAL_IMPORT_KEY,
            config('mediamanager.decision_agent.allow_manual_import', false),
        );
    }

    public function setAllowManualImport(bool $allow): void
    {
        $this->appSettings->set(self::ALLOW_MANUAL_IMPORT_KEY, $allow);
    }

    public function notifyOnSuggest(): bool
    {
        return (bool) $this->appSettings->get(
            self::NOTIFY_ON_SUGGEST_KEY,
            config('mediamanager.decision_agent.notify_on_suggest', true),
        );
    }

    public function setNotifyOnSuggest(bool $notify): void
    {
        $this->appSettings->set(self::NOTIFY_ON_SUGGEST_KEY, $notify);
    }

    public function notifyOnAct(): bool
    {
        return (bool) $this->appSettings->get(
            self::NOTIFY_ON_ACT_KEY,
            config('mediamanager.decision_agent.notify_on_act', true),
        );
    }

    public function setNotifyOnAct(bool $notify): void
    {
        $this->appSettings->set(self::NOTIFY_ON_ACT_KEY, $notify);
    }

    /**
     * Upper bound on how many ActionRequests a single decision may spawn,
     * bounding the blast radius of one webhook event.
     */
    public function maxActionsPerRun(): int
    {
        $value = (int) $this->appSettings->get(
            self::MAX_ACTIONS_KEY,
            config('mediamanager.decision_agent.max_actions_per_run', 3),
        );

        return max(1, $value);
    }

    public function setMaxActionsPerRun(int $max): void
    {
        $this->appSettings->set(self::MAX_ACTIONS_KEY, max(1, $max));
    }
}
