<?php

declare(strict_types=1);

use App\Models\User;

test('the statistics time window is one sideways strip on a phone and its last window can be picked', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('statistics.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript('(() => { const strip = document.querySelector("[data-filter-strip]"); const rect = strip.getBoundingClientRect(); return strip.scrollWidth > strip.clientWidth && rect.right <= window.innerWidth && new Set([...strip.querySelectorAll("button")].map((button) => Math.round(button.getBoundingClientRect().top))).size === 1; })()', true)
        ->assertScript('getComputedStyle(document.querySelector("[data-filter-strip]")).scrollbarWidth', 'none')
        ->click('[data-filter-strip] button:last-child')
        ->assertAttribute('[data-filter-strip] button:last-child', 'aria-pressed', 'true')
        ->assertScript(phoneOverflowScript('main'), '');
});

test('the activity log filter chips wrap on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('activity-log', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-activity-log-filters]')
        ->assertScript(phoneOverflowScript('[data-activity-log-filters]'), '');
});

test('at desktop width a strip shows every option without scrolling', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('statistics.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript('(() => { const strip = document.querySelector("[data-filter-strip]"); return strip.scrollWidth === strip.clientWidth; })()', true);
});
