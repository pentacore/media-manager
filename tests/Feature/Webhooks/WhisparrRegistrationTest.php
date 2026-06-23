<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Enums\WhisparrVersion;
use App\Http\Controllers\WebhookController;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\ServiceClientFactory;
use App\Services\Whisparr\WhisparrClient;
use App\Services\Whisparr\WhisparrWebhookHandler;
use Illuminate\Http\Request;

test('Whisparr is a webhook-capable service type', function (): void {
    expect(ServiceType::Whisparr->value)->toBe('whisparr');
    expect(ServiceType::Whisparr->label())->toBe('Whisparr');
    expect(ServiceType::Whisparr->supportsWebhookConfiguration())->toBeTrue();
});

test('whisparrVersion defaults to V3 and reads the configured value', function (): void {
    $default = ServiceConnection::factory()->whisparr()->create();
    expect($default->whisparrVersion())->toBe(WhisparrVersion::V3);

    $v2 = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create();
    expect($v2->whisparrVersion())->toBe(WhisparrVersion::V2);
});

test('ServiceClientFactory builds a WhisparrClient', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->create();
    expect(resolve(ServiceClientFactory::class)->make($connection))->toBeInstanceOf(WhisparrClient::class);
});

test('ProcessWebhookEvent resolves Whisparr to WhisparrWebhookHandler', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->create();
    $reflection = new ReflectionMethod(ProcessWebhookEvent::class, 'resolveHandler');
    $handler = $reflection->invoke(
        new ProcessWebhookEvent(WebhookEvent::factory()->create([
            'service_connection_id' => $connection->id,
        ])),
        ServiceType::Whisparr,
    );
    expect($handler)->toBeInstanceOf(WhisparrWebhookHandler::class);
});

test('WebhookController extracts the eventType key for Whisparr', function (): void {
    $request = Request::create('/', 'POST', [], [], [], [], json_encode(['eventType' => 'Grab']));
    $request->headers->set('Content-Type', 'application/json');

    $reflection = new ReflectionMethod(WebhookController::class, 'extractEventType');
    $eventType = $reflection->invoke(resolve(WebhookController::class), $request, ServiceType::Whisparr);
    expect($eventType)->toBe('Grab');
});
