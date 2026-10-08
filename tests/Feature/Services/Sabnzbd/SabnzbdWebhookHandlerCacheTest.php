<?php

declare(strict_types=1);

use App\Cache\Services\SabnzbdCache;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use App\Services\Sabnzbd\SabnzbdWebhookHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    Cache::store('array')->flush();
    Notification::fake();
    // complete/failed/queue_done recompute the download badge, which would
    // otherwise call the factory's SABnzbd host.
    $this->mock(SabnzbdDownloadCounter::class)->shouldReceive('recompute')->andReturn(['queued' => 0, 'completed' => 0]);
});

test('no SABnzbd event flushes the connection cache scope', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->sabnzbd()->create();
    $prefix = sprintf('sabnzbd:%d', $serviceConnection->id);
    new SabnzbdCache($serviceConnection)->rememberList('list', fn (): array => ['warm' => true]);

    resolve(SabnzbdWebhookHandler::class)->handle(WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $eventType,
        'payload' => ['eventType' => $eventType, 'name' => 'Some.Show.S01E01', 'title' => 'Disk almost full', 'message' => 'Less than 1 GB free'],
    ]));

    expect(Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix)))->toBe(['warm' => true]);
})->with(['complete', 'failed', 'startup', 'pause', 'resume', 'queue_done', 'warning', 'disk_full', 'something_new']);
