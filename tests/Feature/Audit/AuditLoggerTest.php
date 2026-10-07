<?php

declare(strict_types=1);

use App\Enums\ActivityLogCategory;
use App\Enums\SettingsGroup;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SettingsSnapshot;
use App\Settings\AppSettings;

test('record writes an audit row for the acting user with its subject', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $connection = ServiceConnection::factory()->sonarr()->create();

    $activityLog = resolve(AuditLogger::class)->record('connection.updated', $connection, 'Updated Sonarr connection "Main".', [
        'name' => ['from' => 'Sonarr', 'to' => 'Main'],
    ], ['source' => 'admin page']);

    expect($activityLog->fresh()->category)->toBe(ActivityLogCategory::Audit)
        ->and($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->service_connection_id)->toBe($connection->id)
        ->and($activityLog->subject_type)->toBe(ServiceConnection::class)
        ->and($activityLog->subject_id)->toBe($connection->id)
        ->and($activityLog->metadata)->toBe([
            'changes' => ['name' => ['from' => 'Sonarr', 'to' => 'Main']],
            'context' => ['source' => 'admin page'],
            'actor' => ['id' => $admin->id, 'name' => $admin->name],
        ]);
});

test('a deleted connection subject keeps its id but no foreign key', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $connection = ServiceConnection::factory()->sonarr()->create();
    $connection->delete();

    $activityLog = resolve(AuditLogger::class)->record('connection.deleted', $connection, 'Deleted Sonarr connection.');

    expect($activityLog->service_connection_id)->toBeNull()
        ->and($activityLog->subject_id)->toBe($connection->id)
        ->and($activityLog->metadata)->toBe(['actor' => ['id' => $admin->id, 'name' => $admin->name]]);
});

test('a row written without a signed-in user records no actor', function (): void {
    $activityLog = resolve(AuditLogger::class)->record('queue.removed', null, 'Removed a queue item.');

    expect($activityLog->user_id)->toBeNull()
        ->and($activityLog->metadata)->toBeNull();
});

test('secret-named fields are recorded as changed only', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $activityLog = resolve(AuditLogger::class)->record('connection.updated', null, 'Updated a connection.', [
        'api_key' => ['from' => 'old-api-key', 'to' => 'new-api-key'],
        'webhook_token' => ['from' => 'old-token-value', 'to' => 'new-token-value'],
        'settings.client_secret' => ['from' => null, 'to' => 'oauth-secret'],
        'discord_webhook_url' => ['from' => null, 'to' => 'https://discord.com/api/webhooks/1/abc'],
        'free_total_tokens' => ['from' => 1000, 'to' => 2000],
    ]);

    expect($activityLog->metadata['changes'])->toBe([
        'api_key' => ['changed' => true],
        'webhook_token' => ['changed' => true],
        'settings.client_secret' => ['changed' => true],
        'discord_webhook_url' => ['changed' => true],
        'free_total_tokens' => ['from' => 1000, 'to' => 2000],
    ]);

    expect(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('new-api-key')
        ->not->toContain('new-token-value')
        ->not->toContain('oauth-secret')
        ->not->toContain('webhooks/1/abc');
});

test('urls with credentials are masked and other urls lose their query strings', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $activityLog = resolve(AuditLogger::class)->record('connection.updated', null, 'Updated a connection.', [
        'url' => ['from' => 'http://sab.local:8080', 'to' => 'http://admin:hunter2@sab.local:8080'],
        'external_url' => ['from' => null, 'to' => 'https://sab.example.com/sabnzbd?apikey=leaky'],
    ]);

    expect($activityLog->metadata['changes'])->toBe([
        'url' => ['changed' => true],
        'external_url' => ['from' => null, 'to' => 'https://sab.example.com/sabnzbd?[redacted]'],
    ]);
});

test('a subject secret list masks fields the key rule does not know', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $activityLog = resolve(AuditLogger::class)->record('settings.updated', SettingsGroup::NotificationDestinations->value, 'Updated a destination.', [
        'config.url' => ['from' => null, 'to' => 'https://discord.com/api/webhooks/9/zzz'],
        'config.topic' => ['from' => null, 'to' => 'mm-alerts'],
    ]);

    expect($activityLog->metadata['changes'])->toBe([
        'config.url' => ['changed' => true],
        'config.topic' => ['from' => null, 'to' => 'mm-alerts'],
    ]);
});

test('context is scrubbed of secret keys, credential urls and query strings', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $activityLog = resolve(AuditLogger::class)->record('queue.removed', null, 'Removed a queue item.', context: [
        'token' => 'raw-token',
        'nested' => ['password' => 'raw-password', 'queue_id' => 7],
        'link' => 'https://indexer.example/get?apikey=raw-key',
        'mirror' => 'http://user:pw@mirror.example/',
    ]);

    expect($activityLog->metadata['context'])->toBe([
        'token' => AuditChanges::MASKED,
        'nested' => ['password' => AuditChanges::MASKED, 'queue_id' => 7],
        'link' => 'https://indexer.example/get?[redacted]',
        'mirror' => AuditChanges::MASKED,
    ]);
});

test('between flattens associative arrays, compares lists whole and drops unchanged keys', function (): void {
    expect(AuditChanges::between(
        ['name' => 'Main', 'settings' => ['disk' => ['mode' => 'all', 'paths' => ['/a']]], 'tags' => ['x']],
        ['name' => 'Main', 'settings' => ['disk' => ['mode' => 'selected', 'paths' => ['/a']]], 'tags' => ['x', 'y'], 'is_active' => false],
    ))->toBe([
        'is_active' => ['from' => null, 'to' => false],
        'settings.disk.mode' => ['from' => 'all', 'to' => 'selected'],
        'tags' => ['from' => ['x'], 'to' => ['x', 'y']],
    ]);
});

test('settingsUpdated writes one row for a real change and nothing for a no-op save', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $auditLogger = resolve(AuditLogger::class);

    $activityLog = $auditLogger->settingsUpdated(SettingsGroup::Webhooks, [], ['webhooks.capture_enabled' => false]);

    expect($activityLog?->action)->toBe('settings.updated')
        ->and($activityLog?->subject_type)->toBe('webhooks')
        ->and($activityLog?->description)->toBe('Updated Webhook settings.')
        ->and($activityLog?->metadata['changes'])->toBe(['webhooks.capture_enabled' => ['from' => null, 'to' => false]])
        ->and($auditLogger->settingsUpdated(SettingsGroup::Webhooks, ['webhooks.capture_enabled' => false], ['webhooks.capture_enabled' => false]))->toBeNull();
});

test('a settings snapshot captures only its own group and escapes like wildcards', function (): void {
    $appSettings = resolve(AppSettings::class);
    $appSettings->set('decision_agent.enabled', true);
    $appSettings->set('decisionXagent.enabled', true);
    $appSettings->set('ai.chat_timeout', 300);
    $appSettings->set('ai.media_replacement', ['enabled' => true]);
    $appSettings->set('ai.budget.soft_notified_at', '2026-09-01');

    $settingsSnapshot = resolve(SettingsSnapshot::class);

    expect($settingsSnapshot->capture(SettingsGroup::DecisionAgent))->toBe(['decision_agent.enabled' => true])
        ->and($settingsSnapshot->capture(SettingsGroup::Ai))->toBe(['ai.chat_timeout' => 300])
        ->and($settingsSnapshot->capture(SettingsGroup::MediaReplacement))->toBe(['ai.media_replacement' => ['enabled' => true]])
        ->and($settingsSnapshot->capture(SettingsGroup::NotificationDestinations))->toBe([]);
});
