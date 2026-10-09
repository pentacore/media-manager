<?php

declare(strict_types=1);

use App\Enums\PushChannelType;
use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\ActivityLog;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Settings\AppSettings;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

/**
 * @return Collection<int, ActivityLog>
 */
function settingsAuditRows(string $group): Collection
{
    return ActivityLog::query()
        ->where('action', 'settings.updated')
        ->where('subject_type', $group)
        ->orderBy('id')
        ->get();
}

test('a decision agent save records its changed keys once and a repeat save records nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $payload = [
        'enabled' => true,
        'event_allowlist' => ['sonarr:ManualInteractionRequired'],
        'allow_manual_import' => true,
        'notify_on_suggest' => false,
        'notify_on_act' => true,
        'max_actions_per_run' => 5,
    ];

    $this->actingAs($admin)->put(route('admin.decision-agent.update'), $payload)->assertRedirect(route('admin.decision-agent.index'));
    $this->actingAs($admin)->put(route('admin.decision-agent.update'), $payload)->assertRedirect(route('admin.decision-agent.index'));

    $rows = settingsAuditRows('decision_agent');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->isAudit())->toBeTrue()
        ->and($rows->first()->user_id)->toBe($admin->id)
        ->and($rows->first()->description)->toBe('Updated Decision agent settings.')
        ->and($rows->first()->metadata['changes']['decision_agent.enabled'])->toBe(['from' => null, 'to' => true])
        ->and($rows->first()->metadata['changes']['decision_agent.max_actions_per_run'])->toBe(['from' => null, 'to' => 5]);
});

test('an AI settings save is audited without the budget notification bookkeeping key', function (): void {
    resolve(AppSettings::class)->set('ai.budget.soft_notified_at', '2026-09-01T00:00:00Z');

    $this->actingAs(User::factory()->admin()->create())->put(route('admin.ai-settings.update'), [
        'mode' => 'executive',
        'soft_budget_usd' => 25,
    ])->assertRedirect(route('admin.ai-settings.index'));

    $changes = settingsAuditRows('ai')->sole()->metadata['changes'];

    expect($changes['ai.mode'])->toBe(['from' => null, 'to' => 'executive'])
        ->and($changes['ai.budget.soft_monthly_usd']['to'])->toEqual(25)
        ->and($changes)->not->toHaveKey('ai.budget.soft_notified_at')
        ->and($changes)->not->toHaveKey('ai.media_replacement');
});

test('an AI settings save audits a setting only written after the brief-documented audit call site', function (): void {
    // updateOpenRouterSettings()/embeddings writes run after
    // updateClassificationSettings() inside update(); the audit must still be
    // recorded after every one of them so this change lands in the diff
    // (controller ruling A).
    config()->set('ai.providers.openrouter.key', 'sk-or-test');

    $this->actingAs(User::factory()->admin()->create())->put(route('admin.ai-settings.update'), [
        'mode' => 'executive',
        'soft_budget_usd' => 25,
        'openrouter_order' => 'anthropic',
        'embeddings_provider' => 'openrouter',
        'embeddings_model' => 'text-embedding-3-small',
    ])->assertRedirect(route('admin.ai-settings.index'));

    $changes = settingsAuditRows('ai')->sole()->metadata['changes'];

    expect($changes['ai.openrouter.order'])->toBe(['from' => null, 'to' => ['anthropic']])
        ->and($changes['ai.embeddings.provider'])->toBe(['from' => null, 'to' => 'openrouter'])
        ->and($changes['ai.embeddings.model'])->toBe(['from' => null, 'to' => 'text-embedding-3-small']);
});

test('an AI settings save never leaks a provider API key into the audit metadata', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-v1-super-secret-audit-key');

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('admin.ai-settings.update'), [
        'mode' => 'executive',
        'soft_budget_usd' => 25,
    ]);

    $response->assertRedirect(route('admin.ai-settings.index'));

    $activityLog = settingsAuditRows('ai')->sole();

    expect(json_encode($activityLog->metadata))->not->toContain('sk-or-v1-super-secret-audit-key');
});

