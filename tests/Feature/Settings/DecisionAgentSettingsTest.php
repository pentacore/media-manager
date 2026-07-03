<?php

declare(strict_types=1);

use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;

test('defaults are off and opt-in', function (): void {
    $settings = resolve(DecisionAgentSettings::class);

    expect($settings->enabled())->toBeFalse();
    expect($settings->eventAllowlist())->toBe([]);
    expect($settings->allowManualImport())->toBeFalse();
    expect($settings->maxActionsPerRun())->toBe(3);
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
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setEventAllowlist(['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired']);

    expect($settings->isEventAllowed('sonarr', 'ManualInteractionRequired'))->toBeTrue();
    expect($settings->isEventAllowed('SONARR', 'ManualInteractionRequired'))->toBeTrue();
    expect($settings->isEventAllowed('sonarr', 'Download'))->toBeFalse();
    expect($settings->isEventAllowed('emby', 'ManualInteractionRequired'))->toBeFalse();
});

test('max actions per run is clamped to at least 1', function (): void {
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setMaxActionsPerRun(0);

    expect($settings->maxActionsPerRun())->toBe(1);
});

test('toggles round-trip', function (): void {
    $settings = resolve(DecisionAgentSettings::class);

    $settings->setEnabled(true);
    $settings->setAllowManualImport(true);
    $settings->setNotifyOnSuggest(false);
    $settings->setNotifyOnAct(false);

    expect($settings->enabled())->toBeTrue();
    expect($settings->allowManualImport())->toBeTrue();
    expect($settings->notifyOnSuggest())->toBeFalse();
    expect($settings->notifyOnAct())->toBeFalse();
});

test('eventCatalog includes Whisparr v2 and v3 events', function (): void {
    $whisparr = DecisionAgentSettings::eventCatalog()['whisparr'] ?? [];

    expect($whisparr)->toContain('MovieAdded', 'MovieDelete', 'MovieFileDelete');
    expect($whisparr)->toContain('SeriesAdd', 'SeriesDelete', 'EpisodeFileDelete');
    expect($whisparr)->toContain('Grab', 'Download', 'Rename', 'ManualInteractionRequired', 'Health', 'HealthRestored', 'ApplicationUpdate');
});
