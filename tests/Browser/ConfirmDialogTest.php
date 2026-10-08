<?php

declare(strict_types=1);

use App\Jobs\ClearSeerrRequests;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\ChatTemplate;
use App\Models\EmbyUserLink;
use App\Models\NotificationDestination;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function confirmDialogConnection(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create(['name' => 'Primary Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
}

test('a connection delete asks in the app dialog; Cancel keeps it and Delete removes it', function (): void {
    $serviceConnection = confirmDialogConnection();
    $this->actingAs(User::factory()->admin()->create());
    $menu = sprintf('[data-connection-row="%d"] [data-connection-menu]', $serviceConnection->id);

    $webpage = visit(route('admin.connections.index', absolute: false))
        ->assertNoSmoke()
        ->click($menu)
        ->click('[data-connection-delete]')
        ->assertSeeIn('[data-confirm-dialog]', 'Delete Primary Sonarr?')
        ->assertSeeIn('[data-confirm-dialog]', 'This cannot be undone.')
        ->assertScript("document.activeElement?.hasAttribute('data-confirm-cancel') === true")
        ->assertScript("document.querySelector('[data-confirm-accept]').classList.contains('bg-destructive') === true")
        ->assertScript("document.querySelector('[data-confirm-dialog]').hasAttribute('aria-describedby') === true")
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0);

    expect(ServiceConnection::query()->whereKey($serviceConnection->id)->exists())->toBeTrue();

    // The page stays usable after the dialog opened from a dropdown closes.
    $webpage->click($menu)
        ->click('[data-connection-delete]')
        ->click('[data-confirm-accept]')
        ->assertSee('Connection deleted.')
        ->assertNoSmoke();

    expect(ServiceConnection::query()->whereKey($serviceConnection->id)->exists())->toBeFalse();
});

test('Escape closes the confirm as a cancel', function (): void {
    $serviceConnection = confirmDialogConnection();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-connection-row="%d"] [data-connection-menu]', $serviceConnection->id))
        ->click('[data-connection-delete]')
        ->assertVisible('[data-confirm-dialog]')
        ->keys('[data-confirm-dialog]', 'Escape');

    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0);

    expect(ServiceConnection::query()->whereKey($serviceConnection->id)->exists())->toBeTrue();
});

test('clicking the overlay closes the confirm as a cancel', function (): void {
    $serviceConnection = confirmDialogConnection();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-connection-row="%d"] [data-connection-menu]', $serviceConnection->id))
        ->click('[data-connection-delete]')
        ->assertVisible('[data-confirm-dialog]');

    // A real click lands on the centered dialog content, not the overlay
    // behind it, so dismiss via the same pointerdown-outside event reka's
    // DismissableLayer listens for on the overlay element itself.
    $webpage->script(<<<'JS'
        document
            .querySelector('[data-slot="dialog-overlay"]')
            .dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, pointerType: 'mouse' }));
        JS);
    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0);

    expect(ServiceConnection::query()->whereKey($serviceConnection->id)->exists())->toBeTrue();
});

test('leaving the page while a confirm is open cancels it and deletes nothing', function (): void {
    $serviceConnection = confirmDialogConnection();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-connection-add]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", route('admin.connections.create', absolute: false))));

    // Back to the list through the browser history, so a forward entry exists.
    $webpage->script('window.history.back()');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("document.querySelector('[data-connection-row=\"%d\"]') !== null", $serviceConnection->id)));
    $webpage->click(sprintf('[data-connection-row="%d"] [data-connection-menu]', $serviceConnection->id))
        ->click('[data-connection-delete]')
        ->assertSeeIn('[data-confirm-dialog]', 'Delete Primary Sonarr?');

    $webpage->script('window.history.forward()');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", route('admin.connections.create', absolute: false))));
    $webpage->script(confirmDialogGoneScript());
    $webpage->assertCount('[data-confirm-dialog]', 0);

    expect(ServiceConnection::query()->whereKey($serviceConnection->id)->exists())->toBeTrue();
});

test('a user delete asks first; Cancel keeps the user and Delete removes them', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $mallory = User::factory()->create(['name' => 'Mallory']);
    $delete = sprintf('[data-user-row="%d"] [data-user-delete]', $mallory->id);

    $webpage = visit(route('admin.users.index', absolute: false))
        ->assertNoSmoke()
        ->click($delete)
        ->assertSeeIn('[data-confirm-dialog]', 'Delete Mallory?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    $webpage->assertScript("document.activeElement?.hasAttribute('data-user-delete') === true");

    expect(User::query()->whereKey($mallory->id)->exists())->toBeTrue();

    $webpage->click($delete)
        ->click('[data-confirm-accept]')
        ->assertSee('User deleted.')
        ->assertNoSmoke();

    expect(User::query()->whereKey($mallory->id)->exists())->toBeFalse();
});

