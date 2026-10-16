<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * Insert a conversation owned by $user with $count alternating user/assistant
 * rows stored in the 1.0 `steps`/`status` columns, one second apart.
 */
function seedConversationWithMessages(User $user, int $count = 2, ?string $archivedAt = null): string
{
    $conversationId = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'A conversation',
        'archived_at' => $archivedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($index = 0; $index < $count; $index++) {
        $isUser = $index % 2 === 0;
        $content = $isUser ? 'Hello' : 'Hi there';

        insertConversationRow($conversationId, $user, [
            'role' => $isUser ? 'user' : 'assistant',
            'content' => $content,
            'steps' => $isUser ? '[]' : json_encode([conversationStep($content, '')]),
        ]);
    }

    return $conversationId;
}

function insertAssistantRow(string $conversationId, User $user, string $content, string $reasoning, string $status): void
{
    insertConversationRow($conversationId, $user, [
        'role' => 'assistant',
        'content' => $content,
        'steps' => json_encode([conversationStep($content, $reasoning)]),
        'status' => $status,
    ]);
}

/**
 * @return array{content: string, tool_calls: list<mixed>, reasoning: string, replay_blocks: list<mixed>, provider_tool_calls: list<mixed>}
 */
function conversationStep(string $content, string $reasoning): array
{
    return ['content' => $content, 'tool_calls' => [], 'reasoning' => $reasoning, 'replay_blocks' => [], 'provider_tool_calls' => []];
}

