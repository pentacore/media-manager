<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

/*
 * Functional browser coverage for the admin AI prices screen
 * (resources/js/pages/Admin/AiPrices/Index.vue).
 *
 * REFRESH OUTCOME TOASTS (success / partial / failure / legacy):
 *
 * The enriched "Price refresh complete", "partially completed" (incl. fallback
 * provider names), "failed" and legacy success toasts are driven by the
 * `AiPriceRefreshStateChanged` broadcast handled by `handleRefreshState()` in
 * Index.vue. The default test env uses the "null" broadcaster with no Reverb
 * broker (see tests/Browser/RealtimeFlowsTest.php), so nothing is delivered
 * over a socket. But Echo's Reverb driver still boots pusher-js client-side
 * (window.Pusher) and `onMounted` registers the page's listener on the
 * client-side channel object. pusher-js Channels extend an EventsDispatcher,
 * so we look the subscribed channel up by name and `.emit()` the payload
 * straight into the bound callback — exactly what a delivered socket frame
 * does — driving the REAL handler end-to-end without a broker. See
 * emitPriceRefreshState() below.
 *
 * Backend payload shape is separately proven in
 * tests/Feature/Jobs/RefreshAiPricesJobTest.php and
 * tests/Feature/AI/Pricing/AiPriceRefreshCoordinatorTest.php; dispatch/locking
 * in tests/Feature/Admin/AiModelPriceControllerTest.php.
 */

/**
 * Fires the client-side `AiPriceRefreshStateChanged` handler by emitting the
 * payload directly on the pusher-js channel the page subscribed to. Returns a
 * status string so the test can assert the handler was actually reachable
 * (`EMITTED`) rather than silently no-op on a missing channel/callback.
 *
 * @param  array<string, mixed>  $payload
 */
function emitPriceRefreshState(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const pusher = window.Pusher
                && window.Pusher.instances
                && window.Pusher.instances[0];
            if (!pusher) { return 'NO_PUSHER'; }
            const channel = pusher.channels.channels['private-admin.ai-prices'];
            if (!channel) { return 'NO_CHANNEL'; }
            const bound = channel.callbacks._callbacks['_AiPriceRefreshStateChanged'];
            if (!bound || bound.length === 0) { return 'NO_CALLBACK'; }
            channel.emit('AiPriceRefreshStateChanged', {$json});
            return 'EMITTED';
        })()
    JS;
}

/**
 * A fully enriched succeeded payload. Individual tests override the fields
 * that matter to the branch under test.
 *
 * @return array<string, mixed>
 */
function enrichedRefreshPayload(): array
{
    return [
        'state' => 'succeeded',
        'triggered_by' => null,
        'summary' => null,
        'error' => null,
        'added' => null,
        'total' => null,
        'occurred_at' => now()->toIso8601String(),
        'run_id' => 1,
        'final_result' => 'succeeded',
        'models_dev_status' => 'ok',
        'providers_requested' => 2,
        'providers_succeeded' => 2,
        'providers_failed' => 0,
        'models_created' => 3,
        'models_updated' => 5,
        'models_unchanged' => 1,
        'models_locked' => 2,
        'models_rejected' => 0,
        'models_tiered' => 0,
        'fallback_providers' => null,
        'error_message' => null,
    ];
}

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());
});

test('source and automatic-update metadata render for a synced row', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.25,
        'output_per_mtok' => 2.00,
        'pricing_source' => PricingSource::ModelsDev,
        'pricing_source_url' => 'https://models.dev/openai/gpt-5-mini',
        'pricing_synced_at' => now()->subHours(2),
        'pricing_verified_at' => now()->subHours(3),
        'is_price_locked' => false,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSee('gpt-5-mini')
        ->assertSee('Models.dev')
        ->assertSee('Auto-updates on')
        ->assertSee('2h ago')
        ->assertSee('3h ago');
});

test('clicking refresh shows the in-progress state', function (): void {
    // The refresh job reaches out to live provider pricing pages, so fake it:
    // dispatch is recorded but never runs, leaving the cache lock held so the
    // reloaded page reports the refresh as still running.
    Bus::fake([RefreshAiPricesJob::class]);

    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertButtonEnabled('Refresh online')
        ->click('Refresh online')
        ->assertSee('Price refresh queued. Updates will appear automatically.')
        ->assertButtonDisabled('Refresh online');

    Bus::assertDispatched(RefreshAiPricesJob::class);
    expect(RefreshAiPricesJob::isRunning())->toBeTrue();
});

test('a manual price edit locks the row and disables automatic updates', function (): void {
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.25,
        'output_per_mtok' => 2.00,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSee('Auto-updates on')
        ->click('Edit')
        ->assertSee('Edit openai / gpt-5-mini')
        // Turn automatic updates off, then change a rate: together these take
        // the row under manual control.
        ->click('On — kept in sync online')
        ->fill('input_per_mtok', '5.0000')
        ->click('Save')
        ->assertSee('Model price updated.')
        ->assertSee('Locked')
        ->assertSee('Manual');

    $price->refresh();

    expect($price->is_price_locked)->toBeTrue();
    expect($price->pricing_source)->toBe(PricingSource::Manual);
    expect((float) $price->input_per_mtok)->toBe(5.0);
});

