<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/*
 * Batch 3h-3, Task 6: an activity-log row wraps on a phone and breaks a long
 * description while desktop keeps one truncated line, and the statistics
 * grids keep a long title inside their cards between `sm` and `lg`.
 */

/**
 * True when every card of the Statistics watch grid, and the leaderboard
 * table inside it, ends inside the grid.
 */
function activityStatisticsWatchGridHoldsScript(): string
{
    return '(() => { const grid = document.querySelector("[data-statistics-watch-grid]").getBoundingClientRect(); return [...document.querySelectorAll("[data-statistics-watch-grid] > *, [data-statistics-watch-grid] table")].every((box) => box.getBoundingClientRect().right <= grid.right + 1); })()';
}

test('an activity-log row wraps on a phone and breaks a long description', function (): void {
    $member = User::factory()->member()->create();
    ActivityLog::factory()->for($member)->create(['description' => 'Imported /data/media/tv/'.str_repeat('Example-Unbroken-Path-Segment-', 4).'S01E01.mkv']);
    $this->actingAs($member);

    $webpage = visit(route('activity-log', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(phoneOverflowScript('[data-activity-feed]'), '')
        ->assertScript('(() => { const row = document.querySelector("[data-activity-row]"); const description = row.querySelector("[data-activity-description]"); const time = row.querySelector("time"); return description.getBoundingClientRect().top >= time.getBoundingClientRect().bottom && description.getBoundingClientRect().right <= window.innerWidth; })()', true);
});

test('an activity-log row stays one truncated line on desktop', function (): void {
    $member = User::factory()->member()->create();
    ActivityLog::factory()->for($member)->create(['description' => str_repeat('A long example description that runs on. ', 8)]);
    $this->actingAs($member);

    visit(route('activity-log', absolute: false))->assertNoSmoke()
        ->assertScript('getComputedStyle(document.querySelector("[data-activity-description]")).whiteSpace', 'nowrap')
        ->assertScript('document.querySelector("[data-activity-row]").firstElementChild.getBoundingClientRect().height < 48', true);
});

test('the busiest activity log fits a phone with every control a 40 px target', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Example Administrator With A Long Name']);
    $this->actingAs($admin);
    foreach (['sonarr', 'radarr', 'sabnzbd', 'emby', 'seerr'] as $type) {
        ServiceConnection::factory()->{$type}()->create(['name' => 'Example '.ucfirst($type).' Connection']);
    }

    $serviceConnection = ServiceConnection::query()->where('name', 'Example Sonarr Connection')->sole();
    ActivityLog::factory()->for($admin)->count(118)->create(['action' => 'sabnzbd.queue.paused', 'description' => 'Paused the SABnzbd queue.', 'created_at' => now()->subHour()]);
    ActivityLog::factory()->for($admin)->create(['service_connection_id' => $serviceConnection->id, 'action' => 'media.library.episode.imported', 'created_at' => now()->subMinute(), 'description' => 'Imported /data/media/tv/'.str_repeat('Example-Unbroken-Path-Segment-', 4).'S01E01.mkv']);
    resolve(AuditLogger::class)->record('connection.updated', null, 'Updated Sonarr connection "Example".', [
        'url' => ['from' => 'http://sonarr.local:8989/'.str_repeat('example-unbroken-path-', 4), 'to' => 'http://sonarr.local:8989'],
        'api_key' => ['from' => 'old-secret-key', 'to' => 'new-secret-key'],
    ]);

    $webpage = visit(route('activity-log', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(echoEmitScript('activity.audit', 'ActivityLogCreated', [
        'id' => 999_999, 'action' => 'connection.updated', 'category' => 'audit', 'description' => 'Updated Radarr connection "Example".',
        'user_name' => 'Example Administrator With A Long Name', 'service_id' => null, 'service_name' => null, 'service_type' => null,
        'subject_type' => null, 'subject_id' => null, 'metadata' => null, 'created_at' => now()->toISOString(),
    ]));
    $webpage->assertSeeIn('[data-activity-new-count]', '1 new')
        ->click('[data-audit-expand]')
        ->assertPresent('[data-audit-changes]');

    $webpage->assertScript(phoneOverflowScript('main'), '')
        ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0)
        ->assertScript(phoneOverlapScript('main'), '')
        ->assertScript(phoneScrollSliverScript('main'), '')
        ->assertScript(phoneSmallTargetsScript(), '');
});

test('the statistics grids keep a long title inside their cards from 640 to 1023 px', function (int $width): void {
    EmbyActivity::factory()->create(['media_type' => 'movie', 'media_title' => 'An-Extremely-Long-Unbroken-Example-Movie-Title-That-Never-Wraps-Anywhere', 'series_title' => null, 'action' => 'played']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('statistics.index', absolute: false))->assertNoSmoke();
    $webpage->resize($width, 1000);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-statistics-heatmap]") && !document.querySelector("main .animate-pulse:not(header *)")'));

    $webpage->assertScript('(() => { const grid = document.querySelector("[data-statistics-watch-grid]").getBoundingClientRect(); return [...document.querySelectorAll("[data-statistics-watch-grid] > *")].every((card) => card.getBoundingClientRect().right <= grid.right + 1); })()', true)
        ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0);
})->with([640, 800, 1023]);

test('the busiest statistics page fits a phone and holds a long viewer name up to 1023 px', function (): void {
    $viewer = User::factory()->create(['name' => 'ExampleUnbrokenViewerNameThatRunsPastAPhoneScreen']);
    $link = EmbyUserLink::factory()->create(['user_id' => $viewer->id]);
    StatRollup::factory()->create(['metric' => 'watch.user_plays', 'dimensions' => ['emby_user_link_id' => $link->id], 'count' => 120]);
    StatRollup::factory()->create(['metric' => 'watch.seconds', 'dimensions' => ['emby_user_link_id' => $link->id], 'count' => 120, 'sum' => 4_000_000]);
    EmbyActivity::factory()->count(120)->create(['emby_user_link_id' => $link->id, 'media_type' => 'movie', 'media_title' => 'An-Extremely-Long-Unbroken-Example-Movie-Title-That-Never-Wraps-Anywhere', 'series_title' => null, 'action' => 'played']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('statistics.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-statistics-heatmap]") && !document.querySelector("main .animate-pulse:not(header *)")'));

    $webpage->assertSeeIn('[data-statistics-watch-grid]', '120 plays')
        ->assertSeeIn('[data-statistics-watch-grid]', 'ExampleUnbrokenViewerName')
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0)
        ->assertScript(activityStatisticsWatchGridHoldsScript(), true);

    foreach ([800, 1023] as $width) {
        $webpage->resize($width, 1000);
        $webpage->assertScript(activityStatisticsWatchGridHoldsScript(), true)
            ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0);
    }
});

test('the busiest operational statistics page fits a phone and holds a long connection name up to 1023 px', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'ExampleUnbrokenConnectionNameThatRunsPastAPhone']);
    StatRollup::factory()->create(['metric' => 'service.uptime_pct', 'dimensions' => ['service_connection_id' => $connection->id], 'count' => 120, 'sum' => 118]);
    StatRollup::factory()->create(['metric' => 'service.latency_ms', 'dimensions' => ['service_connection_id' => $connection->id], 'count' => 120, 'sum' => 120_000]);
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'dimensions' => ['service' => 'sonarr'], 'count' => 999]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.statistics.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertSee('ExampleUnbrokenConnectionName')
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0);

    foreach ([800, 1023] as $width) {
        $webpage->resize($width, 1000);
        $webpage->assertScript(phoneOverflowScript('main'), '')
            ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0);
    }
});
