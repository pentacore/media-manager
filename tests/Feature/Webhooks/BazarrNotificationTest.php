<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseStatus;
use App\Jobs\ReconcileBazarrConnection;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('Bazarr notification accepts header and query token authentication', function (bool $queryToken): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'bazarr-notification-secret',
    ]);
    Queue::fake([ReconcileBazarrConnection::class]);
    $url = '/webhooks/bazarr/'.$connection->id.($queryToken ? '?token=bazarr-notification-secret' : '');
    $headers = $queryToken ? [] : ['X-Webhook-Token' => 'bazarr-notification-secret'];

    $this->postJson($url, ['eventType' => 'Test'], $headers)
        ->assertOk()
        ->assertJson(['status' => 'received']);
})->with([false, true]);

test('Bazarr notification rejects missing invalid inactive and wrong-type connections', function (array $attributes, string $token, int $status): void {
    $connection = ServiceConnection::factory()->create([
        'webhook_token' => 'correct-secret',
        ...$attributes,
    ]);

    $this->postJson(
        '/webhooks/bazarr/'.$connection->id,
        ['eventType' => 'Test'],
        $token === '' ? [] : ['X-Webhook-Token' => $token],
    )->assertStatus($status);
})->with([
    'missing token' => [['type' => 'bazarr', 'is_active' => true], '', 401],
    'wrong token' => [['type' => 'bazarr', 'is_active' => true], 'incorrect', 401],
    'inactive Bazarr' => [['type' => 'bazarr', 'is_active' => false], 'correct-secret', 404],
    'wrong service type' => [['type' => 'sonarr', 'is_active' => true], 'correct-secret', 404],
]);

test('Bazarr notification enforces JSON validation and a 64 kilobyte body limit', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    $headers = ['X-Webhook-Token' => 'secret'];

    $this->postJson('/webhooks/bazarr/'.$connection->id, ['sonarrEpisodeId' => 701], $headers)
        ->assertUnprocessable();
    $this->postJson('/webhooks/bazarr/'.$connection->id, [
        'eventType' => 'Test',
        'message' => str_repeat('x', 65_536),
    ], $headers)->assertStatus(413);
});

test('Bazarr notification is rate limited', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    Queue::fake([ReconcileBazarrConnection::class]);
    $headers = ['X-Webhook-Token' => 'secret'];

    foreach (range(1, 60) as $requestNumber) {
        $this->postJson('/webhooks/bazarr/'.$connection->id, [
            'eventType' => 'ProviderStatus'.$requestNumber,
        ], $headers)->assertOk();
    }

    $this->postJson('/webhooks/bazarr/'.$connection->id, [
        'eventType' => 'ProviderStatus61',
    ], $headers)->assertTooManyRequests();
});

test('duplicate notifications store one sanitized hint and dispatch targeted reconciliation', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $connection->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
    ]);
    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);
    $payload = [
        'eventType' => 'SubtitleDownloaded',
        'sonarrSeriesId' => 101,
        'sonarrEpisodeId' => 701,
        'path' => '/private/anime/Frieren.mkv',
        'message' => 'Downloaded private subtitle text',
    ];
    $headers = ['X-Webhook-Token' => 'secret'];

    $this->postJson('/webhooks/bazarr/'.$connection->id, $payload, $headers)->assertOk();
    $this->postJson('/webhooks/bazarr/'.$connection->id, $payload, $headers)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->firstOrFail()->payload)->toBe([
            'media_type' => 'episode',
            'media_id' => 701,
            'series_id' => 101,
        ])
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
    expect(json_encode(WebhookEvent::query()->firstOrFail()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('/private/')
        ->not->toContain('subtitle text');

    Queue::assertPushed(ReconcileSubtitleCase::class, 1);
    Queue::assertNotPushed(ReconcileBazarrConnection::class);
});

test('Bazarr notification accepts the payload shape Apprise actually posts', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);

    $this->postJson('/webhooks/bazarr/'.$connection->id, [
        'version' => '1.0',
        'title' => 'Bazarr',
        'type' => 'info',
        'message' => 'Downloaded eng subtitles for /private/anime/Frieren.mkv',
    ], ['X-Webhook-Token' => 'secret'])->assertOk();

    $webhookEvent = WebhookEvent::query()->firstOrFail();

    expect($webhookEvent->event_type)->toBe('info')
        ->and($webhookEvent->payload)->toBe([]);
    expect(json_encode($webhookEvent->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('/private/')
        ->not->toContain('Frieren');

    Queue::assertPushed(ReconcileBazarrConnection::class, 1);
    Queue::assertNotPushed(ReconcileSubtitleCase::class);
});

