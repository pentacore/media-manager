<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Storage\DatabaseConversationStore;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-ant-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();
});

/**
 * Insert a conversation owned by $user carrying the given saved override.
 *
 * @param  array{provider?: string|null, model?: string|null, reasoning?: string|null}  $override
 */
function overrideConversation(User $user, array $override = []): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Test',
        'model_provider' => $override['provider'] ?? null,
        'model' => $override['model'] ?? null,
        'reasoning' => $override['reasoning'] ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Exhaust a one-request-per-minute limit on the given (already priced) chat model.
 */
function exhaustOverrideRateLimit(string $provider, string $model): void
{
    resolve(AiSettings::class)->setRateLimitsEnforced(true);

    $price = AiModelPrice::query()->where('provider', $provider)->where('model', $model)->firstOrFail();
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => 'TestAgent',
        'provider' => $provider,
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

test('a new conversation runs on the requested override and keeps it', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(route('ai.chat.send'), [
        'message' => 'Hi',
        'override' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'reasoning' => 'high'],
    ])->assertOk();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'claude-opus-5-5');

    $row = DB::table('agent_conversations')->where('id', $response->json('conversation_id'))->first();

    expect([$row->model_provider, $row->model, $row->reasoning])->toBe(['anthropic', 'claude-opus-5-5', 'high']);
});

test('a new conversation without an override keeps the admin defaults', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi'])->assertOk();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.6-luna');

    $row = DB::table('agent_conversations')->where('id', $response->json('conversation_id'))->first();

    expect([$row->model_provider, $row->model, $row->reasoning])->toBe([null, null, null]);
});

test('an existing conversation uses its saved override and ignores request fields', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5']);

    $this->actingAs($admin)->postJson(route('ai.chat.send'), [
        'message' => 'Hi',
        'conversation_id' => $id,
        'override' => ['provider' => 'openai', 'model' => 'gpt-5.6-luna'],
    ])->assertOk();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'claude-opus-5-5');
});

test('a requested override model must be priced under its provider', function (): void {
    $this->actingAs(User::factory()->admin()->create())->postJson(route('ai.chat.send'), [
        'message' => 'Hi',
        'override' => ['provider' => 'anthropic', 'model' => 'gpt-5.6-luna'],
    ])->assertUnprocessable()->assertJsonValidationErrors('override.model');
});

test('a requested override rejects an unknown reasoning level', function (): void {
    $this->actingAs(User::factory()->admin()->create())->postJson(route('ai.chat.stream'), [
        'message' => 'Hi',
        'override' => ['reasoning' => 'extreme'],
    ])->assertUnprocessable()->assertJsonValidationErrors('override.reasoning');
});

test('an override whose price row was deleted still runs and still shows', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5']);
    AiModelPrice::query()->where('model', 'claude-opus-5-5')->delete();

    $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi', 'conversation_id' => $id])->assertOk();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'claude-opus-5-5');

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $id))
        ->assertJsonPath('override.model', 'claude-opus-5-5');
});

test('a streamed new conversation runs on the requested override and keeps it', function (): void {
    MediaAgent::fake(['Hello from the stream.']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('ai.chat.stream'), [
        'message' => 'Hi',
        'override' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'reasoning' => 'low'],
    ], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'claude-opus-5-5');

    $row = DB::table('agent_conversations')->where('participant_id', $admin->id)->sole();

    expect([$row->model_provider, $row->model, $row->reasoning])->toBe(['anthropic', 'claude-opus-5-5', 'low']);
});

test('a streamed new conversation whose first turn fails still keeps the override', function (): void {
    // 1.0 stores a failed turn only once a step completed, so fail on step 2.
    MediaAgent::fake([
        new ToolCall(id: 'call-1', name: 'GetServiceStatusTool', arguments: []),
        fn (): never => throw new RuntimeException('boom'),
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('ai.chat.stream'), [
        'message' => 'Hi',
        'override' => ['provider' => null, 'model' => null, 'reasoning' => 'xhigh'],
    ], ['Accept' => 'text/event-stream'])->streamedContent();

    $row = DB::table('agent_conversations')->where('participant_id', $admin->id)->sole();

    expect([$row->model_provider, $row->model, $row->reasoning])->toBe([null, null, 'xhigh']);
});

test('the owner can change and clear the override', function (): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin);

    $this->actingAs($admin)->patchJson(route('ai.conversations.model', $id), [
        'provider' => null, 'model' => null, 'reasoning' => 'xhigh',
    ])->assertOk()
        ->assertJsonPath('override.reasoning', 'xhigh')
        ->assertJsonPath('resolved.model', 'gpt-5.6-luna')
        ->assertJsonPath('resolved.reasoning', 'xhigh');

    $this->actingAs($admin)->patchJson(route('ai.conversations.model', $id), [
        'provider' => null, 'model' => null, 'reasoning' => null,
    ])->assertOk()->assertJsonPath('resolved.reasoning', 'medium');

    expect(DB::table('agent_conversations')->where('id', $id)->value('reasoning'))->toBeNull();
});

