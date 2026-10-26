<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;

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

    public const string ALLOWLIST_KEY = 'decision_agent.event_allowlist';

    public const string ALLOW_MANUAL_IMPORT_KEY = 'decision_agent.allow_manual_import';

    public const string NOTIFY_ON_SUGGEST_KEY = 'decision_agent.notify_on_suggest';

    public const string NOTIFY_ON_ACT_KEY = 'decision_agent.notify_on_act';

    public const string MAX_ACTIONS_KEY = 'decision_agent.max_actions_per_run';

    public const string SECOND_OPINION_ENGINE_KEY = 'decision_agent.second_opinion_engine';

    public const string SECOND_OPINION_JEV_THRESHOLD_KEY = 'decision_agent.second_opinion_jev_threshold';

    public const string SECOND_OPINION_EVENT_OVERRIDES_KEY = 'decision_agent.second_opinion_event_overrides';

    /**
     * The Jev threshold when neither the store nor the config holds a
     * usable value.
     */
    public const float DEFAULT_SECOND_OPINION_JEV_THRESHOLD = 0.8;

    public function __construct(private readonly AppSettings $appSettings) {}

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
     * The services the catalog covers.
     *
     * @return list<string>
     */
    public static function eventServices(): array
    {
        return array_keys(self::eventCatalog());
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

    /**
     * Which verifier gives the second opinion on automated proposals. An
     * unknown stored value falls back to the LLM. An event override's engine
     * wins when one is set for $eventKey ("service:EventType").
     */
    public function secondOpinionEngine(?string $eventKey = null): SecondOpinionEngine
    {
        $eventEngine = $this->secondOpinionEventOverride($eventKey)['engine'] ?? null;

        if ($eventEngine !== null) {
            return $eventEngine;
        }

        $value = $this->appSettings->get(
            self::SECOND_OPINION_ENGINE_KEY,
            config('mediamanager.decision_agent.second_opinion_engine', SecondOpinionEngine::Llm->value),
        );

        return SecondOpinionEngine::tryFrom(is_string($value) ? $value : '') ?? SecondOpinionEngine::Llm;
    }

    /**
     * Persist the engine. A null value clears it back to the config default.
     */
    public function setSecondOpinionEngine(?SecondOpinionEngine $secondOpinionEngine): void
    {
        $this->appSettings->set(self::SECOND_OPINION_ENGINE_KEY, $secondOpinionEngine?->value);
    }

    /**
     * Jev probability at or above which the classifier agrees with a
     * proposal, clamped to [0, 1]. An event override's threshold wins when one
     * is set for $eventKey ("service:EventType").
     */
    public function secondOpinionJevThreshold(?string $eventKey = null): float
    {
        $eventThreshold = $this->secondOpinionEventOverride($eventKey)['jev_threshold'] ?? null;

        if ($eventThreshold !== null) {
            return $eventThreshold;
        }

        $value = $this->appSettings->get(
            self::SECOND_OPINION_JEV_THRESHOLD_KEY,
            config('mediamanager.decision_agent.second_opinion_jev_threshold', self::DEFAULT_SECOND_OPINION_JEV_THRESHOLD),
        );

        return is_numeric($value) ? $this->clampThreshold($value) : self::DEFAULT_SECOND_OPINION_JEV_THRESHOLD;
    }

    /**
     * Persist the threshold. A null value clears it back to the config default.
     */
    public function setSecondOpinionJevThreshold(?float $threshold): void
    {
        $this->appSettings->set(self::SECOND_OPINION_JEV_THRESHOLD_KEY, $threshold);
    }

    /**
     * Per-event verifier overrides keyed by "service:EventType". An entry
     * holds only the fields it overrides; null fields inherit the global
     * setting. Malformed stored entries are dropped or normalised.
     *
     * @return array<string, array{mode: SecondOpinionMode|null, engine: SecondOpinionEngine|null, jev_threshold: float|null}>
     */
    public function secondOpinionEventOverrides(): array
    {
        $value = $this->appSettings->get(
            self::SECOND_OPINION_EVENT_OVERRIDES_KEY,
            config('mediamanager.decision_agent.second_opinion_event_overrides', []),
        );

        if (! is_array($value)) {
            return [];
        }

        $overrides = [];
        foreach ($value as $eventKey => $override) {
            if (! is_string($eventKey) || $eventKey === '' || ! is_array($override)) {
                continue;
            }

            $mode = $override['mode'] ?? null;
            $engine = $override['engine'] ?? null;
            $threshold = $override['jev_threshold'] ?? null;

            $entry = [
                'mode' => is_string($mode) ? SecondOpinionMode::tryFrom($mode) : null,
                'engine' => is_string($engine) ? SecondOpinionEngine::tryFrom($engine) : null,
                'jev_threshold' => is_numeric($threshold) ? $this->clampThreshold($threshold) : null,
            ];

            if (! $entry['mode'] instanceof SecondOpinionMode && ! $entry['engine'] instanceof SecondOpinionEngine && $entry['jev_threshold'] === null) {
                continue;
            }

            $overrides[$eventKey] = $entry;
        }

        ksort($overrides);

        return $overrides;
    }

    /**
     * Persist the per-event overrides. Entries that inherit everything are
     * dropped; an empty map clears the stored key back to the config default.
     *
     * @param  array<string, array{mode: SecondOpinionMode|null, engine: SecondOpinionEngine|null, jev_threshold: float|null}>  $overrides
     */
    public function setSecondOpinionEventOverrides(array $overrides): void
    {
        $stored = [];
        foreach ($overrides as $eventKey => $override) {
            $entry = [
                'mode' => $override['mode']?->value,
                'engine' => $override['engine']?->value,
                'jev_threshold' => $override['jev_threshold'] === null ? null : $this->clampThreshold($override['jev_threshold']),
            ];

            if ($entry['mode'] === null && $entry['engine'] === null && $entry['jev_threshold'] === null) {
                continue;
            }

            $stored[$eventKey] = $entry;
        }

        ksort($stored);

        $this->appSettings->set(self::SECOND_OPINION_EVENT_OVERRIDES_KEY, $stored === [] ? null : $stored);
    }

    public function secondOpinionModeOverride(?string $eventKey): ?SecondOpinionMode
    {
        return $this->secondOpinionEventOverride($eventKey)['mode'] ?? null;
    }

    public function hasSecondOpinionEventOverride(?string $eventKey): bool
    {
        return $this->secondOpinionEventOverride($eventKey) !== null;
    }

    /**
     * The stored override entry for $eventKey ("service:EventType"), or null
     * when the event has none.
     *
     * @return array{mode: SecondOpinionMode|null, engine: SecondOpinionEngine|null, jev_threshold: float|null}|null
     */
    public function secondOpinionEventOverride(?string $eventKey): ?array
    {
        if ($eventKey === null) {
            return null;
        }

        return $this->secondOpinionEventOverrides()[$eventKey] ?? null;
    }

    private function clampThreshold(int|float|string $threshold): float
    {
        return max(0.0, min(1.0, (float) $threshold));
    }
}
