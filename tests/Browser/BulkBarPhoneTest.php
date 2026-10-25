<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ExecuteActionRequest::class]);
});

/**
 * Holds every bulk POST the page sends through fetch (`jsonRequest()`), as a
 * slow network would, until `window.__mmReleaseBulk()` runs.
 */
function bulkBarPhoneHoldBulkScript(): string
{
    return <<<'JS'
        (() => {
            const original = window.fetch;
            const held = [];
            window.__mmReleaseBulk = () => held.splice(0).forEach((release) => release());
            window.fetch = (input, init) => {
                const url = typeof input === 'string' ? input : input.url;
                if (url.includes('/bulk') && (init?.method ?? 'GET').toUpperCase() === 'POST') {
                    return new Promise((resolve, reject) => held.push(() => original(input, init).then(resolve, reject)));
                }
                return original(input, init);
            };
        })()
    JS;
}

function bulkBarPhoneHeightScript(): string
{
    return 'Math.round(document.querySelector("[data-bulk-bar]").getBoundingClientRect().height)';
}

test('an admin approves the selected actions from the 56 px phone bar', function (): void {
    $first = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $second = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-select="%d"]', $first->id))
        ->click(sprintf('[data-action-card-select="%d"]', $second->id));

    $webpage->assertSeeIn('[data-bulk-count]', '2 selected')
        ->assertScript(bulkBarPhoneHeightScript(), 56)
        ->assertScript('document.querySelector("[data-bulk-bar]").getBoundingClientRect().bottom <= window.innerHeight', true)
        ->assertMissing('[data-bulk-actions]')
        ->assertMissing('[data-bulk-clear]')
        ->assertVisible('[data-bulk-more]')
        ->click('[data-bulk-approve-phone]')
        ->assertSee('2 approved')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertNoSmoke();

    // The table's select-all is display:none on a phone, so focus falls back
    // to the page heading instead of dropping to <body>.
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement === document.querySelector("h1")'));
    $webpage->assertScript('document.activeElement === document.querySelector("h1")', true);

    expect($first->fresh()->status)->toBe(ActionRequestStatus::Approved)
        ->and($second->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('the phone bar menu rejects with a reason, and clears a selection', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    foreach ($pending as $actionRequest) {
        $webpage->click(sprintf('[data-action-card-select="%d"]', $actionRequest->id));
    }

    $webpage->click('[data-bulk-more]')
        ->click('[data-bulk-menu] [data-bulk-menu-item="reject"]')
        ->fill('[data-bulk-reject-reason]', 'Not wanted')
        ->click('[data-bulk-reject-confirm]')
        ->assertSee('2 rejected');

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->result)->toBe(['rejection_reason' => 'Not wanted']);
    }

    $another = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $webpage->navigate(route('actions.requests.index', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-select="%d"]', $another->id))
        ->click('[data-bulk-more]')
        ->click('[data-bulk-menu-clear]')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertNoSmoke();
});

test('the phone bar menu selects every selectable card on the page', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->described()->failed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-select="%d"]', $pending[0]->id))
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-bulk-more]')
        ->click('[data-bulk-menu] [data-bulk-select-all-phone]')
        // Three pending cards are selectable; the failed one has no checkbox.
        ->assertSeeIn('[data-bulk-count]', '3 selected')
        ->assertScript('document.querySelectorAll("[data-action-card-select] [role=\"checkbox\"][data-state=\"checked\"]").length', 3)
        ->assertNoSmoke();
});

test('the compact bar freezes while a bulk run is in flight', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    foreach ($pending as $actionRequest) {
        $webpage->click(sprintf('[data-action-card-select="%d"]', $actionRequest->id));
    }

    $webpage->script(bulkBarPhoneHoldBulkScript());

    $webpage->click('[data-bulk-approve-phone]');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-bulk-approve-phone]").disabled'));
    $webpage->assertScript('document.querySelector("[data-bulk-approve-phone]").disabled', true)
        ->assertScript(bulkBarPhoneHeightScript(), 56)
        ->click('[data-bulk-more]')
        ->assertAttribute('[data-bulk-menu-clear]', 'data-disabled', '')
        ->assertAttribute('[data-bulk-menu] [data-bulk-menu-item="reject"]', 'data-disabled', '');
    $webpage->keys('[data-bulk-menu]', 'Escape');

    $webpage->script('window.__mmReleaseBulk()');
    $webpage->assertSee('2 approved')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertNoSmoke();
});

test('the SABnzbd and grab queue selections get the compact bar on a phone', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sab.local:8080/api*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['mode'] ?? null) === 'history'
                ? Http::response(['history' => ['noofslots' => 0, 'slots' => []]])
                : Http::response(['queue' => ['paused' => false, 'speed' => '1 M', 'sizeleft' => '1 GB', 'timeleft' => '0:10:00', 'speedlimit' => '', 'speedlimit_abs' => '', 'noofslots' => 1, 'slots' => [
                    ['nzo_id' => 'SABnzbd_nzo_1', 'filename' => 'Severance.S01E01.1080p', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0'],
                ]]]);
        },
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [[
            'id' => 41, 'title' => 'Severance.S01E01.1080p', 'series' => ['title' => 'Severance'], 'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
            'status' => 'downloading', 'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading', 'protocol' => 'usenet', 'downloadClient' => 'SABnzbd',
            'size' => 1_000_000_000, 'sizeleft' => 500_000_000, 'timeleft' => '00:05:00', 'statusMessages' => [], 'added' => '2026-09-29T10:00:00Z',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_41',
        ]]]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-sab-card-select="SABnzbd_nzo_1"]')
        ->assertScript(bulkBarPhoneHeightScript(), 56)
        ->assertVisible('[data-bulk-sab-phone="pause"]')
        ->click('[data-bulk-more]')
        ->assertVisible('[data-bulk-menu] [data-bulk-menu-item="resume"]')
        ->assertVisible('[data-bulk-menu] [data-bulk-select-all-phone]')
        ->click('[data-bulk-menu] [data-bulk-menu-item="delete"]')
        ->assertVisible('[data-bulk-sab-delete-confirm]');

    $webpage->navigate(route('media.library.activity.queue', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->click('[data-service-filter="sonarr"]')
        ->click('[data-queue-card-select="41"]')
        ->assertScript(bulkBarPhoneHeightScript(), 56)
        ->click('[data-bulk-queue-phone="remove"]')
        ->assertVisible('[data-bulk-queue-confirm]')
        ->assertNoSmoke();
});

test('at desktop width the bulk bar keeps its inline actions and no menu', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-bulk-select="%d"]', $pending->id))
        ->assertVisible('[data-bulk-approve]')
        ->assertVisible('[data-bulk-reject]')
        ->assertVisible('[data-bulk-clear]')
        ->assertMissing('[data-bulk-more]')
        ->assertMissing('[data-bulk-approve-phone]');
});