test('the owner can switch the conversation to another priced model', function (): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin);

    $this->actingAs($admin)->patchJson(route('ai.conversations.model', $id), [
        'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'reasoning' => null,
    ])->assertOk()
        ->assertJsonPath('override.provider', 'anthropic')
        ->assertJsonPath('override.model', 'claude-opus-5-5')
        ->assertJsonPath('resolved.provider', 'anthropic')
        ->assertJsonPath('resolved.model', 'claude-opus-5-5')
        ->assertJsonPath('resolved.reasoning', 'medium')
        ->assertJsonPath('resolved.reasoning_label', 'Medium');

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $id))
        ->assertJsonPath('override.provider', 'anthropic')
        ->assertJsonPath('override.model', 'claude-opus-5-5')
        ->assertJsonPath('override.reasoning', null);
});

test('changing the override validates the body', function (array $body, string $field): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin);

    $this->actingAs($admin)->patchJson(route('ai.conversations.model', $id), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unpriced model' => [['provider' => 'anthropic', 'model' => 'gpt-5.6-luna', 'reasoning' => null], 'model'],
    'model without provider' => [['provider' => null, 'model' => 'gpt-5.6-luna', 'reasoning' => null], 'provider'],
    'unknown reasoning' => [['provider' => null, 'model' => null, 'reasoning' => 'extreme'], 'reasoning'],
    'missing reasoning' => [['provider' => null, 'model' => null], 'reasoning'],
]);

test('another user cannot change the override', function (): void {
    $id = overrideConversation(User::factory()->admin()->create());

    $this->actingAs(User::factory()->admin()->create())
        ->patchJson(route('ai.conversations.model', $id), ['provider' => null, 'model' => null, 'reasoning' => 'low'])
        ->assertNotFound();
});

test('the override is locked while tool approvals are pending', function (): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin);

    $store = Mockery::mock(DatabaseConversationStore::class)->makePartial();
    $store->shouldReceive('pendingApprovalsFor')->with($id)->andReturn([['id' => 'call_1']]);
    $store->shouldReceive('conversationBelongsTo')->andReturnTrue();
    app()->instance(ConversationStore::class, $store);

    $this->actingAs($admin)
        ->patchJson(route('ai.conversations.model', $id), ['provider' => null, 'model' => null, 'reasoning' => 'low'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'pending_approval');

    expect(DB::table('agent_conversations')->where('id', $id)->value('reasoning'))->toBeNull();
});

test('the rate limit checks the overridden model', function (): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5']);
    exhaustOverrideRateLimit('anthropic', 'claude-opus-5-5');

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'Hi', 'conversation_id' => $id])
        ->assertStatus(429);
});

test('the picker options expose the admin defaults', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.model-options'))
        ->assertOk()
        ->assertJsonPath('defaults.model', 'gpt-5.6-luna')
        ->assertJsonPath('defaults.reasoning', 'medium')
        ->assertJsonPath('defaults.reasoning_label', 'Medium')
        ->assertJsonStructure(['models', 'reasoningLevels', 'modelCapabilities', 'reasoningProviders']);
});

test('non-admins cannot read the picker options or change the override', function (): void {
    $admin = User::factory()->admin()->create();
    $id = overrideConversation($admin);
    $member = User::factory()->member()->create();

    $this->actingAs($member)->getJson(route('ai.chat.model-options'))->assertForbidden();
    $this->actingAs($member)
        ->patchJson(route('ai.conversations.model', $id), ['provider' => null, 'model' => null, 'reasoning' => 'low'])
        ->assertForbidden();
});
