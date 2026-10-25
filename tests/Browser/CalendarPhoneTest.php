<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

/**
 * @return array<int, array<string, string>>
 */
function calendarPhonePoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
    $this->seed(ActionTypeConfigSeeder::class);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    $episodes = [];
    foreach (range(1, 4) as $number) {
        $episodes[] = [
            'id' => 70 + $number, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => $number, 'title' => 'An Example Episode Title That Runs Long '.$number,
            'airDateUtc' => '2026-09-10T02:00:00Z', 'hasFile' => false, 'monitored' => true,
            'series' => ['title' => 'The Extraordinarily Long Example Series Title Of Doom', 'monitored' => true, 'images' => calendarPhonePoster()],
        ];
    }

    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response($episodes),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([[
            'id' => 10, 'title' => 'Dune', 'monitored' => true, 'hasFile' => false, 'digitalRelease' => '2026-09-24T00:00:00Z', 'images' => calendarPhonePoster(),
        ]]),
        'radarr.local:7878/*' => Http::response([]),
    ]);
});

test('the calendar opens in Agenda on a phone and its rows fit', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.calendar.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-calendar-agenda] [data-calendar-item]")'));

    $webpage->assertVisible('[data-calendar-agenda]')
        ->assertCount('[data-calendar-day]', 0)
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertScript('[...document.querySelectorAll("[data-calendar-agenda] [data-search-now]")].every((button) => { const rect = button.getBoundingClientRect(); return rect.right <= window.innerWidth; })', true)
        ->assertScript('[...document.querySelectorAll("[data-calendar-agenda] [data-calendar-item]")].every((row) => { const title = row.querySelector("[data-calendar-item-title]").getBoundingClientRect(); const search = row.querySelector("[data-search-now]").getBoundingClientRect(); return title.width >= 240 && search.top >= title.bottom; })', true)
        ->assertNoSmoke();
});

test('the month grid shows dots per day on a phone and a tapped day opens a full-width popover', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.calendar.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-calendar-view="month"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-calendar-day-open=\"2026-09-10\"]")'));

    $webpage->assertVisible('[data-calendar-day-open="2026-09-10"] [data-calendar-day-dots]')
        ->assertScript('document.querySelectorAll("[data-calendar-day-open=\"2026-09-10\"] [data-calendar-day-dots] > span.rounded-full").length', 3)
        ->assertSeeIn('[data-calendar-day-open="2026-09-10"] [data-calendar-day-dots]', '+1')
        ->assertMissing('[data-calendar-day-open="2026-09-10"] [data-calendar-day-rows]')
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-calendar-day-open="2026-09-10"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-calendar-day-popover=\"2026-09-10\"]") && document.querySelector("[data-calendar-day-popover=\"2026-09-10\"]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const rect = document.querySelector("[data-calendar-day-popover=\"2026-09-10\"]").getBoundingClientRect(); return Math.abs(rect.left - 16) <= 1 && Math.abs(rect.right - (window.innerWidth - 16)) <= 1; })()', true)
        ->assertVisible('[data-calendar-day-popover="2026-09-10"] [data-calendar-item]:first-child [data-search-now]')
        ->assertNoSmoke();
});

test('the calendar view toggles are 40 px targets on a phone', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.calendar.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript('(() => { const button = document.querySelector("[data-calendar-view=\"month\"]"); const rect = button.getBoundingClientRect(); const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top - 4); return rect.height < 40 && !!hit && button.contains(hit); })()', true);
});

test('at desktop width the month grid keeps its compact rows and its 384 px popover', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.calendar.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-calendar-day-open="2026-09-10"] [data-calendar-item]:first-child')
        ->assertMissing('[data-calendar-day-open="2026-09-10"] [data-calendar-day-dots]')
        ->click('[data-calendar-day-open="2026-09-10"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-calendar-day-popover=\"2026-09-10\"]")'));
    $webpage->assertScript('Math.round(document.querySelector("[data-calendar-day-popover=\"2026-09-10\"]").getBoundingClientRect().width)', 384);
});
