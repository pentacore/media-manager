<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Batch 3h-3, Task 12: the show/hide toggle sits on the password input
 * itself, centred on its height and at its right edge, even when the call
 * site gives the input a margin (Confirm password) or a width cap (Profile).
 */

/**
 * Describes how far the toggle of the password input `$inputSelector` sits
 * from the input's vertical centre and right edge; empty when it is on it.
 */
function passwordInputToggleOffsetScript(string $inputSelector): string
{
    $encoded = json_encode($inputSelector, JSON_THROW_ON_ERROR);

    return "(() => { const input = document.querySelector({$encoded}); const toggle = input.parentElement.querySelector('button'); const inputRect = input.getBoundingClientRect(); const toggleRect = toggle.getBoundingClientRect(); const centreOffset = (toggleRect.top + toggleRect.height / 2) - (inputRect.top + inputRect.height / 2); const rightOffset = toggleRect.right - inputRect.right; const inside = toggleRect.top >= inputRect.top - 1 && toggleRect.bottom <= inputRect.bottom + 1; return Math.abs(centreOffset) <= 1 && Math.abs(rightOffset) <= 1 && inside ? '' : `centre \${centreOffset.toFixed(1)} px, right \${rightOffset.toFixed(1)} px, toggle \${Math.round(toggleRect.top)}..\${Math.round(toggleRect.bottom)}, input \${Math.round(inputRect.top)}..\${Math.round(inputRect.bottom)}`; })()";
}

test('the password toggle is centred on an input with a top margin on desktop', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('password.confirm', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(passwordInputToggleOffsetScript('#password'), '');
});

test('the password toggle sits at the right edge of a width-capped input on desktop', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('profile.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(passwordInputToggleOffsetScript('#emby-password'), '');
});