test('an AI Models save records its changes once and a repeat save records nothing', function (): void {
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    $admin = User::factory()->admin()->create();
    $blank = ['tiers' => [['provider' => null, 'model' => null, 'reasoning' => null, 'min_pool_percent' => null, 'min_pool_tokens' => null]]];
    $payload = [
        'tasks' => [
            'chat' => ['tiers' => [['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'medium', 'min_pool_percent' => null, 'min_pool_tokens' => null]]],
            'title' => $blank,
            'decision' => $blank,
            'file_inspector' => $blank,
            'stuck_download_investigator' => $blank,
            'price_updater' => $blank,
        ],
        'failover' => ['provider' => null, 'model' => null],
        'event_overrides' => [],
    ];

    $this->actingAs($admin)->put(route('admin.ai-models.update'), $payload)->assertRedirect(route('admin.ai-models.index'));
    $this->actingAs($admin)->put(route('admin.ai-models.update'), $payload)->assertRedirect(route('admin.ai-models.index'));

    $rows = settingsAuditRows('ai_models');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->isAudit())->toBeTrue()
        ->and($rows->first()->user_id)->toBe($admin->id)
        ->and($rows->first()->metadata['changes']['chat:default#0'])->toBe(['from' => null, 'to' => 'openai/gpt-5.6-luna · medium'])
        ->and(settingsAuditRows('ai'))->toHaveCount(0);
});

test('webhook capture and Bazarr automation saves are audited with their values', function (): void {
    $admin = User::factory()->admin()->create();
    $automation = resolve(BazarrAutomationSettings::class)->configuration();
    $automation['enabled'] = true;

    $this->actingAs($admin)->put(route('admin.webhook-log.update-settings'), ['capture_enabled' => false])->assertRedirect();
    $this->actingAs($admin)->put(route('bazarr.admin.automation.update'), ['automation' => $automation])->assertRedirect();

    expect(settingsAuditRows('webhooks')->sole()->metadata['changes'])->toBe(['webhooks.capture_enabled' => ['from' => null, 'to' => false]])
        ->and(settingsAuditRows('bazarr_automation')->sole()->metadata['changes']['bazarr.automation.enabled'])->toBe(['from' => null, 'to' => true])
        // The existing value-free activity row is kept alongside the audit row.
        ->and(ActivityLog::query()->where('action', 'bazarr.automation.updated')->count())->toBe(1);
});

test('notification destination create, update and delete are audited with the webhook url masked', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.notification-destinations.store'), [
        'channel' => 'discord',
        'label' => 'Ops channel',
        'is_enabled' => '1',
        'min_severity' => 'warning',
        'config' => ['url' => 'https://discord.com/api/webhooks/1/abc'],
    ])->assertSessionHasNoErrors();

    $notificationDestination = NotificationDestination::query()->sole();

    $this->actingAs($admin)->put(route('admin.notification-destinations.update', $notificationDestination), [
        'channel' => 'discord',
        'label' => 'Ops alerts',
        'is_enabled' => '1',
        'min_severity' => 'warning',
        'config' => ['url' => ''],
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->delete(route('admin.notification-destinations.destroy', $notificationDestination))->assertRedirect();

    $rows = settingsAuditRows('notification_destinations');

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->description)->toBe('Added notification destination "Ops channel".')
        ->and($rows[0]->metadata['context'])->toBe(['operation' => 'created', 'record_id' => $notificationDestination->id])
        ->and($rows[0]->metadata['changes']['config.url'])->toBe(['changed' => true])
        ->and($rows[1]->metadata['changes'])->toBe(['label' => ['from' => 'Ops channel', 'to' => 'Ops alerts']])
        ->and($rows[2]->description)->toBe('Removed notification destination "Ops alerts".')
        ->and($rows[2]->metadata['context']['operation'])->toBe('deleted')
        ->and($rows->toJson())->not->toContain('webhooks/1/abc');
});

test('a notification destination audit record never leaks the webhook url when recorded against the model subject', function (): void {
    // Belt-and-braces for controller ruling B: AuditLogger::SUBJECT_SECRET_FIELDS
    // must also mask config.url when the subject is the model itself (its
    // morph class), not just the settings-group string used by the
    // controller today.
    $admin = User::factory()->admin()->create();
    $notificationDestination = NotificationDestination::factory()->create([
        'channel' => PushChannelType::Discord,
        'config' => ['url' => 'https://discord.com/api/webhooks/999/direct-secret'],
    ]);

    $this->actingAs($admin);

    $activityLog = resolve(AuditLogger::class)->record(
        'notification_destination.tested',
        $notificationDestination,
        'Test row.',
        ['config.url' => ['from' => null, 'to' => $notificationDestination->config['url']]],
    );

    expect(json_encode($activityLog->metadata))->not->toContain('webhooks/999/direct-secret')
        ->and($activityLog->metadata['changes']['config.url'])->toBe(['changed' => true]);
});

