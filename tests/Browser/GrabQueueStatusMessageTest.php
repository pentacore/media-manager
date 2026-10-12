<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a queue status message without a title shows no stray colon', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [[
            'id' => 51, 'title' => 'Example.Show.S01E01.1080p', 'series' => ['title' => 'Example Show'],
            'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
            'status' => 'completed', 'trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importPending',
            'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 0,
            'timeleft' => '00:00:00', 'added' => '2026-09-29T10:00:00Z',
            'statusMessages' => [
                ['title' => '', 'messages' => ['No files found are eligible for import']],
                ['title' => 'Example.Show.S01E01.mkv', 'messages' => ['Not a Custom Format upgrade']],
            ],
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_51',
        ]]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.library.activity.queue', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-queue-row="sonarr-51"]', 'Example Show')
        ->assertCount('[data-queue-row="sonarr-51"] [data-queue-status-message]', 2)
        ->assertCount('[data-queue-row="sonarr-51"] [data-queue-status-title]', 1)
        ->assertSeeIn('[data-queue-row="sonarr-51"] [data-queue-status-title]', 'Example.Show.S01E01.mkv:')
        ->assertScript('document.querySelector(\'[data-queue-row="sonarr-51"] [data-queue-status-message]\').textContent.trim() === "No files found are eligible for import"');
});
