<?php

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

test('webhook with valid token is accepted', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertOk();
});

test('webhook with invalid token is rejected', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'wrong-token']
    )->assertUnauthorized();
});

test('webhook with missing token is rejected', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        ['eventType' => 'Test']
    )->assertUnauthorized();
});

test('webhook for inactive connection is rejected', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->inactive()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertNotFound();
});

test('webhook for non-existent connection returns 404', function (): void {
    $this->postJson(
        '/api/webhooks/sonarr/999',
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'some-token']
    )->assertNotFound();
});

test('webhook for mismatched service type returns 404', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertNotFound();
});

test('a Bazarr connection is not reachable on the generic webhook endpoint', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create(['webhook_token' => 'test-secret-token']);

    $this->postJson(
        '/api/webhooks/bazarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertNotFound();

    expect(WebhookEvent::query()->count())->toBe(0);
});
