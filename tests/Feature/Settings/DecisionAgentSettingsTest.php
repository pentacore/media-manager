<?php

declare(strict_types=1);

use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;

test('defaults are off and opt-in', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    expect($decisionAgentSettings->enabled())->toBeFalse();
    expect($decisionAgentSettings->eventAllowlist())->toBe([]);
    expect($decisionAgentSettings->allowManualImport())->toBeFalse();
    expect($decisionAgentSettings->maxActionsPerRun())->toBe(3);
});

test('model falls back to the chat model when unset', function (): void {
    resolve(AiSettings::class)->setModel('gpt-chat-model');

    expect(resolve(DecisionAgentSettings::class)->model())->toBe('gpt-chat-model');
});

test('explicit model overrides the chat model', function (): void {
    resolve(AiSettings::class)->setModel('gpt-chat-model');
    resolve(DecisionAgentSettings::class)->setModel('gpt-cheap-triage');

    expect(resolve(DecisionAgentSettings::class)->model())->toBe('gpt-cheap-triage');
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
