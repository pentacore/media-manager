<?php

declare(strict_types=1);

use App\Models\BenchmarkSchedule;
use App\Models\EmbyUserLink;
use App\Models\NotificationDestination;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Admin tables stay tables on a phone and scroll sideways inside their own
 * frame. `sticky-first-column` keeps each row's first cell, its name, at the
 * frame's left edge while the rest scrolls under it; desktop is untouched.
 */

function adminTablePhoneSeed(): User
{
    config()->set('mediamanager.ai.enabled', true);
    $admin = User::factory()->admin()->create(['name' => 'Example Admin', 'email' => 'example.admin.with.a.long.address@example.com']);

    foreach (range(1, 3) as $index) {
        User::factory()->member()->create(['name' => 'Example Member '.$index, 'email' => sprintf('example.member.%d.with.a.long.address@example.com', $index)]);
    }

    Http::fake([
        'emby.local:8096/Users*' => Http::response([
            ['Id' => 'emby-1', 'Name' => 'Alice', 'Policy' => ['IsAdministrator' => true], 'LastActivityDate' => '2026-09-28T20:15:00Z'],
            ['Id' => 'emby-2', 'Name' => 'Bob', 'Policy' => ['IsAdministrator' => false], 'LastActivityDate' => null],
            ['Id' => 'emby-3', 'Name' => 'Carol', 'Policy' => ['IsAdministrator' => false], 'LastActivityDate' => '2026-09-20T08:00:00Z'],
        ]),
        'emby.local:8096/*' => Http::response([]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);

    ServiceConnection::factory()->emby()->create(['name' => 'Emby', 'url' => 'http://emby.local:8096', 'api_key' => 'k']);
    ServiceConnection::factory()->sonarr()->create(['name' => 'Example-Connection-With-A-Very-Long-Unbroken-Name-0123456789', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    EmbyUserLink::factory()->create(['emby_user_id' => 'emby-1', 'emby_username' => 'Alice']);
    NotificationDestination::factory()->discord()->count(2)->create();
    test()->seed(ActionTypeConfigSeeder::class);
    $server = Server::factory()->create(['name' => 'example-host-01']);
    ServerIncident::factory()->open()->create(['server_id' => $server->id]);
    BenchmarkSchedule::factory()->count(2)->create(['created_by' => $admin->id]);
    DB::table('agent_conversations')->insert(['id' => '01900000-0000-7000-8000-000000000901', 'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id, 'title' => 'Example conversation about a long series title', 'created_at' => now(), 'updated_at' => now()]);

    return $admin;
}

/**
 * Scrolls the table's frame fully right, then returns 'ok' or the first
 * failed condition: the frame scrolled, the first header and body cells sit
 * at the frame's left edge, and a tap on the first body cell lands in it.
 */
function adminTableStickyScript(string $key): string
{
    $encoded = json_encode(sprintf('[data-admin-table="%s"]', $key), JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const hook = document.querySelector({$encoded});
            if (!hook) {
                return 'no table';
            }
            const table = hook.tagName === 'TABLE' ? hook : hook.querySelector('table');
            const frame = table.parentElement;
            frame.scrollLeft = frame.scrollWidth;
            if (!(frame.scrollLeft > 0)) {
                return 'the frame does not scroll';
            }
            const left = frame.getBoundingClientRect().left;
            const head = table.querySelector('th');
            const cell = table.querySelector('tbody td');
            if (Math.abs(head.getBoundingClientRect().left - left) > 1) {
                return 'the first th left ' + head.getBoundingClientRect().left + ' vs frame ' + left;
            }
            const rect = cell.getBoundingClientRect();
            if (Math.abs(rect.left - left) > 1) {
                return 'the first td left ' + rect.left + ' vs frame ' + left;
            }
            const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            if (!hit || !cell.contains(hit)) {
                return 'a tap on the first td lands on ' + (hit ? hit.tagName : 'nothing');
            }
            return 'ok';
        })()
    JS;
}

test('an admin table keeps its first column on screen while it scrolls sideways on a phone', function (string $routeName, string $key): void {
    $this->actingAs(adminTablePhoneSeed());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("main [data-slot=\"skeleton\"]")'));

    $webpage->assertScript(adminTableStickyScript($key), 'ok');
})->with([
    'users' => ['admin.users.index', 'users'],
    'connections' => ['admin.connections.index', 'connections'],
    'notification destinations' => ['admin.notification-destinations.index', 'destinations'],
    'action rules' => ['actions.rules.index', 'action-rules'],
    'servers' => ['admin.servers.index', 'servers'],
    'server incidents' => ['admin.servers.incidents.index', 'incidents'],
    'benchmark schedules' => ['admin.benchmarks.schedules.index', 'schedules'],
    'ai conversations' => ['admin.ai-conversations.index', 'conversations'],
    'emby links' => ['emby.links.index', 'emby-links'],
]);

test('a long name in a sticky first column takes at most 45 % of the screen', function (): void {
    $this->actingAs(adminTablePhoneSeed());

    $webpage = visit(route('admin.connections.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript('(() => { const cell = document.querySelector("[data-admin-table=\"connections\"] tbody td"); return cell.getBoundingClientRect().width <= window.innerWidth * 0.45 + 25; })()', true)
        ->assertScript('(() => { const cell = document.querySelector("[data-admin-table=\"connections\"] tbody td"); const style = getComputedStyle(cell); return cell.getBoundingClientRect().width >= Math.min(128, window.innerWidth * 0.45) + parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) - 1; })()', true)
        ->assertScript(adminTableStickyScript('connections'), 'ok');
});

test('a control scrolled into view in an admin table lands beside the sticky column, not under it', function (): void {
    $this->actingAs(adminTablePhoneSeed());

    $webpage = visit(route('emby.links.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("main [data-slot=\"skeleton\"]")'));

    // The table sits inside a card, so the sticky column covers the frame's
    // centre: without the frame's scroll padding a centred control sits under it.
    $webpage->assertScript('(() => { const control = document.querySelector("[data-admin-table=\"emby-unlinked\"] [data-emby-link-trigger=\"emby-2\"]"); control.scrollIntoView({ block: "center", inline: "center" }); const rect = control.getBoundingClientRect(); const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2); return !!hit && control.contains(hit); })()', true);
});

test('admin tables keep a plain first column on desktop', function (): void {
    $this->actingAs(adminTablePhoneSeed());

    visit(route('admin.users.index', absolute: false))->assertNoSmoke()
        ->assertScript('getComputedStyle(document.querySelector("[data-admin-table=\"users\"] tbody td")).position', 'static')
        ->assertScript('getComputedStyle(document.querySelector("[data-admin-table=\"users\"] tbody td")).backgroundColor', 'rgba(0, 0, 0, 0)');
});
