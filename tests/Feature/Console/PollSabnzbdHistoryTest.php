<?php

declare(strict_types=1);

use App\Events\SabnzbdDownloadFinished;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([SabnzbdDownloadFinished::class]);
});

test('command logs completed and failed slots and advances the cursor', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
        'settings' => ['last_history_unix' => 100],
    ]);

    Http::fake([
        'sab.local:8080/sabnzbd/api*' => Http::response([
            'history' => [
                'slots' => [
                    [
                        'nzo_id' => 'A',
                        'name' => 'Show.S01E01',
                        'status' => 'Completed',
                        'completed' => 200,
                    ],
                    [
                        'nzo_id' => 'B',
                        'name' => 'Show.S01E02',
                        'status' => 'Failed',
                        'completed' => 250,
                        'fail_message' => 'unpack error',
                    ],
                    [
                        'nzo_id' => 'C',
                        'name' => 'old',
                        'status' => 'Completed',
                        'completed' => 50,
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('sabnzbd:poll-history')->assertSuccessful();

    expect(ActivityLog::query()->where('action', 'sabnzbd.download.completed')->count())->toBe(1);
    expect(ActivityLog::query()->where('action', 'sabnzbd.download.failed')->count())->toBe(1);

    Event::assertDispatchedTimes(SabnzbdDownloadFinished::class, 2);

    $connection->refresh();
    expect($connection->settings['last_history_unix'])->toBe(250);
});

test('command skips inactive SABnzbd connections', function (): void {
    ServiceConnection::factory()->sabnzbd()->inactive()->create();

    $this->artisan('sabnzbd:poll-history')->assertSuccessful();

    expect(ActivityLog::query()->count())->toBe(0);
    Event::assertNotDispatched(SabnzbdDownloadFinished::class);
});
