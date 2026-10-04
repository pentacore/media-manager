<?php

declare(strict_types=1);

use App\Enums\ActivityLogCategory;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

/**
 * Make every audit row write fail, as a lost database connection or a full
 * disk would, after the save's earlier writes have gone through.
 */
function settingsAtomicityFailAudits(): void
{
    ActivityLog::creating(static function (ActivityLog $activityLog): void {
        throw_if($activityLog->category === ActivityLogCategory::Audit, RuntimeException::class, 'audit store unavailable');
    });
}

/**
 * @return array<string, mixed>
 */
function settingsAtomicityStoredSettings(): array
{
    return AppSetting::query()->orderBy('key')->get()
        ->mapWithKeys(static fn (AppSetting $appSetting): array => [$appSetting->key => $appSetting->value])
        ->all();
}

test('a settings save whose audit row cannot be written keeps every earlier setting and activity row', function (string $routeName, Closure $payload): void {
    $admin = User::factory()->admin()->create();
    $payload = $payload();
    $settingsBefore = settingsAtomicityStoredSettings();
    $activityRowsBefore = ActivityLog::query()->count();
    settingsAtomicityFailAudits();

    expect(fn () => $this->withoutExceptionHandling()->actingAs($admin)->put(route($routeName), $payload))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(settingsAtomicityStoredSettings())->toBe($settingsBefore)
        ->and(ActivityLog::query()->count())->toBe($activityRowsBefore);
})->with([
    'AI settings' => ['admin.ai-settings.update', fn (): array => [
        'mode' => 'executive',
        'model' => 'gpt-5-mini',
        'title_model' => 'gpt-5.4-nano',
        'advisor_reasoning_level' => 'none',
        'soft_budget_usd' => 25,
    ]],
    'decision agent' => ['admin.decision-agent.update', fn (): array => [
        'enabled' => true,
        'model' => 'gpt-5-mini',
        'event_allowlist' => ['sonarr:ManualInteractionRequired'],
        'allow_manual_import' => true,
        'notify_on_suggest' => false,
        'notify_on_act' => true,
        'max_actions_per_run' => 5,
        'reasoning_level' => 'high',
    ]],
    'media replacement' => ['admin.media-replacement.update', fn (): array => ['media_replacement' => [
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 77,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => ['Japanese'], 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'subtitle_check' => ['enabled' => false, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => 'No special guidance.', 'rules' => []],
            'tv' => ['notes' => 'No special guidance.', 'rules' => []],
            'movie' => ['notes' => 'No special guidance.', 'rules' => []],
        ],
    ]]],
    'Bazarr automation' => ['bazarr.admin.automation.update', function (): array {
        $automation = resolve(BazarrAutomationSettings::class)->configuration();
        $automation['enabled'] = true;

        return ['automation' => $automation];
    }],
    'webhook capture' => ['admin.webhook-log.update-settings', fn (): array => ['capture_enabled' => false]],
]);
