<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * The measured height of a bar, rounded as `--bulk-bar-height` rounds it.
 */
function bulkBarHeightOf(string $bar): string
{
    return sprintf('Math.round(document.querySelector(\'[data-test="%s"]\').getBoundingClientRect().height)', $bar);
}

function bulkBarPublishedHeightScript(): string
{
    return 'parseFloat(document.body.style.getPropertyValue("--bulk-bar-height") || "0")';
}

test('two open bulk bars publish the taller height, and the survivor takes over when one closes', function (): void {
    // No product page shows two bars; the fixture page mounts two.
    Route::middleware(['web', 'auth'])->get('bulk-bar-height-fixture', fn () => Inertia::render('Testing/BulkBars'));
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit('/bulk-bar-height-fixture')->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // Both open, the tall one opened last: the page end clears the tall one.
    $webpage->click('[data-test="first-toggle"]')
        ->click('[data-test="second-toggle"]');
    $webpage->script(confirmDialogWaitUntilScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('second-bar')));
    $webpage->assertScript('document.body.dataset.bulkBarOpen + "/" + document.querySelectorAll("[data-bulk-bar]").length', '2/2')
        ->assertScript(bulkBarHeightOf('second-bar').' > '.bulkBarHeightOf('first-bar'), true)
        ->assertScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('second-bar'), true);

    // The tall bar closes while the short one stays: the height follows the
    // short one instead of staying at the closed bar's.
    $webpage->click('[data-test="second-toggle"]');
    $webpage->script(confirmDialogWaitUntilScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('first-bar')));
    $webpage->assertScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('first-bar'), true)
        ->assertScript('document.body.dataset.bulkBarOpen', '1');

    // The tall bar opens again over the short one: back to its height.
    $webpage->click('[data-test="second-toggle"]');
    $webpage->script(confirmDialogWaitUntilScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('second-bar')));
    $webpage->assertScript(bulkBarPublishedHeightScript().' === '.bulkBarHeightOf('second-bar'), true);

    // The last bar out drops the height and the flag.
    $webpage->click('[data-test="first-toggle"]')
        ->click('[data-test="second-toggle"]');
    $webpage->script(confirmDialogWaitUntilScript('!document.body.hasAttribute("data-bulk-bar-open")'));
    $webpage->assertScript('document.body.style.getPropertyValue("--bulk-bar-height")', '')
        ->assertNoSmoke();
});
