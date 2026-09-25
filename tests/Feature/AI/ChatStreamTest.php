<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Http\Streaming\ChatStreamProtocol;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Listeners\Ai\RecordAgentUsage;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamStart;

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
    // text delta, so assert on the first word to prove the faked text
    // actually reached the AG-UI body rather than just the framing.
    expect($body)->toContain('"type":"RUN_STARTED"')
        ->and($body)->toContain('"type":"TEXT_MESSAGE_CONTENT"')
        ->and($body)->toContain('Hello')
        ->and($body)->toContain('"type":"RUN_FINISHED"')
        ->and($body)->not->toContain('[DONE]');
});

test('streaming a first turn reports the minted conversation id as the run thread', function (): void {
    MediaAgent::fake(['Hello from the stream.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'Say hello',
        ], ['Accept' => 'text/event-stream']);

    $response->assertOk();

    $body = $response->streamedContent();

    $conversationId = DB::table('agent_conversations')
        ->where('participant_type', User::class)
        ->where('participant_id', $admin->id)
        ->value('id');

    expect($conversationId)->not->toBeNull()
        ->and(chatStreamFrame($body, 'RUN_FINISHED'))->toContain('"threadId":"'.$conversationId.'"');
});

test('streaming an existing conversation echoes its id as the run thread', function (): void {
    MediaAgent::fake(['Continuing.']);
    $admin = User::factory()->admin()->create();

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => User::class,
        'participant_id' => $admin->id,
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

    $body = $response->streamedContent();

    expect(chatStreamFrame($body, 'RUN_STARTED'))->toContain('"threadId":"'.$conversationId.'"')
        ->and(chatStreamFrame($body, 'RUN_FINISHED'))->toContain('"threadId":"'.$conversationId.'"');
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
        ->where('participant_type', User::class)
        ->where('participant_id', $admin->id)
        ->value('id');

    expect($conversationId)->not->toBeNull();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->value('title'))
        ->toBe('Tell me about my queue please');

    Bus::assertDispatched(fn (GenerateConversationTitle $generateConversationTitle): bool => $generateConversationTitle->conversationId === $conversationId
        && $generateConversationTitle->firstUserMessage === 'Tell me about my queue please');
});

// The REAL streaming gateway dispatches only AgentStreamed (StreamsText.php),
// never AgentPrompted — the fake dispatches both. RecordAgentUsage is therefore
// explicitly registered for AgentStreamed (AIServiceProvider) and deduped by
// invocation_id so the fake's double dispatch still yields exactly one row.
// This guards the budget guard's dependency on usage rows for streamed turns.
test('RecordAgentUsage listens to AgentStreamed (real streams never fire AgentPrompted)', function (): void {
    Event::fake();

    Event::assertListening(
        AgentStreamed::class,
        RecordAgentUsage::class,
    );
});

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

    // Exactly one MediaAgent row for the streamed turn (deduped even though
    // the fake gateway dispatches both AgentStreamed and AgentPrompted).
    // The sync-queued title job records its own TitleAgent row — asserting
    // on the bare table count would let a missing MediaAgent row hide
    // behind it, which is precisely the bug this test exists to catch.
    expect(AiUsageRecord::where('agent_class', MediaAgent::class)->count())->toBe(1);
});

test('admin cannot stream against another users conversation', function (): void {
    MediaAgent::fake(['nope']);
    $owner = User::factory()->admin()->create();
    $admin = User::factory()->admin()->create();

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => User::class,
        'participant_id' => $owner->id,
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

test('streaming endpoint refuses with 429 when the chat model has exhausted its rate limit', function (): void {
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
    MediaAgent::fake(['Should never run.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.stream'), ['message' => 'hi'])
        ->assertStatus(429);

    expect($response->json('error'))->toBe('rate_limited');
    expect($response->getContent())->not->toContain('Should never run.');
});

test('a provider failure mid-stream ends with a friendly RUN_ERROR', function (): void {
    MediaAgent::fake(function (): never {
        throw new ProviderConnectionException('connect timeout');
    });

    $body = $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.chat.stream'), ['message' => 'Hi'], ['Accept' => 'text/event-stream'])
        ->streamedContent();

    expect($body)->toContain('"type":"RUN_ERROR"')
        ->and($body)->toContain('provider_unreachable')
        ->and($body)->not->toContain('An error occurred.');
});

test('a provider stream error without an error event ends with a friendly RUN_ERROR', function (): void {
    MediaAgent::fake([fn () => throw new StreamErrorException]);
    $admin = User::factory()->admin()->create();

    $body = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), ['message' => 'Say hello'], ['Accept' => 'text/event-stream'])
        ->assertOk()
        ->streamedContent();

    expect(chatStreamFrame($body, 'RUN_ERROR'))->toContain('"code":"ai_error"')
        ->and($body)->toContain('The AI provider returned an error. Please try again.')
        ->and($body)->not->toContain('[DONE]');
});

test('a provider stream error that carried its own error event is not reported twice', function (): void {
    $error = new Error('evt-1', 'overloaded', 'Provider overloaded.', true, Date::now()->getTimestamp());
    $streamableAgentResponse = new StreamableAgentResponse('inv-1', function () use ($error): Generator {
        yield new StreamStart('evt-0', 'openai', 'gpt-test', Date::now()->getTimestamp());
        yield $error;

        throw new StreamErrorException($error);
    });

    $body = TestResponse::fromBaseResponse((new ChatStreamProtocol)->response($streamableAgentResponse))->streamedContent();

    expect(substr_count($body, '"type":"RUN_ERROR"'))->toBe(1)
        ->and(chatStreamFrame($body, 'RUN_ERROR'))->toContain('Provider overloaded.')
        ->and($body)->not->toContain('The AI provider returned an error.');
});

test('a failed first turn still gets a conversation title', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    // 1.0 stores a failed turn only once a step completed, so fail on step 2.
    MediaAgent::fake([
        new ToolCall(id: 'call-1', name: 'GetServiceStatusTool', arguments: []),
        fn (): never => throw new RuntimeException('boom'),
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('ai.chat.stream'), ['message' => 'Find me something to watch tonight'], ['Accept' => 'text/event-stream'])
        ->streamedContent();

    $row = DB::table('agent_conversations')->where('participant_id', $admin->id)->sole();

    expect($row->title)->toBe('Find me something to watch tonight');
    Bus::assertDispatched(GenerateConversationTitle::class);
});

test('a first turn that fails before any step completes dispatches no title job', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(function (): never {
        throw new RuntimeException('boom');
    });
    $admin = User::factory()->admin()->create();

    $body = $this->actingAs($admin)
        ->post(route('ai.chat.stream'), ['message' => 'Find me something to watch tonight'], ['Accept' => 'text/event-stream'])
        ->streamedContent();

    expect($body)->toContain('"type":"RUN_ERROR"')
        ->and(DB::table('agent_conversations')->where('participant_id', $admin->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(GenerateConversationTitle::class);
});

/**
 * The first SSE frame of the given AG-UI event type.
 */
function chatStreamFrame(string $body, string $type): string
{
    foreach (explode("\n\n", $body) as $frame) {
        if (str_contains($frame, sprintf('"type":"%s"', $type))) {
            return $frame;
        }
    }

    return '';
}
