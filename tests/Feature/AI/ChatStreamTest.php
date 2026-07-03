<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('admin can stream a chat response as SSE', function (): void {
    MediaAgent::fake(['Hello from the stream.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Say hello',
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/event-stream');

    $body = $response->streamedContent();
    // The fake gateway streams the response one space-delimited word per
    // text_delta event, so assert on the first word to prove the faked text
    // actually reached the SSE body rather than just the framing.
    expect($body)->toContain('data:')
        ->and($body)->toContain('text_delta')
        ->and($body)->toContain('Hello')
        ->and($body)->toContain('[DONE]');
});

test('streaming a first turn appends the minted conversation id before DONE', function (): void {
    MediaAgent::fake(['Hello from the stream.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Say hello',
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();

    $body = $response->streamedContent();

    $conversationId = DB::table('agent_conversations')
        ->where('user_id', $admin->id)
        ->value('id');

    expect($conversationId)->not->toBeNull()
        ->and($body)->toContain('"type":"conversation_id"')
        ->and($body)->toContain($conversationId);

    // The id event must precede the terminal [DONE] marker.
    expect(strpos($body, $conversationId))
        ->toBeLessThan(strpos($body, '[DONE]'));
});

test('streaming an existing conversation echoes its id in the terminal event', function (): void {
    MediaAgent::fake(['Continuing.']);
    $admin = User::factory()->admin()->create();

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $admin->id,
        'title' => 'Existing conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Continue this',
            'conversation_id' => $conversationId,
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();

    expect($response->streamedContent())
        ->toContain('"type":"conversation_id"')
        ->and($response->streamedContent())->toContain($conversationId);
});

test('streaming endpoint enforces budget guard', function (): void {
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'test-model',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 2.0,
    ]);

    resolve(AiSettings::class)->setHardBudgetUsd(1.0);

    // 1M input * $1 + 1M output * $2 = $3 — over the $1 cap.
    AiUsageRecord::create([
        'invocation_id' => 'test-'.bin2hex(random_bytes(8)),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => 'test-model',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 1_000_000,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'completed',
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.stream'), ['message' => 'hi'])
        ->assertStatus(402);
});

test('streaming endpoint validates message', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.stream'), [])
        ->assertJsonValidationErrors(['message']);
});

test('non-admins cannot stream', function (): void {
    $viewer = User::factory()->create(); // default role viewer

    $this->actingAs($viewer)
        ->postJson(route('ai.chat.stream'), ['message' => 'hi'])
        ->assertForbidden();
});

test('streaming a first turn seeds a fallback title and dispatches GenerateConversationTitle', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['Hello from the stream.']);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Tell me about my queue please',
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();

    // Draining the stream triggers the then() callback that seeds + dispatches.
    $response->streamedContent();

    $conversationId = DB::table('agent_conversations')
        ->where('user_id', $admin->id)
        ->value('id');

    expect($conversationId)->not->toBeNull();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->value('title'))
        ->toBe('Tell me about my queue please');

    Bus::assertDispatched(fn (GenerateConversationTitle $job): bool => $job->conversationId === $conversationId
        && $job->firstUserMessage === 'Tell me about my queue please');
});

// Streamed runs dispatch a real AgentPrompted event alongside AgentStreamed
// (not via parent-class listener resolution) — RecordAgentUsage matches that.
// This guards the budget guard's dependency on usage rows for streamed turns.
test('streamed turn records ai usage', function (): void {
    MediaAgent::fake(['Streamed reply.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Track my usage',
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();

    // Consume the stream so the SDK dispatches its terminal usage event.
    $response->streamedContent();

    expect(AiUsageRecord::count())->toBe(1);
});

test('admin cannot stream against another users conversation', function (): void {
    MediaAgent::fake(['nope']);
    $owner = User::factory()->admin()->create();
    $admin = User::factory()->admin()->create();

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $owner->id,
        'title' => 'Private conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.stream'), [
            'message' => 'Continue this',
            'conversation_id' => $conversationId,
        ])
        ->assertNotFound();

    MediaAgent::assertNeverPrompted();
});
