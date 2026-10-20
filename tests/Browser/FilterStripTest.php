<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
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

test('a deep-linked window past the fold scrolls into view in the strip on a phone', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // "year" is TimeWindow::ThisYear, the 8th of 9 options — a cold load on
    // a phone must not leave the pressed option scrolled off the strip.
    $webpage = visit(route('statistics.index', ['window' => 'year'], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(
        '(() => { const strip = document.querySelector("[data-filter-strip]"); const selected = strip.querySelector("[aria-pressed=\"true\"]"); const stripRect = strip.getBoundingClientRect(); const selectedRect = selected.getBoundingClientRect(); return selected.textContent.trim() === "This year" && selectedRect.left >= stripRect.left - 1 && selectedRect.right <= stripRect.right + 1; })()',
        true,
    );
});

test('the activity log filter chips wrap on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // Enough chips in the Service row (9: "all" + every ServiceType) and the
    // Actor row (5: "all" + 4 named actors) that neither fits one line at
    // 375px — the test must prove flex-wrap actually wraps, not just that a
    // row happened to already fit.
    foreach (ServiceType::cases() as $type) {
        ServiceConnection::factory()->create(['type' => $type]);
    }

    foreach (['Ada Lovelace', 'Grace Hopper', 'Linus Torvalds', 'Margaret Hamilton'] as $name) {
        ActivityLog::factory()->create(['user_id' => User::factory()->create(['name' => $name])->id]);
    }

    $webpage = visit(route('activity-log', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-activity-log-filters]')
        ->assertScript(phoneOverflowScript('[data-activity-log-filters]'), '')
        ->assertScript(
            '(() => { const groups = [...document.querySelectorAll("[data-activity-log-filters] > div")]; const serviceGroup = groups.find((group) => group.querySelector("span")?.textContent?.trim() === "Service"); const tops = new Set([...serviceGroup.querySelectorAll("button")].map((button) => Math.round(button.getBoundingClientRect().top))); return tops.size > 1; })()',
            true,
        );
});

test('at desktop width a strip shows every option without scrolling', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('statistics.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript('(() => { const strip = document.querySelector("[data-filter-strip]"); return strip.scrollWidth === strip.clientWidth; })()', true);
});