test('admin can re-enable automatic updates on a locked row', function (): void {
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 5.00,
        'output_per_mtok' => 10.00,
        'pricing_source' => PricingSource::Manual,
        'is_price_locked' => true,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSee('Locked')
        ->click('Edit')
        ->assertSee('Edit openai / gpt-5-mini')
        // Flip the toggle from off → on and save; this unlocks the row.
        ->click('Off — locked to manual price')
        ->click('Save')
        ->assertSee('Model price updated.')
        ->assertSee('Auto-updates on');

    $price->refresh();

    expect($price->is_price_locked)->toBeFalse();
    expect($price->automatic_updates_enabled)->toBeTrue();
});

test('editing a rate without touching the toggle locks an unlocked row', function (): void {
    // Regression: the edit dialog prefills the toggle from the row, so an
    // unlocked row (auto-updates on) starts with the toggle on. Editing a rate
    // WITHOUT touching the toggle must omit the hidden field so the backend's
    // price-change rule locks the row — it must not read the prefilled toggle
    // as an explicit re-enable.
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.25,
        'output_per_mtok' => 2.00,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSee('Auto-updates on')
        ->click('Edit')
        ->assertSee('Edit openai / gpt-5-mini')
        // Change a rate but leave the automatic-updates toggle untouched.
        ->fill('input_per_mtok', '5.0000')
        ->click('Save')
        ->assertSee('Model price updated.')
        ->assertSee('Locked')
        ->assertSee('Manual');

    $price->refresh();

    expect($price->is_price_locked)->toBeTrue();
    expect($price->pricing_source)->toBe(PricingSource::Manual);
    expect((float) $price->input_per_mtok)->toBe(5.0);
});

test('editing a rate while flipping the toggle on keeps an unlocked row unlocked', function (): void {
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.25,
        'output_per_mtok' => 2.00,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSee('Auto-updates on')
        ->click('Edit')
        ->assertSee('Edit openai / gpt-5-mini')
        ->fill('input_per_mtok', '5.0000')
        // Interact with the toggle (off then back on) so the hidden field is
        // submitted as an explicit re-enable. This must override the
        // price-change lock and keep the row unlocked.
        ->click('On — kept in sync online')
        ->click('Off — locked to manual price')
        ->click('Save')
        ->assertSee('Model price updated.')
        ->assertSee('Auto-updates on');

    $price->refresh();

    expect($price->is_price_locked)->toBeFalse();
    expect($price->automatic_updates_enabled)->toBeTrue();
    expect((float) $price->input_per_mtok)->toBe(5.0);
});

test('a succeeded broadcast shows the success toast and reloads prices', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    $page = visit('/admin/ai-prices');
    $page->assertNoSmoke()->assertSee('gpt-5-mini');

    // Created after the initial render: it only appears if the success handler
    // triggers router.reload({ only: ['prices'] }).
    AiModelPrice::factory()->create([
        'provider' => 'anthropic',
        'model' => 'reload-proof-model',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    expect($page->script(emitPriceRefreshState(enrichedRefreshPayload())))
        ->toBe('EMITTED');

    $page->assertSee('Price refresh complete')
        ->assertSee('3 created, 5 updated, 2 locked, 0 rejected')
        ->assertSee('reload-proof-model');
});

test('a partial broadcast shows the warning toast with counts and fallback providers', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    $payload = array_merge(enrichedRefreshPayload(), [
        'final_result' => 'partial',
        'fallback_providers' => ['openai', 'anthropic'],
        'models_created' => 1,
        'models_updated' => 2,
        'models_locked' => 0,
        'models_rejected' => 1,
    ]);

    $page = visit('/admin/ai-prices');
    $page->assertNoSmoke()->assertSee('gpt-5-mini');

    expect($page->script(emitPriceRefreshState($payload)))->toBe('EMITTED');

    $page->assertSee('Price refresh partially completed')
        ->assertSee('1 created, 2 updated, 0 locked, 1 rejected')
        ->assertSee('Fallback: openai, anthropic');
});

test('a failed broadcast shows the error toast with the error message', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    $payload = array_merge(enrichedRefreshPayload(), [
        'state' => 'failed',
        'final_result' => 'failed',
        'error_message' => 'models.dev unreachable',
    ]);

    $page = visit('/admin/ai-prices');
    $page->assertNoSmoke()->assertSee('gpt-5-mini');

    expect($page->script(emitPriceRefreshState($payload)))->toBe('EMITTED');

    $page->assertSee('Price refresh failed')
        ->assertSee('models.dev unreachable');
});

test('a legacy broadcast (no final_result) shows the old added/total success toast', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'pricing_source' => PricingSource::ModelsDev,
    ]);

    $payload = array_merge(enrichedRefreshPayload(), [
        'state' => 'succeeded',
        'final_result' => null,
        'added' => 4,
        'total' => 12,
    ]);

    $page = visit('/admin/ai-prices');
    $page->assertNoSmoke()->assertSee('gpt-5-mini');

    expect($page->script(emitPriceRefreshState($payload)))->toBe('EMITTED');

    $page->assertSee('Price refresh complete')
        ->assertSee('4 new, 12 total');
});
