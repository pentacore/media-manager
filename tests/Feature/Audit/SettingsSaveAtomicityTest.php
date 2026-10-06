<?php

declare(strict_types=1);

use App\Enums\ActivityLogCategory;
use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\ActivityLog;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AppSetting;
use App\Models\NotificationDestination;
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

test('a free usage pool create, update or delete whose audit row cannot be written changes nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->unified(500_000)->create(['name' => 'Gemini free tier']);
    $payload = ['name' => 'Groq free', 'period' => 'daily', 'unified' => true, 'free_total_tokens' => 1000, 'overflow_behavior' => 'fit_or_paid'];
    settingsAtomicityFailAudits();
    $this->withoutExceptionHandling()->actingAs($admin);

    expect(fn () => $this->post(route('admin.ai-free-usage-pools.store'), $payload))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->put(route('admin.ai-free-usage-pools.update', $pool), $payload))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->delete(route('admin.ai-free-usage-pools.destroy', $pool)))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(AiFreeUsagePool::query()->pluck('name')->all())->toBe(['Gemini free tier']);
});

test('a model price create, update or delete whose audit row cannot be written changes nothing, rate limits included', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-atomic', 'input_per_mtok' => 1.25]);
    $price->rateLimits()->create(['metric' => RateLimitMetric::Requests, 'period' => RateLimitPeriod::Minute, 'limit_value' => 60]);
    settingsAtomicityFailAudits();
    $this->withoutExceptionHandling()->actingAs($admin);

    expect(fn () => $this->post(route('admin.ai-prices.store'), [
        'provider' => 'openai', 'model' => 'gpt-atomic-new', 'input_per_mtok' => 1, 'output_per_mtok' => 5,
        'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0,
    ]))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->put(route('admin.ai-prices.update', $price), [
            'input_per_mtok' => 2.5, 'output_per_mtok' => 5, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0,
        ]))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->delete(route('admin.ai-prices.destroy', $price)))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(AiModelPrice::query()->pluck('model')->all())->toBe(['gpt-atomic'])
        ->and((float) $price->fresh()->input_per_mtok)->toBe(1.25)
        ->and($price->fresh()->rateLimits()->count())->toBe(1);
});

test('a notification destination create, update or delete whose audit row cannot be written changes nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $notificationDestination = NotificationDestination::factory()->create(['label' => 'Ops channel']);
    $payload = ['channel' => 'discord', 'label' => 'Ops alerts', 'is_enabled' => '1', 'min_severity' => 'warning', 'config' => ['url' => 'https://discord.com/api/webhooks/1/abc']];
    settingsAtomicityFailAudits();
    $this->withoutExceptionHandling()->actingAs($admin);

    expect(fn () => $this->post(route('admin.notification-destinations.store'), $payload))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->put(route('admin.notification-destinations.update', $notificationDestination), $payload))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->delete(route('admin.notification-destinations.destroy', $notificationDestination)))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(NotificationDestination::query()->pluck('label')->all())->toBe(['Ops channel']);
});

test('a bulk price edit or delete whose audit row cannot be written changes nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $prices = AiModelPrice::factory()->count(2)->create(['is_price_locked' => false]);
    $prices[0]->rateLimits()->create(['metric' => RateLimitMetric::Requests, 'period' => RateLimitPeriod::Minute, 'limit_value' => 60]);
    settingsAtomicityFailAudits();
    $this->withoutExceptionHandling()->actingAs($admin);

    expect(fn () => $this->put(route('admin.ai-prices.bulk-update'), [
        'ids' => $prices->pluck('id')->all(),
        'automatic_updates_enabled' => false,
        'rate_limits' => [],
    ]))->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(fn () => $this->delete(route('admin.ai-prices.bulk-destroy'), ['ids' => $prices->pluck('id')->all()]))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect($prices->map(fn (AiModelPrice $aiModelPrice): bool => $aiModelPrice->fresh()->is_price_locked)->all())->toBe([false, false])
        ->and($prices[0]->fresh()->rateLimits()->count())->toBe(1);
});
