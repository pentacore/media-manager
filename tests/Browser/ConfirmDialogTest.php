<?php

declare(strict_types=1);

use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\EmbyUserLink;
use App\Models\NotificationDestination;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Polls inside the page until the JavaScript condition holds (or ~5 s pass),
 * so script() returns only once an async UI change has landed.
 */
function confirmDialogWaitUntilScript(string $condition): string
{
    return <<<JS
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if ({$condition}) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS;
}

/**
 * Waits until the confirm dialog has left the DOM; its exit animation keeps
 * it mounted briefly after it closes.
 */
function confirmDialogGoneScript(): string
{
    return confirmDialogWaitUntilScript("!document.querySelector('[data-confirm-dialog]')");
}

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

test('leaving the page while a confirm is open cancels it and deletes nothing', function (): void {
    $serviceConnection = confirmDialogConnection();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-connection-add]');
    $webpage->script(confirmDialogWaitUntilScript("window.location.pathname === '/admin/connections/create'"));

    // Back to the list through the browser history, so a forward entry exists.
    $webpage->script('window.history.back()');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("document.querySelector('[data-connection-row=\"%d\"]') !== null", $serviceConnection->id)));
    $webpage->click(sprintf('[data-connection-row="%d"] [data-connection-menu]', $serviceConnection->id))
        ->click('[data-connection-delete]')
        ->assertSeeIn('[data-confirm-dialog]', 'Delete Primary Sonarr?');

    $webpage->script('window.history.forward()');
    $webpage->script(confirmDialogWaitUntilScript("window.location.pathname === '/admin/connections/create'"));
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
        // Scoped to the title: with no description the dialog also renders
        // the title as a screen-reader-only description, and both are
        // visible to Playwright's locator (sr-only hides visually, not from
        // the accessibility tree), so an unscoped match is ambiguous.
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
        // Scoped to the title: no description means the dialog also renders
        // the title as the (visually hidden but Playwright-visible)
        // accessible description, which makes an unscoped match ambiguous.
        ->assertSeeIn('[data-confirm-dialog] [data-slot="dialog-title"]', 'Remove pricing for openai/gpt-5-mini?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect(AiModelPrice::query()->whereKey($price->id)->exists())->toBeTrue();

    $webpage->click($remove)
        ->click('[data-confirm-accept]')
        ->assertSee('Model price removed.')
        ->assertNoSmoke();

    expect(AiModelPrice::query()->whereKey($price->id)->exists())->toBeFalse();
});
