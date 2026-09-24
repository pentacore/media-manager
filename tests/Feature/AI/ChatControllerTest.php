<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.ai.enabled', true);
});

test('returns 404 when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('ai.chat'))->assertNotFound();
});

test('guests are redirected to login', function (): void {
    $this->get(route('ai.chat'))->assertRedirect(route('login'));
});

test('non-admins cannot access AI chat', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('ai.chat'))->assertForbidden();

    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('ai.chat'))->assertForbidden();
});

test('admin can view chat page', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('ai.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('AI/Chat'));
});

test('admin can send a message to MediaAgent', function (): void {
    MediaAgent::fake(['Action queued.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
        ])
        ->assertOk();

    expect($response->json('text'))->toBe('Action queued.');
});

test('admin cannot continue another users AI conversation', function (): void {
    MediaAgent::fake(['Action queued.']);
    $owner = User::factory()->admin()->create();
    $admin = User::factory()->admin()->create();

    DB::table('agent_conversations')->insert([
        'id' => '018f7cf5-3b26-72c8-93e5-6dc5b44f2472',
        'participant_type' => User::class,
        'participant_id' => $owner->id,
        'title' => 'Private conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Continue this',
            'conversation_id' => '018f7cf5-3b26-72c8-93e5-6dc5b44f2472',
        ])
        ->assertNotFound();

    MediaAgent::assertNeverPrompted();
});

test('message is validated as required', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('exception messages are not leaked to the client outside local env', function (): void {
    Log::spy();
    $this->app['env'] = 'production';
    $this->withoutMiddleware(PreventRequestForgery::class);

    MediaAgent::fake(fn (): never => throw new RuntimeException('LEAKED-PROVIDER-SECRET-https://api.example.com?token=abc'));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
        ])
        ->assertStatus(500);

    expect($response->json('error'))->toBe('AI request failed.');
    expect($response->json('message'))->toBeNull();
    expect($response->getContent())->not->toContain('LEAKED-PROVIDER-SECRET');

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'AI request failed.'
            && ($context['user_id'] ?? null) === $admin->id
            && str_contains((string) ($context['message'] ?? ''), 'LEAKED-PROVIDER-SECRET')
    );
});

test('first turn dispatches GenerateConversationTitle and seeds fallback title', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['ok']);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Tell me about my queue please',
        ])
        ->assertOk();

    $conversationId = $response->json('conversation_id');
    expect($conversationId)->toBeString();

    Bus::assertDispatched(fn (GenerateConversationTitle $generateConversationTitle): bool => $generateConversationTitle->conversationId === $conversationId
        && $generateConversationTitle->firstUserMessage === 'Tell me about my queue please');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->value('title'))
        ->toBe('Tell me about my queue please');
});

test('subsequent turns do not dispatch GenerateConversationTitle', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['ok']);
    $admin = User::factory()->admin()->create();
    $existing = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $existing,
        'participant_type' => User::class,
        'participant_id' => $admin->id,
        'title' => 'Existing convo',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'follow-up',
            'conversation_id' => $existing,
        ])
        ->assertOk();

    Bus::assertNotDispatched(GenerateConversationTitle::class);
});

test('exception messages are surfaced to the client in local env', function (): void {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);

    MediaAgent::fake(fn (): never => throw new RuntimeException('LOCAL-DEBUG-DETAIL'));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
        ])
        ->assertStatus(500);

    expect($response->json('error'))->toBe('AI request failed.');
    expect($response->json('message'))->toBe('LOCAL-DEBUG-DETAIL');
});

/**
 * Exhaust a one-request-per-minute limit on the configured chat model.
 */
function exhaustChatModelRateLimit(): void
{
    resolve(AiSettings::class)->setRateLimitsEnforced(true);

    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => resolve(AiSettings::class)->model()]);
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => resolve(AiSettings::class)->model(),
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

test('send refuses with 429 when the chat model has exhausted its rate limit and no failover is configured', function (): void {
    exhaustChatModelRateLimit();
    MediaAgent::fake(['Should never run.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'hi'])
        ->assertStatus(429);

    expect($response->json('error'))->toBe('rate_limited')
        ->and($response->json('message'))->toContain('Rate limit reached for openai/');

    expect($response->getContent())->not->toContain('Should never run.');
});

test('send lets an exhausted primary through when a failover provider can take the turn', function (): void {
    exhaustChatModelRateLimit();
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    MediaAgent::fake(['Served by the failover.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'hi'])
        ->assertOk();

    expect($response->json('text'))->toBe('Served by the failover.');
});

test('send maps a rate limit raised during the run to 429 instead of a generic failure', function (): void {
    MediaAgent::fake(fn (): never => throw RateLimitedException::forProvider('openai'));
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'hi'])
        ->assertStatus(429);

    expect($response->json('error'))->toBe('rate_limited')
        ->and($response->json('message'))->toContain('rate limited');
});