test('adding a free pool and removing a pool and a price are audited with what changed', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->unified(500_000)->create(['name' => 'Gemini free tier']);
    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-audit-test']);
    $price->rateLimits()->create(['metric' => RateLimitMetric::Requests, 'period' => RateLimitPeriod::Minute, 'limit_value' => 60]);

    $this->actingAs($admin)->post(route('admin.ai-free-usage-pools.store'), [
        'name' => 'Groq free',
        'period' => 'daily',
        'unified' => true,
        'free_total_tokens' => 1000,
        'overflow_behavior' => 'fit_or_paid',
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->delete(route('admin.ai-free-usage-pools.destroy', $pool))->assertRedirect();
    $this->actingAs($admin)->delete(route('admin.ai-prices.destroy', $price))->assertRedirect();

    $poolRows = settingsAuditRows('ai_free_usage_pools');
    $activityLog = settingsAuditRows('ai_model_prices')->sole();

    expect($poolRows)->toHaveCount(2)
        ->and($poolRows[0]->metadata['context']['operation'])->toBe('created')
        ->and($poolRows[0]->metadata['changes']['name'])->toBe(['from' => null, 'to' => 'Groq free'])
        ->and($poolRows[1]->metadata['context'])->toBe(['operation' => 'deleted', 'record_id' => $pool->id])
        // `tokens` is not the secret `token` segment — the numbers stay readable.
        ->and($poolRows[1]->metadata['changes']['free_total_tokens'])->toBe(['from' => 500_000, 'to' => null])
        ->and($activityLog->metadata['changes']['rate_limits'])->toBe([
            'from' => [['metric' => 'requests', 'period' => 'minute', 'limit_value' => 60]],
            'to' => null,
        ]);
});

test('adding and editing an AI model price are audited with what changed', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.ai-prices.store'), [
        'provider' => 'openai',
        'model' => 'gpt-audit-create',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ])->assertSessionHasNoErrors();

    $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-audit-create')->sole();

    $this->actingAs($admin)->put(route('admin.ai-prices.update', $aiModelPrice), [
        'input_per_mtok' => 2.5,
        'output_per_mtok' => 5,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ])->assertSessionHasNoErrors();

    $rows = settingsAuditRows('ai_model_prices');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->metadata['context'])->toBe(['operation' => 'created', 'record_id' => $aiModelPrice->id])
        ->and($rows[0]->metadata['changes']['model'])->toBe(['from' => null, 'to' => 'gpt-audit-create'])
        ->and($rows[1]->metadata['context'])->toBe(['operation' => 'updated', 'record_id' => $aiModelPrice->id])
        ->and($rows[1]->metadata['changes'])->toHaveKey('input_per_mtok')
        ->and($rows[1]->metadata['changes'])->not->toHaveKey('model');
});

test('a bulk price edit audits each selected row it changed, like the single edit', function (): void {
    $admin = User::factory()->admin()->create();
    $changed = AiModelPrice::factory()->count(2)->create(['is_price_locked' => false]);
    $alreadyLocked = AiModelPrice::factory()->create(['is_price_locked' => true]);
    $untouched = AiModelPrice::factory()->create(['is_price_locked' => false]);

    $this->actingAs($admin)->put(route('admin.ai-prices.bulk-update'), [
        'ids' => [...$changed->pluck('id')->all(), $alreadyLocked->id],
        'automatic_updates_enabled' => false,
    ])->assertRedirect(route('admin.ai-prices.index'));

    $rows = settingsAuditRows('ai_model_prices');

    expect($rows)->toHaveCount(2)
        ->and($rows->map(fn (ActivityLog $activityLog): array => $activityLog->metadata['context'])->all())->toBe([
            ['operation' => 'updated', 'record_id' => $changed[0]->id, 'bulk' => true],
            ['operation' => 'updated', 'record_id' => $changed[1]->id, 'bulk' => true],
        ])
        ->and($rows[0]->metadata['changes'])->toBe(['is_price_locked' => ['from' => false, 'to' => true]])
        ->and($rows[0]->description)->toBe(sprintf('Updated the AI model price for %s/%s.', $changed[0]->provider, $changed[0]->model))
        ->and($untouched->fresh()->is_price_locked)->toBeFalse();
});

test('a bulk price delete audits each removed row with what it held', function (): void {
    $admin = User::factory()->admin()->create();
    $selected = AiModelPrice::factory()->count(2)->create();
    $selected[0]->rateLimits()->create(['metric' => RateLimitMetric::Requests, 'period' => RateLimitPeriod::Minute, 'limit_value' => 60]);

    $this->actingAs($admin)
        ->delete(route('admin.ai-prices.bulk-destroy'), ['ids' => $selected->pluck('id')->all()])
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', '2 model prices removed.');

    $rows = settingsAuditRows('ai_model_prices');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->metadata['context'])->toBe(['operation' => 'deleted', 'record_id' => $selected[0]->id, 'bulk' => true])
        ->and($rows[0]->metadata['changes']['rate_limits'])->toBe([
            'from' => [['metric' => 'requests', 'period' => 'minute', 'limit_value' => 60]],
            'to' => null,
        ])
        ->and($rows[1]->description)->toBe(sprintf('Removed the AI model price for %s/%s.', $selected[1]->provider, $selected[1]->model));
});