test('an admin unlinking a user from Emby asks first; Cancel keeps the link and Unlink removes it', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $mallory = User::factory()->create(['name' => 'Mallory']);
    $embyUserLink = EmbyUserLink::factory()->for($mallory)->create(['emby_username' => 'mallory-emby']);
    $unlink = sprintf('[data-user-row="%d"] [data-user-unlink-emby]', $mallory->id);

    $webpage = visit(route('admin.users.index', absolute: false))
        ->assertNoSmoke()
        ->click($unlink)
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'Unlink Emby account "mallory-emby" from Mallory?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(EmbyUserLink::query()->whereKey($embyUserLink->id)->exists())->toBeTrue();

    // An admin unlink redirects to the Emby links page.
    $webpage->click($unlink)
        ->click('[data-confirm-accept]')
        ->assertSee('Link removed.');

    expect(EmbyUserLink::query()->whereKey($embyUserLink->id)->exists())->toBeFalse();
});

test('the Emby import asks first; Cancel calls nothing and Import runs it', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake(['emby.local:8096/Users' => Http::response([])]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-users-import-emby]')
        ->assertSeeIn('[data-confirm-dialog]', 'Import every Emby user as a viewer account here?')
        ->assertSeeIn('[data-confirm-dialog]', 'Existing accounts and links are skipped.')
        ->assertScript("document.querySelector('[data-confirm-accept]').classList.contains('bg-destructive') === false")
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/Users'));

    $webpage->click('[data-users-import-emby]')
        ->click('[data-confirm-accept]')
        ->assertSee('Imported 0 Emby user(s), skipped 0.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/Users'));
});

test('a notification destination removal asks first; Cancel keeps it and Remove deletes it', function (): void {
    $destination = NotificationDestination::factory()->create(['label' => 'Ops pager']);
    $this->actingAs(User::factory()->admin()->create());
    $remove = sprintf('[data-destination-row="%d"] [data-destination-remove]', $destination->id);

    $webpage = visit(route('admin.notification-destinations.index', absolute: false))
        ->assertNoSmoke()
        ->click($remove)
        ->assertSeeIn('[data-confirm-dialog]', 'Remove "Ops pager"?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(NotificationDestination::query()->whereKey($destination->id)->exists())->toBeTrue();

    $webpage->click($remove)
        ->click('[data-confirm-accept]')
        ->assertSee('Notification destination removed.')
        ->assertNoSmoke();

    expect(NotificationDestination::query()->whereKey($destination->id)->exists())->toBeFalse();
});

test('a free usage pool removal asks first; Cancel keeps it and Remove deletes it', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $pool = AiFreeUsagePool::factory()->create(['name' => 'Gemini free tier']);
    $this->actingAs(User::factory()->admin()->create());
    $remove = sprintf('[data-pool-row="%d"] [data-pool-delete]', $pool->id);

    $webpage = visit(route('admin.ai-prices.index', absolute: false))
        ->assertNoSmoke()
        ->click($remove)
        ->assertSeeIn('[data-confirm-dialog]', 'Remove pool "Gemini free tier"?')
        ->assertSeeIn('[data-confirm-dialog]', 'Member models keep their pricing but lose the free tier.')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(AiFreeUsagePool::query()->whereKey($pool->id)->exists())->toBeTrue();

    $webpage->click($remove)
        ->click('[data-confirm-accept]')
        ->assertSee('Free usage pool removed.')
        ->assertNoSmoke();

    expect(AiFreeUsagePool::query()->whereKey($pool->id)->exists())->toBeFalse();
});

test('a model price removal asks first; Cancel keeps it and Remove deletes it', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    $this->actingAs(User::factory()->admin()->create());
    $remove = '[data-price-row="gpt-5-mini"] [data-price-delete]';

    $webpage = visit(route('admin.ai-prices.index', absolute: false))
        ->assertNoSmoke()
        ->click($remove)
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'Remove pricing for openai/gpt-5-mini?')
        ->assertScript("document.querySelector('[data-confirm-dialog]').hasAttribute('aria-describedby') === false")
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(AiModelPrice::query()->whereKey($price->id)->exists())->toBeTrue();

    $webpage->click($remove)
        ->click('[data-confirm-accept]')
        ->assertSee('Model price removed.')
        ->assertNoSmoke();

    expect(AiModelPrice::query()->whereKey($price->id)->exists())->toBeFalse();
});

/**
 * One pending Seerr movie request (id 1, "The Matrix"). The list pattern also
 * answers the DELETE and the decline POST, so it is registered last.
 */
