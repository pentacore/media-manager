<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiMode;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderConnectionException;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.ai.enabled', true);
    Bus::fake([GenerateConversationTitle::class]);
});

/**
 * Exhausts the chat model's one-request-per-minute limit, with no failover
 * provider to take the turn.
 */
function chatPreflightExhaustRateLimit(): void
{
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    $model = resolve(AiSettings::class)->chatSelection()->model;

    $aiModelPrice = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model]);
    $aiModelPrice->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);

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

/**
 * A conversation row the user owns, archived.
 */
function chatPreflightArchivedConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Example archived chat',
        'archived_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

test('send answers an unreachable provider with a 503 and the friendly sentence', function (): void {
    MediaAgent::fake(fn (): never => throw new ProviderConnectionException('connect timeout'));

    $testResponse = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'hi'])
        ->assertServiceUnavailable();

    expect($testResponse->json('error'))->toBe('provider_unreachable')
        ->and($testResponse->json('message'))->toBe("Couldn't reach the AI provider. Please try again in a moment.");
});

test('send and stream refuse an exhausted rate limit before looking at the conversation', function (string $routeName): void {
    chatPreflightExhaustRateLimit();
    MediaAgent::fake(['never']);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route($routeName), ['message' => 'hi', 'conversation_id' => (string) Str::uuid7()])
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'rate_limited');

    MediaAgent::assertNeverPrompted();
})->with(['ai.chat.send', 'ai.chat.stream']);

test('send and stream answer an archived conversation with 404 before the agent runs', function (string $routeName): void {
    MediaAgent::fake(['never']);
    $admin = User::factory()->admin()->create();
    $conversationId = chatPreflightArchivedConversation($admin);

    $this->actingAs($admin)
        ->postJson(route($routeName), ['message' => 'hi', 'conversation_id' => $conversationId])
        ->assertNotFound()
        ->assertJsonPath('message', 'Conversation not found.');

    MediaAgent::assertNeverPrompted();
})->with(['ai.chat.send', 'ai.chat.stream']);

test('a mode requested by one turn does not carry into the next request', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Executive);
    MediaAgent::fake(fn (): string => resolve(AiSettings::class)->mode()->value);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'first', 'mode' => 'advisory'])
        ->assertOk()
        ->assertJsonPath('text', 'advisory');

    // Mirrors Octane's per-request flush of scoped instances; tests/Pest.php
    // registers that flush for Browser tests only.
    $this->app->forgetScopedInstances();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'second'])
        ->assertOk()
        ->assertJsonPath('text', 'executive');
});

test('the pending workflow endpoint answers an archived conversation with 404', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = chatPreflightArchivedConversation($admin);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => $conversationId]))
        ->assertNotFound()
        ->assertJsonPath('message', 'Conversation not found.');
});
