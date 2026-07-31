<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Settings\BazarrAutomationSettings;

test('automation settings have safe defaults and typed accessors', function (): void {
    $bazarrAutomationSettings = resolve(BazarrAutomationSettings::class);

    expect($bazarrAutomationSettings->configuration())->toBe([
        'enabled' => false,
        'reconciliation_interval_minutes' => 15,
        'grace_hours' => ['anime' => 24, 'tv' => 72, 'movie' => 72],
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_cases_per_cycle' => 100,
        'max_probes_per_cycle' => 10,
        'max_advisor_escalations_per_cycle' => 3,
        'advisor_concurrency' => 1,
        'upload_max_kilobytes' => 5120,
        'upload_expiry_hours' => 24,
    ])->and($bazarrAutomationSettings->enabled())->toBeFalse()
        ->and($bazarrAutomationSettings->graceHours('anime'))->toBe(24);
});

test('partial and invalid settings normalize without preserving unknown keys', function (): void {
    $bazarrAutomationSettings = resolve(BazarrAutomationSettings::class);
    $bazarrAutomationSettings->setConfiguration([
        'enabled' => 'yes',
        'reconciliation_interval_minutes' => -20,
        'grace_hours' => ['anime' => 99999, 'unknown' => 4],
        'probe_spacing_hours' => 9999,
        'max_advisor_escalations_per_cycle' => -1,
        'unknown_secret' => 'discard',
    ]);

    expect($bazarrAutomationSettings->configuration())->toMatchArray([
        'enabled' => false,
        'reconciliation_interval_minutes' => 5,
        'grace_hours' => ['anime' => 8760, 'tv' => 72, 'movie' => 72],
        'probe_spacing_hours' => 720,
        'max_advisor_escalations_per_cycle' => 0,
    ])->and($bazarrAutomationSettings->configuration())->not->toHaveKey('unknown_secret')
        ->and(AppSetting::query()->findOrFail('bazarr.automation')->value)->not->toHaveKey('unknown_secret');
});

test('every numeric setting clamps to its documented safe range', function (): void {
    $bazarrAutomationSettings = resolve(BazarrAutomationSettings::class);
    $bazarrAutomationSettings->setConfiguration([
        'empty_probe_threshold' => 50,
        'max_cases_per_cycle' => 0,
        'max_probes_per_cycle' => 500,
        'advisor_concurrency' => 0,
        'upload_max_kilobytes' => 2,
        'upload_expiry_hours' => 999,
    ]);

    expect($bazarrAutomationSettings->configuration())->toMatchArray([
        'empty_probe_threshold' => 10,
        'max_cases_per_cycle' => 1,
        'max_probes_per_cycle' => 100,
        'advisor_concurrency' => 1,
        'upload_max_kilobytes' => 64,
        'upload_expiry_hours' => 168,
    ]);
});