function confirmDialogFakeSeerr(): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 1, 'tv' => 0]),
        // posterPath keeps the card an <img> instead of Poster's text-hint
        // fallback, which would otherwise duplicate the title in the same
        // card and break strict-mode assertSeeIn (see DiscoverTest.php).
        'seerr.local:5055/api/v1/movie/603' => Http::response(['id' => 603, 'title' => 'The Matrix', 'posterPath' => '/matrix.jpg']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1],
            'results' => [[
                'id' => 1,
                'status' => 1,
                'type' => 'movie',
                'media' => ['mediaType' => 'movie', 'tmdbId' => 603],
                'requestedBy' => ['displayName' => 'Alice'],
                'createdAt' => '2026-10-01T00:00:00Z',
            ]],
        ]),
    ]);
}

test('unlinking your own Emby account asks first; Cancel keeps the link and Unlink removes it', function (): void {
    $member = User::factory()->member()->create();
    $embyUserLink = EmbyUserLink::factory()->for($member)->create(['emby_username' => 'alice-emby']);
    $this->actingAs($member);
    $unlink = sprintf('[data-emby-link="%d"] [data-emby-unlink]', $embyUserLink->id);

    $webpage = visit(route('profile.edit', absolute: false))
        ->assertNoSmoke()
        ->click($unlink)
        ->assertSeeIn('[data-confirm-dialog]', 'Unlink Emby account "alice-emby"?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(EmbyUserLink::query()->whereKey($embyUserLink->id)->exists())->toBeTrue();

    $webpage->click($unlink)
        ->click('[data-confirm-accept]')
        ->assertSee('Emby account unlinked.')
        ->assertNoSmoke();

    expect(EmbyUserLink::query()->whereKey($embyUserLink->id)->exists())->toBeFalse();
});

test('a template delete asks first; Cancel keeps it and Delete removes it', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Stuck downloads']);
    $this->actingAs($admin);
    $delete = sprintf('[data-template-row="%d"] [data-template-delete]', $chatTemplate->id);

    $webpage = visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->click($delete)
        ->assertSeeIn('[data-confirm-dialog]', 'Delete "Stuck downloads"?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(ChatTemplate::query()->whereKey($chatTemplate->id)->exists())->toBeTrue();

    $webpage->click($delete)
        ->click('[data-confirm-accept]')
        ->assertSee('Template deleted.')
        ->assertNoSmoke();

    expect(ChatTemplate::query()->whereKey($chatTemplate->id)->exists())->toBeFalse();
});

test('a Seerr request delete asks first; Cancel sends nothing and Delete removes it', function (): void {
    confirmDialogFakeSeerr();
    $this->actingAs(User::factory()->admin()->create());
    $isDelete = fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/request/1');

    $webpage = visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-request-card="1"]', 'The Matrix')
        ->click('[data-request-card="1"] [data-request-delete]')
        ->assertSeeIn('[data-confirm-dialog]', 'Delete request for "The Matrix"?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Http::assertNotSent($isDelete);

    $webpage->click('[data-request-card="1"] [data-request-delete]')
        ->click('[data-confirm-accept]')
        ->assertSee('Request deleted.')
        ->assertNoSmoke();

    Http::assertSent($isDelete);
});

test('a Seerr request decline asks first; Cancel sends nothing and Decline declines it', function (): void {
    confirmDialogFakeSeerr();
    $this->actingAs(User::factory()->admin()->create());
    $isDecline = fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/request/1/decline');

    $webpage = visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-request-card="1"] [data-request-decline]')
        ->assertSeeIn('[data-confirm-dialog]', 'Decline request for "The Matrix"?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Http::assertNotSent($isDecline);

    $webpage->click('[data-request-card="1"] [data-request-decline]')
        ->click('[data-confirm-accept]')
        ->assertSee('Request declined.')
        ->assertNoSmoke();

    Http::assertSent($isDecline);
});

test('clearing Seerr requests by status asks first; Cancel queues nothing and the confirm queues the clear', function (): void {
    Queue::fake([ClearSeerrRequests::class]);
    confirmDialogFakeSeerr();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-requests-clear-menu]')
        ->click('[data-requests-clear="failed"]')
        ->assertSeeIn('[data-confirm-dialog]', 'Permanently delete every failed Seerr request?')
        ->assertSeeIn('[data-confirm-dialog]', 'This covers requests Seerr could not push to Sonarr/Radarr. This cannot be undone.')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Queue::assertNotPushed(ClearSeerrRequests::class);

    $webpage->click('[data-requests-clear-menu]')
        ->click('[data-requests-clear="failed"]')
        ->click('[data-confirm-accept]')
        ->assertSee('in the background')
        ->assertNoSmoke();

    Queue::assertPushed(ClearSeerrRequests::class);
});
