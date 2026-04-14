<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyWebhookHandler;
use App\Services\Webhook\WebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

test('job is queued', function (): void {
    expect(new ProcessWebhookEvent(WebhookEvent::factory()->create()))
        ->toBeInstanceOf(ShouldQueue::class);
});

test('job is a no-op when service connection is missing', function (): void {
    $event = WebhookEvent::factory()->create();
    $event->serviceConnection->delete();
    $event->refresh();

    Log::shouldReceive('warning')->once();

    new ProcessWebhookEvent($event)->handle();
})->skip('skipped: foreign key cascade prevents orphaned webhook events');

test('job logs and skips when no handler is registered for service type', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);

    Log::shouldReceive('info')->once();

    new ProcessWebhookEvent($event)->handle();
});

test('job invokes EmbyWebhookHandler for emby service connections when handler exists', function (): void {
    if (! class_exists(EmbyWebhookHandler::class)) {
        $this->markTestSkipped('EmbyWebhookHandler does not exist yet — covered in Task 2.');
    }

    $connection = ServiceConnection::factory()->emby()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);

    $mock = Mockery::mock(WebhookHandler::class);
    $mock->shouldReceive('handle')->once()->with($event);
    $this->app->bind(EmbyWebhookHandler::class, fn (): WebhookHandler => $mock);

    new ProcessWebhookEvent($event)->handle();
});
