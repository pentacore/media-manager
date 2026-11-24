<?php

declare(strict_types=1);

use App\Settings\DecisionAgentSettings;

test('defaults are off and opt-in', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    expect($decisionAgentSettings->enabled())->toBeFalse();
    expect($decisionAgentSettings->eventAllowlist())->toBe([]);
    expect($decisionAgentSettings->allowManualImport())->toBeFalse();
    expect($decisionAgentSettings->maxActionsPerRun())->toBe(3);
});

test('event allowlist round-trips and gates by service:event key', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setEventAllowlist(['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired']);

    expect($decisionAgentSettings->isEventAllowed('sonarr', 'ManualInteractionRequired'))->toBeTrue();
    expect($decisionAgentSettings->isEventAllowed('SONARR', 'ManualInteractionRequired'))->toBeTrue();
    expect($decisionAgentSettings->isEventAllowed('sonarr', 'Download'))->toBeFalse();
    expect($decisionAgentSettings->isEventAllowed('emby', 'ManualInteractionRequired'))->toBeFalse();
});

test('max actions per run is clamped to at least 1', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setMaxActionsPerRun(0);

    expect($decisionAgentSettings->maxActionsPerRun())->toBe(1);
});

test('the run time limit falls back to config and is clamped inside the job timeout', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    expect($decisionAgentSettings->runTimeLimit())->toBe(210);

    config()->set('mediamanager.decision_agent.run_time_limit', 9999);

    expect($decisionAgentSettings->runTimeLimit())->toBe(DecisionAgentSettings::maxRunTimeLimit());

    $decisionAgentSettings->setRunTimeLimit(5);

    expect($decisionAgentSettings->runTimeLimit())->toBe(DecisionAgentSettings::MIN_RUN_TIME_LIMIT_SECONDS);

    $decisionAgentSettings->setRunTimeLimit(null);
    config()->set('mediamanager.decision_agent.run_time_limit', 120);

    expect($decisionAgentSettings->runTimeLimit())->toBe(120);
});

test('toggles round-trip', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    $decisionAgentSettings->setEnabled(true);
    $decisionAgentSettings->setAllowManualImport(true);
    $decisionAgentSettings->setNotifyOnSuggest(false);
    $decisionAgentSettings->setNotifyOnAct(false);

    expect($decisionAgentSettings->enabled())->toBeTrue();
    expect($decisionAgentSettings->allowManualImport())->toBeTrue();
    expect($decisionAgentSettings->notifyOnSuggest())->toBeFalse();
    expect($decisionAgentSettings->notifyOnAct())->toBeFalse();
});

test('eventCatalog includes Whisparr v2 and v3 events', function (): void {
    $whisparr = DecisionAgentSettings::eventCatalog()['whisparr'] ?? [];

    expect($whisparr)->toContain('MovieAdded', 'MovieDelete', 'MovieFileDelete');
    expect($whisparr)->toContain('SeriesAdd', 'SeriesDelete', 'EpisodeFileDelete');
    expect($whisparr)->toContain('Grab', 'Download', 'Rename', 'ManualInteractionRequired', 'Health', 'HealthRestored', 'ApplicationUpdate');
});
