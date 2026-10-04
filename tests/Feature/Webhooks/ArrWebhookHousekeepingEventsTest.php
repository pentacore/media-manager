<?php

declare(strict_types=1);

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use App\Services\Prowlarr\ProwlarrWebhookHandler;
use App\Services\Radarr\RadarrWebhookHandler;
use App\Services\Sonarr\SonarrWebhookHandler;
use App\Services\Whisparr\WhisparrWebhookHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

/**
 * Handler class, service slug (also the factory state), service label.
 *
 * @return array<string, array{0: class-string, 1: string, 2: string}>
 */
function arrHousekeepingCases(): array
{
    return [
        'sonarr' => [SonarrWebhookHandler::class, 'sonarr', 'Sonarr'],
        'radarr' => [RadarrWebhookHandler::class, 'radarr', 'Radarr'],
        'whisparr' => [WhisparrWebhookHandler::class, 'whisparr', 'Whisparr'],
        'prowlarr' => [ProwlarrWebhookHandler::class, 'prowlarr', 'Prowlarr'],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function arrHousekeepingEvent(string $slug, string $eventType, array $payload): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => ServiceConnection::factory()->{$slug}()->create()->id,
        'event_type' => $eventType,
        'payload' => ['eventType' => $eventType, ...$payload],
    ]);
}

test('a Test event is logged with the instance details', function (string $handlerClass, string $slug, string $label): void {
    $webhookEvent = arrHousekeepingEvent($slug, 'Test', ['instanceName' => 'Main', 'applicationUrl' => 'http://arr.example.test']);

    resolve($handlerClass)->handle($webhookEvent);

    $activityLog = ActivityLog::query()->where('action', sprintf('webhook.%s.test', $slug))->sole();
    expect($activityLog->description)->toBe(sprintf('%s webhook test received.', $label))
        ->and($activityLog->metadata)->toEqual(['instance_name' => 'Main', 'application_url' => 'http://arr.example.test'])
        ->and($activityLog->webhook_event_id)->toBe($webhookEvent->id)
        ->and($activityLog->service_connection_id)->toBe($webhookEvent->service_connection_id);
})->with(arrHousekeepingCases());

test('a Health warning is logged with its own message and notifies the admins', function (string $handlerClass, string $slug): void {
    $admin = User::factory()->admin()->create();
    $webhookEvent = arrHousekeepingEvent($slug, 'Health', [
        'level' => 'warning',
        'message' => 'Indexer unavailable',
        'type' => 'IndexerStatusCheck',
        'wikiUrl' => 'https://wiki.servarr.com/health',
    ]);

    resolve($handlerClass)->handle($webhookEvent);

    $activityLog = ActivityLog::query()->where('action', sprintf('webhook.%s.health', $slug))->sole();
    expect($activityLog->description)->toBe('Indexer unavailable')
        ->and($activityLog->metadata)->toEqual(['level' => 'warning', 'type' => 'IndexerStatusCheck', 'wiki_url' => 'https://wiki.servarr.com/health']);
    Notification::assertSentTo($admin, ServiceWarning::class, fn (ServiceWarning $serviceWarning): bool => $serviceWarning->service === $slug
        && $serviceWarning->title === 'IndexerStatusCheck'
        && $serviceWarning->message === 'Indexer unavailable'
        && $serviceWarning->level === 'warning');
})->with(arrHousekeepingCases());

test("a Health error without a type or message falls back to the service's generic wording", function (string $handlerClass, string $slug, string $label): void {
    $admin = User::factory()->admin()->create();
    $webhookEvent = arrHousekeepingEvent($slug, 'Health', ['level' => 'error']);

    resolve($handlerClass)->handle($webhookEvent);

    expect(ActivityLog::query()->where('action', sprintf('webhook.%s.health', $slug))->sole()->description)->toBe('Unknown health event');
    Notification::assertSentTo($admin, ServiceWarning::class, fn (ServiceWarning $serviceWarning): bool => $serviceWarning->title === sprintf('%s health', $label)
        && $serviceWarning->message === 'Unknown health event'
        && $serviceWarning->level === 'error');
})->with(arrHousekeepingCases());

test('only a live Health warning or error notifies', function (string $handlerClass, string $slug, string $label, string $eventType, array $payload, string $action): void {
    User::factory()->admin()->create();
    $webhookEvent = arrHousekeepingEvent($slug, $eventType, $payload);

    resolve($handlerClass)->handle($webhookEvent);

    expect(ActivityLog::query()->where('action', sprintf('webhook.%s.%s', $slug, $action))->count())->toBe(1);
    Notification::assertNothingSent();
})->with(arrHousekeepingCases())->with([
    'restored, even at error' => ['HealthRestored', ['level' => 'error', 'message' => 'Back'], 'health_restored'],
    'an ok level' => ['Health', ['level' => 'ok', 'message' => 'Fine'], 'health'],
    'no level' => ['Health', ['message' => 'Fine'], 'health'],
    'an info level' => ['Health', ['level' => 'info', 'message' => 'Fyi'], 'health'],
]);

test('an ApplicationUpdate is logged with both versions', function (string $handlerClass, string $slug, string $label, array $payload, string $description, array $metadata): void {
    $webhookEvent = arrHousekeepingEvent($slug, 'ApplicationUpdate', $payload);

    resolve($handlerClass)->handle($webhookEvent);

    $activityLog = ActivityLog::query()->where('action', sprintf('webhook.%s.updated', $slug))->sole();
    expect($activityLog->description)->toBe(sprintf($description, $label))
        ->and($activityLog->metadata)->toEqual($metadata);
})->with(arrHousekeepingCases())->with([
    'with versions' => [['previousVersion' => '4.0.0', 'newVersion' => '4.0.1', 'message' => 'Updated'], '%s updated from 4.0.0 to 4.0.1.', ['previous_version' => '4.0.0', 'new_version' => '4.0.1', 'message' => 'Updated']],
    'without versions' => [[], '%s updated from unknown to unknown.', ['previous_version' => null, 'new_version' => null, 'message' => null]],
]);

test('only Prowlarr re-pings its health and re-fetches its version after these events', function (string $handlerClass, string $slug, int $pings, int $versionFetches): void {
    $webhookEvents = [
        arrHousekeepingEvent($slug, 'Health', ['level' => 'ok', 'message' => 'Fine']),
        arrHousekeepingEvent($slug, 'HealthRestored', ['level' => 'ok', 'message' => 'Back']),
        arrHousekeepingEvent($slug, 'ApplicationUpdate', ['previousVersion' => '1', 'newVersion' => '2']),
    ];
    // Faked after the connections exist: their observer dispatches both jobs on create.
    Bus::fake([PingServiceHealth::class, FetchLatestServiceVersion::class]);

    foreach ($webhookEvents as $webhookEvent) {
        resolve($handlerClass)->handle($webhookEvent);
    }

    Bus::assertDispatchedTimes(PingServiceHealth::class, $pings);
    Bus::assertDispatchedTimes(FetchLatestServiceVersion::class, $versionFetches);
})->with([
    'sonarr' => [SonarrWebhookHandler::class, 'sonarr', 0, 0],
    'radarr' => [RadarrWebhookHandler::class, 'radarr', 0, 0],
    'whisparr' => [WhisparrWebhookHandler::class, 'whisparr', 0, 0],
    'prowlarr' => [ProwlarrWebhookHandler::class, 'prowlarr', 2, 1],
]);
