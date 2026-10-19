<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Holds back the page's fetch of one drill-down URL for $milliseconds, as a
 * slow server would, and honours an abort signal while it waits.
 */
function aiUsageDetailDelayScript(string $path, int $milliseconds): string
{
    return <<<JS
        (() => {
            const original = window.fetch.bind(window);
            window.fetch = (input, init = {}) => {
                const url = new URL(typeof input === 'string' ? input : input.url, window.location.origin);
                if (url.pathname !== '{$path}') {
                    return original(input, init);
                }
                return new Promise((resolve, reject) => {
                    const timer = setTimeout(() => original(input, init).then(resolve, reject), {$milliseconds});
                    init.signal?.addEventListener('abort', () => {
                        clearTimeout(timer);
                        reject(new DOMException('The operation was aborted.', 'AbortError'));
                    });
                });
            };
        })()
    JS;
}

/**
 * Rests inside the page past the held-back answer.
 */
function aiUsageDetailRestScript(): string
{
    return '(async () => { await new Promise((resolve) => setTimeout(resolve, 2000)); })()';
}

test('closing the drill-down while it loads closes it, and the late answer does not reopen it', function (): void {
    $record = AiUsageRecord::factory()->create(['prompt_text' => 'Find the example series.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(aiUsageDetailDelayScript(route('admin.ai-usage.show', ['aiUsageRecord' => $record->id], absolute: false), 1500));

    $webpage->click(sprintf('[data-usage-row="%d"]', $record->id))
        ->assertSeeIn('[data-usage-detail]', 'Loading…')
        ->click('[data-usage-detail] [data-slot="dialog-close"]');
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[data-usage-detail]')"));
    $webpage->script(aiUsageDetailRestScript());

    $webpage->assertMissing('[data-usage-detail]')
        ->assertNoSmoke();
});

test('a run opened after closing a slow one shows only its own detail', function (): void {
    $slow = AiUsageRecord::factory()->create(['prompt_text' => 'The slow example question.']);
    $fast = AiUsageRecord::factory()->create(['prompt_text' => 'The fast example question.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(aiUsageDetailDelayScript(route('admin.ai-usage.show', ['aiUsageRecord' => $slow->id], absolute: false), 1500));

    $webpage->click(sprintf('[data-usage-row="%d"]', $slow->id))
        ->assertSeeIn('[data-usage-detail]', 'Loading…')
        ->click('[data-usage-detail] [data-slot="dialog-close"]');
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[data-usage-detail]')"));
    $webpage->click(sprintf('[data-usage-row="%d"]', $fast->id))
        ->assertSeeIn('[data-usage-io="input"]', 'The fast example question.');
    $webpage->script(aiUsageDetailRestScript());

    $webpage->assertSeeIn('[data-usage-io="input"]', 'The fast example question.')
        ->assertDontSeeIn('[data-usage-io="input"]', 'The slow example question.')
        ->assertNoSmoke();
});

/**
 * Holds back the page's XHR to one path for $milliseconds, as a slow server
 * would. Inertia sends its visits (router.post included) over XMLHttpRequest.
 */
function aiUsageDetailDelayVisitScript(string $path, int $milliseconds): string
{
    return <<<JS
        (() => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__aiUsageDetailPath = new URL(url, window.location.origin).pathname;
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (...args) {
                if (this.__aiUsageDetailPath === '{$path}') {
                    setTimeout(() => send.apply(this, args), {$milliseconds});
                    return;
                }
                return send.apply(this, args);
            };
        })()
    JS;
}

/**
 * Polls the page for $text, inside one round trip, and reports whether it
 * ever appeared. A toast dismisses itself a few seconds after it is shown,
 * and this suite's round trips to the browser are slow enough that a second
 * one, right after confirming the toast is up, can lose the race with its
 * own dismissal; capturing "was it ever shown" in a single call sidesteps
 * that without weakening the assertion.
 */
function aiUsageDetailTextWasSeenScript(string $text): string
{
    return <<<JS
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if (document.body.innerText.includes('{$text}')) {
                    return true;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            return false;
        })()
    JS;
}

/**
 * Picks the catalog price for gpt-5-mini in the open drill-down and submits it.
 */
function aiUsageDetailAssign(AwaitableWebpage $webpage): void
{
    $webpage->assertSeeIn('[data-usage-assign]', 'Assign price from catalog')
        ->click('[data-usage-assign-select]')
        ->click('[role="option"]:has-text("openai / gpt-5-mini")')
        ->click('[data-usage-assign-submit]');
}

test('closing the drill-down while a price assignment saves keeps it closed', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1.00, 'output_per_mtok' => 2.00]);
    $aiUsageRecord = AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'mystery-model']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(aiUsageDetailDelayVisitScript(route('admin.ai-usage.assign-price', ['aiUsageRecord' => $aiUsageRecord->id], absolute: false), 1500));

    $webpage->click(sprintf('[data-usage-row="%d"]', $aiUsageRecord->id));
    aiUsageDetailAssign($webpage);
    $webpage->click('[data-usage-detail-close]');
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[data-usage-detail]')"));

    $toastSeen = $webpage->script(aiUsageDetailTextWasSeenScript('Pricing assigned from openai/gpt-5-mini.'));

    expect($toastSeen)->toBeTrue('Expected the price-assigned toast to appear.');
    $webpage->assertMissing('[data-usage-detail]')
        ->assertNoSmoke();

    expect($aiUsageRecord->fresh()->price_source)->toBe('assigned');
});

test('a price assignment that lands after another run was opened leaves that run on screen', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1.00, 'output_per_mtok' => 2.00]);
    $unpriced = AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'mystery-model', 'prompt_text' => 'The unpriced example question.']);
    $other = AiUsageRecord::factory()->create(['prompt_text' => 'The other example question.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(aiUsageDetailDelayVisitScript(route('admin.ai-usage.assign-price', ['aiUsageRecord' => $unpriced->id], absolute: false), 1500));

    $webpage->click(sprintf('[data-usage-row="%d"]', $unpriced->id));
    aiUsageDetailAssign($webpage);
    $webpage->click('[data-usage-detail-close]');
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[data-usage-detail]')"));
    $webpage->click(sprintf('[data-usage-row="%d"]', $other->id))
        ->assertSeeIn('[data-usage-io="input"]', 'The other example question.');
    $toastSeen = $webpage->script(aiUsageDetailTextWasSeenScript('Pricing assigned from openai/gpt-5-mini.'));

    expect($toastSeen)->toBeTrue('Expected the price-assigned toast to appear.');
    $webpage->assertSeeIn('[data-usage-io="input"]', 'The other example question.')
        ->assertDontSeeIn('[data-usage-io="input"]', 'The unpriced example question.')
        ->assertNoSmoke();
});
