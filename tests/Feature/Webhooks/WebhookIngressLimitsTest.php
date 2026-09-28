<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->connection = ServiceConnection::factory()->sonarr()->create(['webhook_token' => 'secret']);
});

function webhookIngressUrl(ServiceConnection $connection, array $query = []): string
{
    return route('webhooks.handle', ['service' => 'sonarr', 'connection' => $connection->id, ...$query]);
}

test('an oversized payload is rejected with 413 before the token is checked', function (): void {
    config()->set('mediamanager.webhooks.max_payload_kb', 1);

    $this->postJson(webhookIngressUrl($this->connection), ['eventType' => 'Test', 'blob' => str_repeat('a', 2048)], ['X-Webhook-Token' => 'wrong'])
        ->assertStatus(413);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('an oversized payload with a valid token is rejected and not stored', function (): void {
    config()->set('mediamanager.webhooks.max_payload_kb', 1);

    $this->postJson(webhookIngressUrl($this->connection), ['eventType' => 'Test', 'blob' => str_repeat('a', 2048)], ['X-Webhook-Token' => 'secret'])
        ->assertStatus(413);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('a payload under the limit is accepted', function (): void {
    config()->set('mediamanager.webhooks.max_payload_kb', 1);

    $this->postJson(webhookIngressUrl($this->connection), ['eventType' => 'Test', 'blob' => str_repeat('a', 200)], ['X-Webhook-Token' => 'secret'])
        ->assertOk();
});

test('webhook deliveries are rate limited per client ip', function (): void {
    config()->set('mediamanager.webhooks.rate_limit_per_minute', 2);

    foreach ([1, 2] as $delivery) {
        $this->postJson(webhookIngressUrl($this->connection), ['eventType' => 'Test', 'delivery' => $delivery], ['X-Webhook-Token' => 'secret'])
            ->assertOk();
    }

    $this->postJson(webhookIngressUrl($this->connection), ['eventType' => 'Test', 'delivery' => 3], ['X-Webhook-Token' => 'secret'])
        ->assertTooManyRequests();
});

test('a query-string token is not stored in the webhook payload', function (): void {
    $this->postJson(webhookIngressUrl($this->connection, ['token' => 'secret']), ['eventType' => 'Test', 'instanceName' => 'Sonarr'])
        ->assertOk();

    $webhookEvent = WebhookEvent::query()->sole();

    expect($webhookEvent->payload)->toBe(['eventType' => 'Test', 'instanceName' => 'Sonarr']);
});