test('a notification without a message or event type is still rejected', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);

    $this->postJson(
        '/webhooks/bazarr/'.$connection->id,
        ['version' => '1.0'],
        ['X-Webhook-Token' => 'secret'],
    )->assertUnprocessable();
});

test('a notification that fails to persist releases the dedup marker so a retry is accepted', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    Queue::fake([ReconcileBazarrConnection::class]);
    $payload = ['eventType' => 'ProviderStatus'];
    $headers = ['X-Webhook-Token' => 'secret'];

    Event::listen('eloquent.creating: '.WebhookEvent::class, function (): never {
        throw new RuntimeException('The hint store is unavailable.');
    });

    $this->postJson('/webhooks/bazarr/'.$connection->id, $payload, $headers)
        ->assertStatus(500);

    expect(WebhookEvent::query()->count())->toBe(0);

    Event::forget('eloquent.creating: '.WebhookEvent::class);

    $this->postJson('/webhooks/bazarr/'.$connection->id, $payload, $headers)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
});

test('distinct notifications for the same target are both processed', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $connection->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
    ]);
    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);
    $headers = ['X-Webhook-Token' => 'secret'];

    foreach (['Downloaded eng subtitles', 'Downloaded swe subtitles'] as $message) {
        $this->postJson('/webhooks/bazarr/'.$connection->id, [
            'eventType' => 'SubtitleDownloaded',
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'message' => $message,
        ], $headers)->assertOk();
    }

    expect(WebhookEvent::query()->count())->toBe(2);

    Queue::assertPushed(ReconcileSubtitleCase::class, 2);
});

test('a unique case outside the most recent hundred still receives targeted reconciliation', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    $targetCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $connection->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
    ]);

    foreach (range(1, 120) as $offset) {
        SubtitleCase::factory()->create([
            'bazarr_connection_id' => $connection->id,
            'status' => SubtitleCaseStatus::Observing,
            'media_type' => 'episode',
            'target_ids' => ['series_id' => 909, 'episode_id' => 900_000 + $offset],
        ]);
    }

    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);

    $this->postJson('/webhooks/bazarr/'.$connection->id, [
        'eventType' => 'SubtitleDownloaded',
        'sonarrEpisodeId' => 701,
    ], ['X-Webhook-Token' => 'secret'])->assertOk();

    Queue::assertPushed(
        ReconcileSubtitleCase::class,
        fn (ReconcileSubtitleCase $job): bool => $job->subtitleCaseId === $targetCase->id,
    );
    Queue::assertNotPushed(ReconcileBazarrConnection::class);
});

test('a shared-file case is matched through its episode id list', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    $targetCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $connection->id,
        'status' => SubtitleCaseStatus::Observing,
        'media_type' => 'episode',
        'target_ids' => [
            'series_id' => 101,
            'episode_id' => 700,
            'episode_ids' => [700, 701, 702],
            'episode_file_id' => 501,
        ],
    ]);
    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);

    $this->postJson('/webhooks/bazarr/'.$connection->id, [
        'eventType' => 'SubtitleDownloaded',
        'sonarrEpisodeId' => 702,
    ], ['X-Webhook-Token' => 'secret'])->assertOk();

    Queue::assertPushed(
        ReconcileSubtitleCase::class,
        fn (ReconcileSubtitleCase $job): bool => $job->subtitleCaseId === $targetCase->id,
    );
    Queue::assertNotPushed(ReconcileBazarrConnection::class);
});

test('unidentifiable notifications dispatch unique connection reconciliation', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'webhook_token' => 'secret',
    ]);
    Queue::fake([ReconcileSubtitleCase::class, ReconcileBazarrConnection::class]);

    $this->postJson(
        '/webhooks/bazarr/'.$connection->id,
        ['eventType' => 'ProviderStatus'],
        ['X-Webhook-Token' => 'secret'],
    )->assertOk();

    Queue::assertPushed(ReconcileBazarrConnection::class, 1);
    Queue::assertNotPushed(ReconcileSubtitleCase::class);
});
