<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Library\InterventionCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function grabQueueBrowserRecord(int $id, string $series): array
{
    return [
        'id' => $id, 'title' => sprintf('%s.S01E01.1080p', $series), 'series' => ['title' => $series],
        'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
        'status' => 'downloading', 'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading',
        'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 500_000_000,
        'timeleft' => '00:05:00', 'statusMessages' => [], 'added' => '2026-09-29T10:00:00Z',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => sprintf('SABnzbd_nzo_%d', $id),
    ];
}

function fakeGrabQueueBrowser(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 200)
            : Http::response(['records' => [grabQueueBrowserRecord(41, 'Severance'), grabQueueBrowserRecord(42, 'Andor')]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
    ]);
}

beforeEach(function (): void {
    // The shared nav badge would otherwise walk the Sonarr queue on every request.
    Cache::put(InterventionCounter::CACHE_KEY, 0, 600);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('an admin removes two Sonarr queue items in bulk', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->click('[data-bulk-select="42"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-queue-action="remove"]')
        ->assertSeeIn('[data-bulk-queue-confirm]', 'Confirm')
        ->click('[data-bulk-queue-confirm]')
        ->assertSee('2 removed')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/41?'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/queue/42?'));
});

test('with both services shown the page asks to pick one before selecting', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->assertPresent('[data-queue-bulk-hint]')
        ->assertCount('[data-bulk-select]', 0);
});

test('a member sees no queue selection', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-41"]', 'Severance')
        ->click('[data-service-filter="sonarr"]')
        ->assertCount('[data-bulk-select]', 0);
});

test('the selection clears when the service filter changes', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-service-filter="radarr"]')
        ->click('[data-service-filter="sonarr"]')
        ->assertPresent('[data-queue-row="sonarr-41"]')
        ->assertDontSee('1 selected');
});

test('the selection clears when switching to the history tab and back', function (): void {
    fakeGrabQueueBrowser();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->click('[data-service-filter="sonarr"]')
        ->click('[data-bulk-select="41"]')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-activity-tab="history"]')
        ->click('[data-activity-tab="queue"]')
        ->click('[data-service-filter="sonarr"]')
        ->assertDontSee('1 selected');
});
