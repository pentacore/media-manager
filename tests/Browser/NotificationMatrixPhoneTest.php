<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Batch 3h-3, Task 7: every cell of the notification preference matrices is a
 * named 40 px target on a phone, while desktop keeps the bare 16 px checkbox.
 */

test('every notification matrix cell is a named 40 px target on a phone', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('settings.notifications.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(phoneSmallTargetsScript('[data-notification-matrix]'), '')
        ->assertScript('[...document.querySelectorAll("[data-notification-matrix] input[type=checkbox]")].every((input) => (input.getAttribute("aria-label") || "").includes(" by "))', true)
        ->assertScript(phoneOverflowScript('main'), '');

    // A tap 2 px inside the label's corner, 12 px outside the 16 px box, toggles the cell.
    $webpage->assertScript('(() => { const input = document.querySelector("[data-notification-matrix] input[type=checkbox]"); const before = input.checked; const label = input.closest("label"); label.scrollIntoView({ block: "center" }); const rect = label.getBoundingClientRect(); document.elementFromPoint(rect.left + 2, rect.top + 2).click(); return input.checked !== before; })()', true);
});

test('the notification matrix cells are unchanged on desktop', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('settings.notifications.edit', absolute: false))->assertNoSmoke()
        ->assertScript('getComputedStyle(document.querySelector("[data-notification-matrix] label")).display', 'contents')
        ->assertScript('Math.round(document.querySelector("[data-notification-matrix] input[type=checkbox]").getBoundingClientRect().width)', 16);
});
