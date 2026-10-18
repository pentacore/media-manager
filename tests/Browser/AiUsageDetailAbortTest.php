<?php

declare(strict_types=1);

use App\Models\AiUsageRecord;
use App\Models\User;

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
