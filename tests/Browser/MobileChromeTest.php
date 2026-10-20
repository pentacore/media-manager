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
