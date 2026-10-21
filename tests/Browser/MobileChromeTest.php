<?php

declare(strict_types=1);

use App\Models\User;

test('the phone sidebar has a 40 px trigger and closes after following a link', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript('(() => { const rect = document.querySelector("[data-sidebar=\"trigger\"]").getBoundingClientRect(); return Math.round(rect.width) === 40 && Math.round(rect.height) === 40; })()', true)
        ->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-sidebar="sidebar"][data-mobile="true"]')
        ->click('[data-sidebar="sidebar"][data-mobile="true"] a[href$="/activity-log"]')
        ->assertPathIs('/activity-log');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-sidebar=\"sidebar\"][data-mobile=\"true\"]")'));
    $webpage->assertNotPresent('[data-sidebar="sidebar"][data-mobile="true"]');
});

test('the phone header search button opens the palette fully on screen', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-search]')
        ->assertVisible('[data-palette-sections]');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));
    $webpage->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"]").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight && rect.left >= 0 && rect.right <= window.innerWidth; })()', true)
        ->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"] input[type=\"search\"]").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true)
        ->type('input[type="search"]', 'severance')
        ->keys('input[type="search"]', 'Enter')
        ->assertPathIs('/media/search');
});

test('at desktop width the header keeps its search field and shows no search button', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-header-search]')
        ->assertScript('Math.round(document.querySelector("[data-sidebar=\"trigger\"]").getBoundingClientRect().width)', 28);
});

test('a breadcrumb trail stays on one line inside the phone header', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.decision-agent.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertScript('document.querySelector("[data-slot=\"breadcrumb-list\"]").getBoundingClientRect().height <= 24', true)
        ->assertScript('(() => { const header = document.querySelector("main header"); const list = document.querySelector("[data-slot=\"breadcrumb-list\"]").getBoundingClientRect(); const rect = header.getBoundingClientRect(); return list.top >= rect.top && list.bottom <= rect.bottom; })()', true)
        ->assertScript(phoneOverflowScript('main header'), '');
});

test('a three-level breadcrumb trail shows only the current page left of the phone header search', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());

    // "Assistant › AI Assistant › Templates" is wider than the room the phone
    // header leaves; the parent crumbs must not push the current one out or
    // paint under the search button.
    $webpage = visit(route('ai.templates.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-slot="breadcrumb-page"]')
        ->assertAttribute('[data-slot="breadcrumb-page"]', 'title', 'Templates')
        ->assertScript('(() => { const crumb = document.querySelector("[data-slot=\"breadcrumb-page\"]"); const rect = crumb.getBoundingClientRect(); const search = document.querySelector("[data-header-search]").getBoundingClientRect(); return rect.width > 0 && crumb.scrollWidth <= crumb.clientWidth && rect.right <= search.left; })()', true)
        ->assertScript('(() => { const search = document.querySelector("[data-header-search]").getBoundingClientRect(); return [...document.querySelectorAll("[data-slot=\"breadcrumb-item\"], [data-slot=\"breadcrumb-separator\"]")].map((el) => el.getBoundingClientRect()).filter((rect) => rect.width > 0 && rect.height > 0).filter((rect) => rect.right > search.left && rect.left < search.right && rect.bottom > search.top && rect.top < search.bottom).length; })()', 0);
});

test('at desktop width the breadcrumb trail keeps every crumb', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript('[...document.querySelectorAll("[data-slot=\"breadcrumb-item\"]")].filter((el) => el.getBoundingClientRect().width > 0).map((el) => el.textContent.trim()).join(" > ")', 'Assistant > AI Assistant > Templates');
});

/**
 * Polls until the phone sidebar sheet has left the DOM.
 */
function mobileChromeSheetClosedScript(): string
{
    return confirmDialogWaitUntilScript('!document.querySelector("[data-sidebar=\"sidebar\"][data-mobile=\"true\"]")');
}

test('the phone sidebar closes after a click on a link it already prefetched', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script('(() => { window.__mmPrefetched = []; document.addEventListener("inertia:prefetched", (event) => window.__mmPrefetched.push(event.detail.visit.url.pathname)); })()');
    $webpage->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-sidebar="sidebar"][data-mobile="true"] [data-nav-item="Action Queue"]')
        ->hover('[data-sidebar="sidebar"][data-mobile="true"] [data-nav-item="Action Queue"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf('window.__mmPrefetched.includes("%s")', $actionQueuePath)));

    expect($webpage->script(sprintf('window.__mmPrefetched.includes("%s")', $actionQueuePath)))->toBeTrue();

    $webpage->click('[data-sidebar="sidebar"][data-mobile="true"] [data-nav-item="Action Queue"]')
        ->assertPathIs($actionQueuePath);
    $webpage->script(mobileChromeSheetClosedScript());
    $webpage->assertNotPresent('[data-sidebar="sidebar"][data-mobile="true"]');
});

test('the phone sidebar closes when the browser goes back', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-sidebar="trigger"]')
        ->click('[data-sidebar="sidebar"][data-mobile="true"] a[href$="/activity-log"]')
        ->assertPathIs('/activity-log');
    $webpage->script(mobileChromeSheetClosedScript());
    $webpage->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-sidebar="sidebar"][data-mobile="true"]')
        ->back()
        ->assertPathIs(route('dashboard', absolute: false));
    $webpage->script(mobileChromeSheetClosedScript());
    $webpage->assertNotPresent('[data-sidebar="sidebar"][data-mobile="true"]');
});

test('the phone sidebar closes after a tap on the link to the page already open', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // A visit to the current URL replaces the page and fires no `navigate`.
    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-sidebar="trigger"]')
        ->click('[data-sidebar="sidebar"][data-mobile="true"] [data-nav-item="Dashboard"]');
    $webpage->script(mobileChromeSheetClosedScript());
    $webpage->assertNotPresent('[data-sidebar="sidebar"][data-mobile="true"]')
        ->assertPathIs(route('dashboard', absolute: false));
});

test('at desktop width a click on a sidebar link keeps the sidebar expanded', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertAttribute('[data-slot="sidebar"][data-state]', 'data-state', 'expanded')
        ->click('[data-nav-item="Dashboard"]')
        ->assertAttribute('[data-slot="sidebar"][data-state]', 'data-state', 'expanded')
        ->click('[data-nav-item="Activity log"]')
        ->assertPathIs('/activity-log')
        ->assertAttribute('[data-slot="sidebar"][data-state]', 'data-state', 'expanded');
});
