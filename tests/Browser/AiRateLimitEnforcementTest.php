<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * A one-request-per-minute limit on the chat model, already used up.
 */
function exhaustChatModelLimit(): void
{
    $model = resolve(AiSettings::class)->model();
    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model]);
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => $model,
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('admin can switch rate limit enforcement on from the AI settings page', function (): void {
    // The model select is fed by the pricing catalog; the form is invalid
    // without a row for the configured chat model.
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => resolve(AiSettings::class)->model()]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rate-limits-toggle]', 'Informational')
        ->click('[data-rate-limits-toggle]')
        ->assertSeeIn('[data-rate-limits-toggle]', 'Enforced')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertSeeIn('[data-rate-limits-toggle]', 'Enforced');

    expect(resolve(AiSettings::class)->rateLimitsEnforced())->toBeTrue();
});

test('the AI usage rate limit card says limits are informational while enforcement is off', function (): void {
    exhaustChatModelLimit();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rate-limits-mode]', 'Informational only')
        ->assertMissing('[data-rate-limit-blocked]');
});

test('the AI usage rate limit card flags an exhausted limit as blocking while enforcement is on', function (): void {
    exhaustChatModelLimit();
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rate-limits-mode]', 'Enforced')
        ->assertSeeIn('[data-rate-limit-blocked]', 'Blocked');
});

test('chat explains the exhausted rate limit instead of a generic failure', function (): void {
    exhaustChatModelLimit();
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    MediaAgent::fake(['Should never run.']);
    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'What is on my watchlist?')
        ->click('Send')
        ->assertSee('Rate limit reached for openai/')
        ->assertDontSee('Should never run.');
});