/**
 * Rows are spaced one second apart (id and created_at) so the uuid7 ordering
 * the store paginates on matches insertion order.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertConversationRow(string $conversationId, User $user, array $attributes): void
{
    $position = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count();
    $createdAt = now()->addSeconds($position);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7($createdAt),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'agent' => MediaAgent::class,
        'attachments' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => 'completed',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        ...$attributes,
    ]);
}

test('owner can fetch their conversation with messages', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin);

    $response = $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertOk();

    $messages = $response->json('messages');
    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['text'])->toBe('Hello');
    expect($messages[1]['role'])->toBe('assistant');
});

test('foreign conversation returns 404', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($other);

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertNotFound();
});

test('archived conversation returns 404 for owner via user route', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, archivedAt: now()->toDateTimeString());

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertNotFound();
});

test('conversation history is cursor-paginated newest first', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, 35);

    $first = $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))->assertOk();

    expect($first->json('messages'))->toHaveCount(30)
        ->and($first->json('next_cursor'))->not->toBeNull()
        ->and($first->json('messages.29.ts'))->toBeGreaterThan($first->json('messages.0.ts'));

    $second = $this->actingAs($admin)->getJson(route('ai.conversations.show', ['conversation' => $conversationId, 'cursor' => $first->json('next_cursor')]))->assertOk();

    expect($second->json('messages'))->toHaveCount(5)
        ->and($second->json('next_cursor'))->toBeNull()
        ->and($second->json('messages.4.ts'))->toBeLessThan($first->json('messages.0.ts'));
});

test('history exposes reasoning and failed turns', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, 0);
    insertAssistantRow($conversationId, $admin, 'Answer', 'I checked Sonarr first', 'completed');
    insertAssistantRow($conversationId, $admin, '', '', 'failed');

    $messages = $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))->json('messages');

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['reasoning'])->toBe('I checked Sonarr first')
        ->and($messages[0]['failed'])->toBeFalse()
        ->and($messages[1]['failed'])->toBeTrue();
});

test('history links a user turn to its stored attachments', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, 0);
    $chatAttachment = ChatAttachment::factory()->for($admin)->create();

    insertConversationRow($conversationId, $admin, [
        'role' => 'user',
        'content' => '',
        'steps' => '[]',
        'attachments' => json_encode([['type' => 'stored-image', 'name' => null, 'path' => $chatAttachment->path, 'disk' => 'local']]),
    ]);

    $messages = $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))->json('messages');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['attachments'][0]['id'])->toBe($chatAttachment->id)
        ->and($messages[0]['attachments'][0]['url'])->toBe(route('ai.chat.attachments.show', $chatAttachment));
});

test('history links a user turn to attachments sent through the provider Files API', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, 0);
    $image = ChatAttachment::factory()->for($admin)->create(['provider_file_ids' => ['openai' => 'file-image-1']]);
    $document = ChatAttachment::factory()->for($admin)->create([
        'original_name' => 'notes.pdf',
        'mime_type' => 'application/pdf',
        'provider_file_ids' => ['anthropic' => 'file-doc-2'],
    ]);

    insertConversationRow($conversationId, $admin, [
        'role' => 'user',
        'content' => 'see attached',
        'steps' => '[]',
        'attachments' => json_encode([
            ['type' => 'provider-image', 'id' => 'file-image-1', 'name' => null],
            ['type' => 'provider-document', 'id' => 'file-doc-2', 'name' => null],
        ]),
    ]);

    $messages = $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))->json('messages');

    expect(collect($messages[0]['attachments'])->pluck('id')->all())->toEqualCanonicalizing([$image->id, $document->id])
        ->and(collect($messages[0]['attachments'])->pluck('name')->all())->toContain('notes.pdf');
});

test('assistant messages carry choice and approval cards with the live action status', function (): void {
    $admin = User::factory()->admin()->create();
    $actionRequest = ActionRequest::factory()->create(['title' => 'Replace X', 'status' => ActionRequestStatus::Approved]);
    $conversationId = seedConversationWithMessages($admin, 0);
    insertConversationRow($conversationId, $admin, ['role' => 'user', 'content' => 'Hi', 'steps' => '[]']);
    insertConversationRow($conversationId, $admin, [
        'role' => 'assistant',
        'content' => 'Pick one',
        'steps' => json_encode([[
            ...conversationStep('Pick one', ''),
            'tool_results' => [
                ['id' => 'call_1', 'name' => 'OfferChoicesTool', 'arguments' => [], 'result' => json_encode(['choices' => ['question' => 'Which?', 'multiple' => false, 'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => null]]]]), 'result_id' => null],
                ['id' => 'call_2', 'name' => 'DeleteMediaTool', 'arguments' => [], 'result' => json_encode(['queued' => true, 'action_request_id' => $actionRequest->id, 'status' => 'pending', 'requires_approval' => true, 'title' => 'Old']), 'result_id' => null],
            ],
        ]]),
    ]);

    $messages = $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertOk()
        ->json('messages');

    expect($messages[0]['cards'])->toBe([])
        ->and($messages[1]['cards'])->toHaveCount(2)
        ->and($messages[1]['cards'][0]['type'])->toBe('choices')
        ->and($messages[1]['cards'][1])->toMatchArray(['type' => 'action_request', 'id' => $actionRequest->id, 'title' => 'Replace X', 'status' => 'approved']);
});

test('an assistant message with no text still returns its choices card', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin, 0);
    insertConversationRow($conversationId, $admin, ['role' => 'user', 'content' => 'Hi', 'steps' => '[]']);
    insertConversationRow($conversationId, $admin, [
        'role' => 'assistant',
        'content' => '',
        'steps' => json_encode([[
            ...conversationStep('', ''),
            'tool_results' => [
                ['id' => 'call_1', 'name' => 'OfferChoicesTool', 'arguments' => [], 'result' => json_encode(['choices' => ['question' => 'Which?', 'multiple' => false, 'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => null]]]]), 'result_id' => null],
            ],
        ]]),
    ]);

    $messages = $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertOk()
        ->json('messages');

    expect($messages)->toHaveCount(2)
        ->and($messages[1]['text'])->toBe('')
        ->and($messages[1]['cards'])->toHaveCount(1)
        ->and($messages[1]['cards'][0]['type'])->toBe('choices')
        ->and($messages[1]['cards'][0]['id'])->toBe('call_1');
});

test('action cards across messages are hydrated with a single action_requests query', function (): void {
    $admin = User::factory()->admin()->create();
    $first = ActionRequest::factory()->create();
    $second = ActionRequest::factory()->create();
    $conversationId = seedConversationWithMessages($admin, 0);
    foreach ([$first, $second] as $actionRequest) {
        insertConversationRow($conversationId, $admin, ['role' => 'user', 'content' => 'Hi', 'steps' => '[]']);
        insertConversationRow($conversationId, $admin, [
            'role' => 'assistant',
            'content' => 'Queued',
            'steps' => json_encode([[
                ...conversationStep('Queued', ''),
                'tool_results' => [
                    ['id' => 'call_'.$actionRequest->id, 'name' => 'DeleteMediaTool', 'arguments' => [], 'result' => json_encode(['queued' => true, 'action_request_id' => $actionRequest->id, 'status' => 'pending', 'requires_approval' => true, 'title' => 'T']), 'result_id' => null],
                ],
            ]]),
        ]);
    }

    DB::enableQueryLog();
    $messages = $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertOk()
        ->json('messages');
    $actionRequestQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'action_requests'));
    DB::disableQueryLog();

    expect($messages[1]['cards'][0]['id'])->toBe($first->id)
        ->and($messages[3]['cards'][0]['id'])->toBe($second->id)
        ->and($actionRequestQueries)->toHaveCount(1);
});
